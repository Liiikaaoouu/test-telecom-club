<?php
declare(strict_types=1);

namespace DailyPass;

/**
 * HTTP surface of the service: F1 opt-in, F2/F4 platform webhooks,
 * F3 job trigger, F5 status, audit queries.
 */
final class Handlers
{
    private const WELCOME_SMS_TPL = 'Welcome to Daily Pass (%s %s/day). Reply STOP to cancel.';

    public function __construct(
        private readonly array $cfg,
        private readonly Store $store,
        private readonly PlatformClient $platform,
        private readonly ChargeJob $job
    ) {
    }

    public function handle(string $method, string $path): void
    {
        try {
            if (!$this->apiAuthorized($path)) {
                return;
            }
            match (true) {
                $method === 'GET'  && $path === '/health' => $this->health(),
                $method === 'POST' && $path === '/opt-in' => $this->optIn(),
                $method === 'POST' && $path === '/webhooks/subscription-notification' => $this->subscriptionNotification(),
                $method === 'POST' && $path === '/webhooks/mo-sms' => $this->moSms(),
                $method === 'POST' && $path === '/jobs/daily-charge' => $this->dailyCharge(),
                $method === 'GET'  && $path === '/status' => $this->status(),
                $method === 'GET'  && $path === '/admin/charges' => $this->adminCharges(),
                default => $this->respond(404, ['ok' => false, 'error' => 'not found']),
            };
        } catch (\Throwable $e) {
            Logger::error('request failed', ['path' => $path, 'error' => (string)$e]);
            $this->respond(500, ['ok' => false, 'error' => 'internal error']);
        }
    }

    /* ---------------- F1 — opt-in ---------------- */

    private function optIn(): void
    {
        $body = $this->readJsonBody();
        $v = Validation::subscriberId($body['msisdn'] ?? $body['subscriberId'] ?? null, (bool)$this->cfg['msisdnStrict']);
        if (!$v['ok']) {
            $this->respond(400, ['ok' => false, 'error' => $v['error']]);
            return;
        }
        $ref = (string)$v['value'];

        // Atomic claim: whatever races with this request, at most ONE caller
        // can drive a (re-)subscription for this subscriber — no double
        // /subscription/send, no double welcome SMS.
        $claim = $this->store->claimOptIn($ref);
        if (!$claim['owner'] && $claim['prev'] === 'OPTING_IN') {
            $staleBefore = Iso::plusSeconds(new \DateTimeImmutable('now', new \DateTimeZone('UTC')), -60);
            $claim = ['owner' => $this->store->takeoverStaleOptInClaim($ref, $staleBefore), 'prev' => 'INACTIVE'];
        }
        if (!$claim['owner']) {
            if ($claim['prev'] === 'ACTIVE') {
                // a subscriber who is already active must not be double-subscribed
                $this->respond(200, ['ok' => true, 'alreadyActive' => true, 'status' => 'ACTIVE']);
                return;
            }
            $this->respond(409, ['ok' => false, 'error' => 'opt-in already in progress']);
            return;
        }

        try {
            $res = $this->platform->subscriptionSend($ref, '1');
        } catch (\Throwable $e) {
            $this->store->restoreOptInClaim($ref, $claim['prev']);
            Logger::error('opt-in platform call failed', ['sub' => Logger::mask($ref), 'error' => (string)$e]);
            $this->respond(502, ['ok' => false, 'error' => 'platform unreachable']);
            return;
        }
        if (($res['statusCode'] ?? null) === 'S1000') {
            $this->store->setSubscriberStatus($ref, 'ACTIVE');
            $this->job->sendSms($ref, sprintf(self::WELCOME_SMS_TPL, $this->cfg['amount'], $this->cfg['currency']));
            Logger::info('subscriber opted in', ['sub' => Logger::mask($ref)]);
            $this->respond(200, ['ok' => true, 'status' => 'ACTIVE']);
            return;
        }
        $this->store->restoreOptInClaim($ref, $claim['prev']);
        Logger::warn('opt-in rejected by platform', ['sub' => Logger::mask($ref), 'statusCode' => $res['statusCode'] ?? null]);
        $this->respond(502, ['ok' => false, 'statusCode' => $res['statusCode'] ?? null, 'error' => 'platform rejected subscription']);
    }

    /* ---------------- F2 — subscription notification webhook (§B) ---------------- */

