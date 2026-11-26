<?php
declare(strict_types=1);

require_once __DIR__ . '/framework.php';
require_once __DIR__ . '/harness.php';

/* Security & config hygiene: validation, secrets, masked ids, fail-fast config. */

require_once dirname(__DIR__) . '/src/autoload.php';

use DailyPass\Logger;

T::test('MSISDN and input validation rejects junk at the boundary', function (): void {
    $s = bootStack();

    foreach (['not-a-phone', 'tel:1234', '1000000001', '', 'tel:+1-555', ['x']] as $junk) {
        $r = jreq('POST', "{$s['serviceUrl']}/opt-in", ['msisdn' => $junk]);
        T::eq($r['status'], 400, "opt-in must reject " . var_export($junk, true));
    }
    $ok = jreq('POST', "{$s['serviceUrl']}/opt-in", ['msisdn' => 'tel:1000000001']);
    T::eq($ok['status'], 200);

    $r = jreq('POST', "{$s['serviceUrl']}/webhooks/subscription-notification", ['status' => 'REGISTERED']);
    T::eq($r['status'], 400, 'webhook requires subscriberId');

    $r = jreq('POST', "{$s['serviceUrl']}/jobs/daily-charge", ['day' => 'tomorrow']);
    T::eq($r['status'], 400, 'day must be YYYY-MM-DD');

    // Boundary hygiene: malformed input is a 400 — never a 500, and never a PHP
    // warning printed into the response (display_errors is off in the image).
    T::eq(jraw("{$s['serviceUrl']}/opt-in", '{"msisdn":"tel:1000000009"')['status'], 400, 'malformed JSON -> 400');
    T::eq(jraw("{$s['serviceUrl']}/opt-in", '"string"')['status'], 400, 'non-object JSON -> 400');
    T::eq(jraw("{$s['serviceUrl']}/opt-in", 'null')['status'], 400, 'null JSON -> 400');
    T::eq(jreq('POST', "{$s['serviceUrl']}/webhooks/subscription-notification", [
        'subscriberId' => 'tel:1000000009', 'status' => 'REGISTERED', 'timeStamp' => ['x'],
    ])['status'], 400, 'array timeStamp -> 400');
    T::eq(jget("{$s['serviceUrl']}/admin/charges?subscriber[]=x")['status'], 400, 'array query param -> 400');

    // A billing day must be a real calendar date.
    T::eq(jreq('POST', "{$s['serviceUrl']}/jobs/daily-charge", ['day' => '2026-02-31'])['status'], 400, '2026-02-31 -> 400');
    T::eq(jreq('POST', "{$s['serviceUrl']}/jobs/daily-charge", ['day' => '2026-13-01'])['status'], 400, '2026-13-01 -> 400');
    T::eq(jreq('POST', "{$s['serviceUrl']}/jobs/daily-charge", ['day' => '2026-11-26'])['status'], 200, 'a real date still works');

    // §D is the money contract: the mock must reject a debit without a 2-dp amount.
    $auth = ['applicationId' => 'APP_TEST', 'password' => 'demo-password'];
    $noAmount = jreq('POST', "{$s['mockUrl']}/caas/direct/debit", $auth + [
        'externalTrxId' => 'BOUNDARY-1', 'subscriberId' => 'tel:1000000009', 'currency' => 'XTS',
    ]);
    T::eq($noAmount['status'], 400, 'mock rejects a debit without amount');
    $badAmount = jreq('POST', "{$s['mockUrl']}/caas/direct/debit", $auth + [
        'externalTrxId' => 'BOUNDARY-2', 'subscriberId' => 'tel:1000000009', 'amount' => '5', 'currency' => 'XTS',
    ]);
    T::eq($badAmount['status'], 400, 'mock rejects an amount with one decimal');

    stopStack($s);
});

