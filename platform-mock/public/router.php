<?php
declare(strict_types=1);

namespace PlatformMock;

/**
 * Carrier Platform mock — implements §A–§D of the contract plus demo/admin
 * helpers for driving scenarios. Dependency-free PHP with SQLite state.
 *
 * Idempotency semantics mirrored from a real gateway: a SUCCESSFUL debit
 * consumes its externalTrxId forever (a repeat = E1337). A FAILED debit
 * (E1326/E1308) does NOT consume the key, so a logical retry with the same
 * key can still succeed later — exactly what the appendix prescribes.
 */

require __DIR__ . '/../src/autoload.php';

$creds = [
    'applicationId' => getenv('APP_ID') ?: 'APP_000123',
    'password'      => getenv('APP_PASSWORD') ?: 'demo-password',
];
$serviceUrl = rtrim(getenv('SERVICE_URL') ?: 'http://127.0.0.1:8080', '/');
$state = State::open(getenv('MOCK_DB') ?: (getcwd() . '/data/mock.db'));

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$body = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        respond(400, ['statusCode' => 'E0400', 'statusDetail' => 'invalid JSON']);
        exit;
    }
    $body = $decoded;
}

$authOk = static fn(): bool => ($body['applicationId'] ?? null) === $creds['applicationId']
    && ($body['password'] ?? null) === $creds['password'];

/* ------------------------- contract endpoints (§A–§D) ------------------------- */

if ($method === 'POST' && $path === '/subscription/send') {
    if (!$authOk()) respond(401, ['statusCode' => 'E0401', 'statusDetail' => 'Authentication failed']);
    $subscriberId = $body['subscriberId'] ?? null;
    $action = (string)($body['action'] ?? '');
    if (!is_string($subscriberId) || $subscriberId === '' || !in_array($action, ['0', '1'], true)) {
        respond(400, ['statusCode' => 'E0400', 'statusDetail' => 'subscriberId and action("0"|"1") required']);
    }
    $subscriptionStatus = $action === '1' ? 'REGISTERED' : 'UNREGISTERED';
    $state->recordSubscription($subscriberId, $subscriptionStatus, $action);
    Http::log('subscription/send', ['action' => $action, 'subscriberId' => substr(sha1($subscriberId), 0, 8)]);
    respond(200, [
        'version' => '1.0',
        'requestId' => (string)(int)(microtime(true) * 1000),
        'statusCode' => 'S1000',
        'statusDetail' => 'SUCCESS',
        'subscriptionStatus' => $subscriptionStatus,
    ]);
}

if ($method === 'POST' && $path === '/sms/send') {
    if (!$authOk()) respond(401, ['statusCode' => 'E0401', 'statusDetail' => 'Authentication failed']);
    $destinations = array_values(array_map('strval', (array)($body['destinationAddresses'] ?? [])));
    $state->addSms((string)($body['message'] ?? ''), $destinations, isset($body['sourceAddress']) ? (string)$body['sourceAddress'] : null);
    Http::log('sms/send', ['to' => implode(',', $destinations)]);
    respond(200, ['statusCode' => 'S1000', 'statusDetail' => 'SUCCESS']);
}

if ($method === 'POST' && $path === '/caas/direct/debit') {
    if (!$authOk()) respond(401, ['statusCode' => 'E0401', 'statusDetail' => 'Authentication failed']);
    $ext = $body['externalTrxId'] ?? null;
    $subscriberId = $body['subscriberId'] ?? null;
    if (!is_string($ext) || $ext === '' || !is_string($subscriberId) || $subscriberId === '') {
        respond(400, ['statusCode' => 'E0400', 'statusDetail' => 'externalTrxId and subscriberId required']);
    }

    // dedup on the idempotency key: a key that already SUCCEEDED is consumed forever
    if ($state->debitSuccess($ext) !== null) {
        $state->logDebitAttempt($ext, $subscriberId, isset($body['amount']) ? (string)$body['amount'] : null, 'E1337');
        Http::log('debit DUPLICATE', ['externalTrxId' => $ext]);
        respond(200, ['statusCode' => 'E1337', 'statusDetail' => 'Duplicate request', 'externalTrxId' => $ext]);
    }

    if (($ms = $state->latencyMs()) > 0) {
        usleep($ms * 1000); // configurable latency — lets demos exercise the opt-out race
    }

    $amount = isset($body['amount']) ? (string)$body['amount'] : null;
    $forced = $state->effectiveMode($subscriberId);
    if ($forced === 'S1000') {
        $result = [
            'statusCode' => 'S1000',
            'statusDetail' => 'Request was successfully processed',
            'externalTrxId' => $ext,
            'internalTrxId' => 'I' . (int)(microtime(true) * 1000) . random_int(100, 999),
            'referenceId' => (string)(10000000 + $state->successfulDebitCount()),
            'timeStamp' => date('c'),
        ];
        $state->saveDebitSuccess($ext, $result);
    } else {
        $result = ['statusCode' => $forced, 'statusDetail' => "Simulated $forced", 'externalTrxId' => $ext];
    }
    $state->logDebitAttempt($ext, $subscriberId, $amount, (string)$result['statusCode']);
    Http::log('debit', ['externalTrxId' => $ext, 'statusCode' => $result['statusCode']]);
    respond(200, $result);
}