    private function subscriptionNotification(): void
    {
        if (!$this->webhookAuthorized()) {
            return;
        }
        $body = $this->readJsonBody();
        $ref = is_string($body['subscriberId'] ?? null) && strlen((string)$body['subscriberId']) <= 64 ? (string)$body['subscriberId'] : null;
        if ($ref === null) {
            $this->respond(400, ['statusCode' => 'E0400', 'statusDetail' => 'subscriberId missing'], ['content-type' => 'application/json']);
            return;
        }
        $status = (string)($body['status'] ?? '');

        // Idempotency: the same event delivered twice must not corrupt state
        // or cause an extra charge/SMS — dedup on (subscriber|status|timeStamp|frequency).
        $dedupKey = sha1(implode('|', [$ref, $status, (string)($body['timeStamp'] ?? ''), (string)($body['frequency'] ?? '')]));
        if (!$this->store->recordEvent($dedupKey, 'subscription-notification', $ref, $body)) {
            Logger::info('duplicate subscription notification ignored', ['sub' => Logger::mask($ref), 'status' => $status]);
            $this->respondPlatformS1000();
            return;
        }

        $sub = $this->store->getSubscriber($ref);
        switch ($status) {
            case 'REGISTERED':
                // Explicit user intent wins over out-of-band events: a late or
                // replayed REGISTERED must NOT re-activate an opted-out
                // (INACTIVE) or suspended subscriber — that would silently
                // resume charging someone who stopped.
                if ($sub === null) {
                    $this->store->upsertSubscriber($ref, 'ACTIVE');
                    $this->job->sendSms($ref, sprintf(self::WELCOME_SMS_TPL, $this->cfg['amount'], $this->cfg['currency']));
                } elseif (in_array($sub['status'], ['PENDING', 'OPTING_IN'], true)) {
                    $this->store->setSubscriberStatus($ref, 'ACTIVE');
                } else {
                    Logger::info('REGISTERED ignored for existing subscriber', ['sub' => Logger::mask($ref), 'state' => (string)$sub['status']]);
                }
                break;
            case 'UNREGISTERED':
                if ($sub !== null && $sub['status'] !== 'INACTIVE') {
                    $this->store->setSubscriberStatus($ref, 'INACTIVE');
                }
                break;
            case 'PENDING':
                if ($sub === null) {
                    $this->store->upsertSubscriber($ref, 'PENDING');
                }
                break;
            case 'CHARGE':
                Logger::info('charge notification (no state change)', ['sub' => Logger::mask($ref)]);
                break;
            default:
                Logger::warn('unknown subscription status in notification', ['status' => $status]);
        }
        $this->respondPlatformS1000();
    }

    /* ---------------- F4 — inbound MO SMS (§C), "STOP" ---------------- */

    private function moSms(): void
    {
        if (!$this->webhookAuthorized()) {
            return;
        }
        $body = $this->readJsonBody();
        $ref = isset($body['sourceAddress']) && is_string($body['sourceAddress']) ? $body['sourceAddress'] : null;
        $message = strtoupper(trim((string)($body['message'] ?? '')));

        if ($message === 'STOP' && $ref !== null) {
            $sub = $this->store->getSubscriber($ref);
            // Optimistic state change FIRST: only the call that flips the row
            // proceeds, so duplicate STOPs are no-ops and an opted-out
            // subscriber can never be picked up by the charging job again.
            $flipped = $sub !== null && $sub['status'] !== 'INACTIVE'
                && $this->store->setSubscriberStatusIfNot($ref, 'INACTIVE', 'INACTIVE');
            if ($flipped) {
                try {
                    $res = $this->platform->subscriptionSend($ref, '0');
                    if (($res['statusCode'] ?? null) !== 'S1000') {
                        // money safety first: we keep the subscriber INACTIVE locally even if
                        // the platform call fails, so no further charges are possible.
                        Logger::warn('platform did not confirm opt-out (subscriber still marked INACTIVE)',
                            ['sub' => Logger::mask($ref), 'statusCode' => $res['statusCode'] ?? null]);
                    }
                } catch (\Throwable $e) {
                    Logger::error('opt-out platform call failed (subscriber still marked INACTIVE)', ['sub' => Logger::mask($ref), 'error' => (string)$e]);
                }
                $this->job->sendSms($ref, 'Daily Pass cancelled. You will not be charged again.');
                Logger::info('subscriber opted out via STOP', ['sub' => Logger::mask($ref)]);
            } elseif ($sub !== null) {
                Logger::info('duplicate STOP ignored', ['sub' => Logger::mask($ref)]);
            } else {
                Logger::warn('STOP from unknown subscriber', ['sub' => Logger::mask($ref)]);
            }
        }
        $this->respondPlatformS1000();
    }

    /* ---------------- F3 — daily charge trigger ---------------- */