T::test('masked stable aliases work end-to-end (never parsed as phone numbers)', function (): void {
    $s = bootStack(['MSISDN_STRICT' => 'false']);
    $alias = 'MASK-9f8e-0042';

    $opt = jpost("{$s['serviceUrl']}/opt-in", ['msisdn' => $alias]);
    T::ok(($opt['ok'] ?? false) === true, 'masked alias accepted in non-strict mode');

    $j = jpost("{$s['serviceUrl']}/jobs/daily-charge");
    T::eq($j['charged'] ?? null, 1);

    $debits = jget("{$s['mockUrl']}/admin/debits")['data'];
    T::eq($debits['log'][0]['subscriber_id'] ?? null, $alias, 'alias passed through to the platform verbatim');

    $sms = jget("{$s['mockUrl']}/admin/sms")['data'];
    $dest = $sms['messages'][1]['destinationAddresses'][0] ?? null; // receipt
    T::eq($dest, $alias, 'SMS addressed to the alias without reconstructing a number');

    $st = jget("{$s['serviceUrl']}/status?subscriber=" . rawurlencode($alias))['data'];
    T::eq($st['status'] ?? null, 'ACTIVE');
    T::eq($st['externalTrxIdToday'] ?? null, expectedExtId($alias, today()), 'deterministic key over the opaque id');

    // STOP over the masked alias, delivered as an MO by the platform
    jpost("{$s['mockUrl']}/admin/mo", ['sourceAddress' => $alias, 'message' => 'STOP']);
    $st2 = jget("{$s['serviceUrl']}/status?subscriber=" . rawurlencode($alias))['data'];
    T::eq($st2['status'] ?? null, 'INACTIVE');

    stopStack($s);
});

T::test('credentials never appear in logs, and password is sanitized', function (): void {
    $s = bootStack();
    $tel = 'tel:4000000001';

    jpost("{$s['serviceUrl']}/opt-in", ['msisdn' => $tel]);
    jpost("{$s['serviceUrl']}/jobs/daily-charge");
    jpost("{$s['mockUrl']}/admin/mo", ['sourceAddress' => $tel, 'message' => 'STOP']);

    $svcLog = (string)file_get_contents($s['svcLog']);
    $mockLog = (string)file_get_contents($s['mockLog']);
    T::notContains($svcLog, 'demo-password', 'service log clean');
    T::notContains($mockLog, 'demo-password', 'mock log clean');
    T::notContains($svcLog, $tel, 'service log carries only masked subscriber labels');

    // unit: the sanitizer strips nested secrets
    $clean = Logger::sanitize(['password' => 'p1', 'body' => ['password' => 'p2', 'ok' => 1]]);
    T::eq($clean['password'], '***');
    T::eq($clean['body']['password'], '***');

    stopStack($s);
});

T::test('a malformed DAILY_AMOUNT fails fast at boot, never reaching a debit', function (): void {
    $root = projectRoot();
    $code = 'require ' . var_export($root . '/service/src/autoload.php', true) . ';'
        . 'try { \DailyPass\Config::fromEnv(); echo "OK"; }'
        . 'catch (\Throwable $e) { echo "THREW:" . $e->getMessage(); }';
    $out = [];
    exec('APP_ID=A APP_PASSWORD=p DAILY_AMOUNT=5.0 php -r ' . escapeshellarg($code) . ' 2>&1', $out);
    $joined = implode("\n", $out);
    T::contains($joined, 'THREW', 'config rejects amount without 2 decimals');

    $out2 = [];
    exec('APP_ID=A APP_PASSWORD=p DAILY_AMOUNT=5.00 php -r ' . escapeshellarg($code) . ' 2>&1', $out2);
    T::eq(implode("\n", $out2), 'OK', 'valid amount boots');

    // secrets must come from the environment — no hard-coded defaults
    $out3 = [];
    exec('env -u APP_ID -u APP_PASSWORD DAILY_AMOUNT=5.00 php -r ' . escapeshellarg($code) . ' 2>&1', $out3);
    T::contains(implode("\n", $out3), 'THREW', 'boot without APP_ID/APP_PASSWORD fails fast (no default credentials)');
});