/* ------------- admin (demo driving; NOT part of the operator contract) ------------- */

if ($method === 'GET' && preg_match('#^/admin/debits/([^/]+)$#', $path, $m)) {
    $found = $state->debitSuccess(rawurldecode($m[1]));
    respond($found ? 200 : 404, $found ?? ['statusCode' => 'E0404', 'statusDetail' => 'no successful debit for this key']);
}

if ($method === 'GET' && $path === '/admin/debits') {
    respond(200, ['successfulCount' => $state->successfulDebitCount(), 'log' => $state->debitLog()]);
}

if ($method === 'GET' && $path === '/admin/sms') {
    $messages = array_map(static fn(array $r): array => [
        'at' => $r['at'],
        'message' => $r['message'],
        'destinationAddresses' => json_decode((string)$r['destinations'], true),
        'sourceAddress' => $r['source'],
    ], $state->smsList());
    respond(200, ['count' => count($messages), 'messages' => $messages]);
}

if ($method === 'GET' && $path === '/admin/subscriptions') {
    respond(200, $state->subscriptionSnapshot());
}

if ($method === 'POST' && $path === '/admin/mode') {
    $status = (string)($body['status'] ?? '');
    $allowed = ['S1000', 'E1326', 'E1308', 'E1337', 'E1318'];
    if (!in_array($status, $allowed, true)) {
        respond(400, ['ok' => false, 'error' => 'status must be one of ' . implode(', ', $allowed)]);
    }
    $subscriberId = isset($body['subscriberId']) && is_string($body['subscriberId']) ? $body['subscriberId'] : null;
    $state->setMode($subscriberId, $status);
    respond(200, ['ok' => true, 'scope' => $subscriberId ?? 'global', 'status' => $status]);
}

if ($method === 'POST' && $path === '/admin/latency') {
    $ms = max(0, (int)($body['ms'] ?? 0));
    $state->setLatencyMs($ms);
    respond(200, ['ok' => true, 'latencyMs' => $ms]);
}

if ($method === 'POST' && $path === '/admin/notify') {
    // §B — push a subscription notification into the service.
    $payload = [
        'applicationId' => $creds['applicationId'],
        'subscriberId' => $body['subscriberId'] ?? null,
        'frequency' => $body['frequency'] ?? 'DAILY',
        'status' => $body['status'] ?? 'REGISTERED',
        'version' => '1.0',
        'timeStamp' => $body['timeStamp'] ?? date('c'),
    ];
    if (!is_string($payload['subscriberId']) || $payload['subscriberId'] === '') {
        respond(400, ['ok' => false, 'error' => 'subscriberId required']);
    }
    $res = Http::postJson("$serviceUrl/webhooks/subscription-notification", $payload);
    Http::log('admin/notify delivered', ['status' => $payload['status'], 'reply' => $res['data']['statusCode'] ?? null]);
    // payload is returned so a caller can re-deliver the EXACT same event (duplicate test)
    respond(200, ['ok' => true, 'delivered' => $res['status'] === 200, 'webhookReply' => $res['data'], 'payload' => $payload]);
}

if ($method === 'POST' && $path === '/admin/mo') {
    // §C MO — push an inbound SMS (e.g. STOP) into the service.
    $payload = [
        'applicationId' => $creds['applicationId'],
        'sourceAddress' => $body['sourceAddress'] ?? null,
        'message' => $body['message'] ?? 'STOP',
        'encoding' => '0',
        'version' => '1.0',
    ];
    if (!is_string($payload['sourceAddress']) || $payload['sourceAddress'] === '') {
        respond(400, ['ok' => false, 'error' => 'sourceAddress required']);
    }
    $res = Http::postJson("$serviceUrl/webhooks/mo-sms", $payload);
    Http::log('admin/mo delivered', ['reply' => $res['data']['statusCode'] ?? null]);
    respond(200, ['ok' => true, 'delivered' => $res['status'] === 200, 'webhookReply' => $res['data'], 'payload' => $payload]);
}

if ($method === 'GET' && $path === '/health') {
    respond(200, ['ok' => true, 'service' => 'platform-mock']);
}

respond(404, ['statusCode' => 'E0404', 'statusDetail' => 'not found']);

/* ------------------------- plumbing ------------------------- */

function respond(int $code, array $obj): void
{
    http_response_code($code);
    header('content-type: application/json');
    echo json_encode($obj, JSON_UNESCAPED_SLASHES);
    exit;
}