    private function dailyCharge(): void
    {
        $body = $this->readJsonBody();
        $day = $body['day'] ?? null;
        if ($day !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$day)) {
            $this->respond(400, ['ok' => false, 'error' => 'day must be YYYY-MM-DD']);
            return;
        }
        $summary = $this->job->runDaily($day !== null ? (string)$day : null);
        $this->respond(200, ['ok' => true] + $summary);
    }

    /* ---------------- F5 — status + audit ---------------- */

    private function status(): void
    {
        $ref = $_GET['subscriber'] ?? null;
        if (!is_string($ref) || $ref === '') {
            $this->respond(400, ['ok' => false, 'error' => 'subscriber param required']);
            return;
        }
        $sub = $this->store->getSubscriber($ref);
        if ($sub === null) {
            $this->respond(404, ['ok' => false, 'error' => 'unknown subscriber']);
            return;
        }
        $today = $this->store->getCharge($ref, Config::billingDay((string)$this->cfg['billingTz']));
        $this->respond(200, [
            'status' => $sub['status'],
            'updated_at' => $sub['updated_at'],
            'externalTrxIdToday' => $today['external_trx_id'] ?? null,
            'chargeToday' => $today === null ? null : [
                'status' => $today['status'],
                'referenceId' => $today['reference_id'],
                'attempts' => (int)$today['attempt_count'],
                'lastStatusCode' => $today['last_status_code'],
                'nextRetryAt' => $today['next_retry_at'],
            ],
        ]);
    }

    private function adminCharges(): void
    {
        // Finance answer to: "was MSISDN X charged today, and what's the reference?"
        $rows = $this->store->listCharges($_GET['subscriber'] ?? null, $_GET['day'] ?? null);
        $this->respond(200, [
            'count' => count($rows),
            'charges' => array_map(static fn(array $r): array => [
                'subscriber_ref' => $r['subscriber_ref'],
                'external_trx_id_preview' => substr((string)$r['external_trx_id'], 0, 15) . '…',
                'billing_day' => $r['billing_day'],
                'status' => $r['status'],
                'attempt_count' => (int)$r['attempt_count'],
                'last_status_code' => $r['last_status_code'],
                'reference_id' => $r['reference_id'],
                'amount' => $r['amount'],
                'currency' => $r['currency'],
                'reconciled' => ((int)$r['reconciled']) === 1,
                'created_at' => $r['created_at'],
                'updated_at' => $r['updated_at'],
            ], $rows),
        ]);
    }

    private function health(): void
    {
        $this->respond(200, ['ok' => true, 'service' => 'daily-pass', 'time' => Iso::now()]);
    }

    /* ---------------- plumbing ---------------- */

    /**
     * Optional service token on operator/finance handles.
     *
     * The assignment puts authentication out of scope, but demo or production
     * deployments still need to keep /jobs/* and /admin/* off the open internet.
     * SERVICE_API_TOKEN is optional: when it is empty (the demo default) nothing
     * changes, so `docker compose up` and the documented curls keep working.
     */
    private function apiAuthorized(string $path): bool
    {
        $token = (string)($this->cfg['apiToken'] ?? '');
        if ($token === '') {
            return true;
        }
        $protected = str_starts_with($path, '/jobs/') || str_starts_with($path, '/admin/');
        if (!$protected) {
            return true;
        }
        $given = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if ($given === '' && function_exists('getallheaders')) {
            // some SAPIs (and php -S on certain builds) expose it only here
            $all = getallheaders();
            $given = (string)($all['Authorization'] ?? $all['authorization'] ?? '');
        }
        if (!hash_equals('Bearer ' . $token, $given)) {
            $this->respond(401, ['ok' => false, 'error' => 'unauthorized']);
            return false;
        }
        return true;
    }

    /**
     * Optional shared secret on inbound webhooks (§B/§C).
     *
     * The contract defines no signature, so this is our own defence layer: both
     * sides read WEBHOOK_SECRET, and the platform sends it as X-Webhook-Secret.
     * Empty secret = disabled, which keeps the single-command demo working.
     */
    private function webhookAuthorized(): bool
    {
        $secret = (string)($this->cfg['webhookSecret'] ?? '');
        if ($secret === '') {
            return true;
        }
        $given = (string)($_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '');
        if (!hash_equals($secret, $given)) {
            $this->respond(401, ['statusCode' => 'E0401', 'statusDetail' => 'Unauthorized']);
            return false;
        }
        return true;
    }

    private function respondPlatformS1000(): void
    {
        $this->respond(200, ['statusCode' => 'S1000', 'statusDetail' => 'Success']);
    }

    /** @return array<string, mixed> */
    private function readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }
        if (strlen($raw) > 1_000_000) {
            throw new \RuntimeException('body too large');
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('invalid JSON body');
        }
        return $data;
    }

    /** @param array<string, mixed> $obj */
    private function respond(int $code, array $obj, array $headers = ['content-type' => 'application/json']): void
    {
        http_response_code($code);
        foreach ($headers as $name => $value) {
            header("$name: $value");
        }
        echo json_encode($obj, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
