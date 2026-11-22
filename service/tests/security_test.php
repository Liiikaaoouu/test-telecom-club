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

T::finish('security');
exit(T::exitCode());