T::test('optional webhook secret: open when unset, mandatory when set', function (): void {
    // 1) demo default: no secret configured -> the documented flow still works
    $open = bootStack();
    $r = jreq('POST', "{$open['serviceUrl']}/webhooks/subscription-notification", [
        'subscriberId' => 'tel:5550000001', 'status' => 'REGISTERED',
        'timeStamp' => '2026-01-01T00:00:00+00:00', 'frequency' => 'DAILY',
    ]);
    T::eq($r['status'], 200, 'no WEBHOOK_SECRET -> webhook stays open');
    T::eq($r['data']['statusCode'] ?? null, 'S1000');
    stopStack($open);

    // 2) secret configured on both sides
    $s = bootStack(
        ['CARRIER_WEBHOOK_SECRET' => 'hook-secret'],
        ['WEBHOOK_SECRET' => 'hook-secret']
    );
    $payload = [
        'subscriberId' => 'tel:5550000002', 'status' => 'REGISTERED',
        'timeStamp' => '2026-01-01T00:00:01+00:00', 'frequency' => 'DAILY',
    ];
    $url = "{$s['serviceUrl']}/webhooks/subscription-notification";

    T::eq(jreq('POST', $url, $payload)['status'], 401, 'no header -> 401');
    T::eq(jreq('POST', $url, $payload, 20000, ['X-Webhook-Secret: wrong'])['status'], 401, 'wrong secret -> 401');

    $st = jget("{$s['serviceUrl']}/status?subscriber=tel:5550000002")['status'];
    T::eq($st, 404, 'rejected webhook must not create a subscriber');

    $ok = jreq('POST', $url, $payload, 20000, ['X-Webhook-Secret: hook-secret']);
    T::eq($ok['status'], 200, 'correct secret -> accepted');
    T::eq($ok['data']['statusCode'] ?? null, 'S1000');
    T::eq(jget("{$s['serviceUrl']}/status?subscriber=tel:5550000002")['data']['status'] ?? null, 'ACTIVE');

    // the mock sends the header itself, so the productised flow is intact
    $notify = jpost("{$s['mockUrl']}/admin/notify", ['subscriberId' => 'tel:5550000003', 'status' => 'REGISTERED']);
    T::eq($notify['webhookReply']['statusCode'] ?? null, 'S1000', 'mock signs its webhook calls');

    stopStack($s);
});

T::test('optional service token: protects /jobs and /admin, leaves product entry open', function (): void {
    $s = bootStack(['SERVICE_API_TOKEN' => 'svc-token']);
    $auth = ['Authorization: Bearer svc-token'];

    T::eq(jget("{$s['serviceUrl']}/admin/charges")['status'], 401, 'admin without token -> 401');
    T::eq(jreq('GET', "{$s['serviceUrl']}/admin/charges", null, 20000, ['Authorization: Bearer nope'])['status'], 401, 'wrong token -> 401');
    T::eq(jreq('POST', "{$s['serviceUrl']}/jobs/daily-charge", [])['status'], 401, 'job without token -> 401');

    T::eq(jreq('GET', "{$s['serviceUrl']}/admin/charges", null, 20000, $auth)['status'], 200, 'admin with token -> 200');
    T::eq(jreq('POST', "{$s['serviceUrl']}/jobs/daily-charge", [], 20000, $auth)['status'], 200, 'job with token -> 200');

    // the subscriber-facing surface must stay reachable
    T::eq(jget("{$s['serviceUrl']}/health")['status'], 200, 'health is always open');
    T::eq(jreq('POST', "{$s['serviceUrl']}/opt-in", ['msisdn' => 'tel:5550000101'])['status'], 200, 'opt-in is open');

    stopStack($s);
});

T::finish('security');
exit(T::exitCode());
