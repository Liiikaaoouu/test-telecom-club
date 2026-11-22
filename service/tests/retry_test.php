<?php
declare(strict_types=1);

require_once __DIR__ . '/framework.php';
require_once __DIR__ . '/harness.php';

/* Error & retry handling: E1326 vs E1308 classified DIFFERENTLY; bounded backoff. */

T::test('E1326 (transient): grace retry with the SAME key can succeed later', function (): void {
    $s = bootStack();
    $tel = 'tel:2000000001';

    jpost("{$s['serviceUrl']}/opt-in", ['msisdn' => $tel]);
    jpost("{$s['mockUrl']}/admin/mode", ['status' => 'E1326', 'subscriberId' => $tel]);

    $j1 = jpost("{$s['serviceUrl']}/jobs/daily-charge");
    T::eq($j1['charged'] ?? null, 0);
    T::eq($j1['graced'] ?? null, 1, 'insufficient balance → grace');

    $st = jget("{$s['serviceUrl']}/status?subscriber=" . rawurlencode($tel))['data'];
    T::eq($st['chargeToday']['status'] ?? null, 'GRACE');
    T::eq($st['chargeToday']['attempts'] ?? null, 1);
    T::eq($st['chargeToday']['lastStatusCode'] ?? null, 'E1326');
    T::ok(($st['chargeToday']['nextRetryAt'] ?? null) !== null, 'retry scheduled');
    $ext1 = $st['externalTrxIdToday'];

    // user tops up; wait out the first backoff interval (1s in tests)
    jpost("{$s['mockUrl']}/admin/mode", ['status' => 'S1000', 'subscriberId' => $tel]);
    usleep(1_200_000);
    $j2 = jpost("{$s['serviceUrl']}/jobs/daily-charge");
    T::eq($j2['charged'] ?? null, 1, 'grace retry charges successfully');

    $st2 = jget("{$s['serviceUrl']}/status?subscriber=" . rawurlencode($tel))['data'];
    T::eq($st2['externalTrxIdToday'], $ext1, 'retry reused the same idempotency key');
    T::eq($st2['chargeToday']['status'], 'SUCCESS');

    $debits = jget("{$s['mockUrl']}/admin/debits")['data'];
    T::eq($debits['successfulCount'], 1);
    T::eq(count($debits['log']), 2, 'failed attempt + successful retry');
    T::eq($debits['log'][0]['external_trx_id'], $debits['log'][1]['external_trx_id'], 'same key on both attempts');

    stopStack($s);
});

T::test('E1326 forever: retries are BOUNDED then exhausted for the day', function (): void {
    $s = bootStack();
    $tel = 'tel:2000000002';

    jpost("{$s['serviceUrl']}/opt-in", ['msisdn' => $tel]);
    jpost("{$s['mockUrl']}/admin/mode", ['status' => 'E1326']); // global

    // intervals are 1,2,3 seconds in tests → 1 initial + 3 retries = 4 attempts
    $steps = [0, 1.2, 2.2, 3.2];
    $last = null;
    foreach ($steps as $i => $wait) {
        if ($i > 0) {
            usleep((int)($steps[$i] * 1_000_000));
        }
        $last = jpost("{$s['serviceUrl']}/jobs/daily-charge");
    }
    T::eq($last['charged'] ?? null, 0);
    T::eq($last['exhausted'] ?? null, 1, 'fourth attempt exceeds the budget → exhausted');

    $st = jget("{$s['serviceUrl']}/status?subscriber=" . rawurlencode($tel))['data'];
    T::eq($st['chargeToday']['status'] ?? null, 'GRACE_EXHAUSTED');
    T::eq($st['chargeToday']['attempts'] ?? null, 4, 'bounded: exactly 4 debit attempts');
    T::eq($st['status'] ?? null, 'ACTIVE', 'balance trouble does not cancel the subscription');

    $again = jpost("{$s['serviceUrl']}/jobs/daily-charge");
    T::eq($again['skipped'] ?? null, 1, 'exhausted day is terminal');
    T::eq(jget("{$s['mockUrl']}/admin/debits")['data']['successfulCount'] ?? null, 0);

    stopStack($s);
});

T::test('E1308 (permanent): suspend — handled DIFFERENTLY from E1326', function (): void {
    $s = bootStack();
    $tel = 'tel:2000000003';

    jpost("{$s['serviceUrl']}/opt-in", ['msisdn' => $tel]);
    jpost("{$s['mockUrl']}/admin/mode", ['status' => 'E1308', 'subscriberId' => $tel]);

    $j1 = jpost("{$s['serviceUrl']}/jobs/daily-charge");
    T::eq($j1['suspended'] ?? null, 1);

    $st = jget("{$s['serviceUrl']}/status?subscriber=" . rawurlencode($tel))['data'];
    T::eq($st['status'] ?? null, 'SUSPENDED', 'permanent error suspends the subscriber');
    T::eq($st['chargeToday']['status'] ?? null, 'FAILED_PERMANENT');

    // even after the platform "recovers", a suspended subscriber is never re-offered
    jpost("{$s['mockUrl']}/admin/mode", ['status' => 'S1000', 'subscriberId' => $tel]);
    $j2 = jpost("{$s['serviceUrl']}/jobs/daily-charge");
    T::eq($j2['charged'] ?? null, 0);

    $debits = jget("{$s['mockUrl']}/admin/debits")['data'];
    T::eq(count($debits['log']), 1, 'no pointless retries after a non-retry-able code');
    T::eq($debits['successfulCount'], 0);

    stopStack($s);
});

T::test('E1318 (throttle): short backoff, same key, then success', function (): void {
    $s = bootStack();
    $tel = 'tel:2000000004';

    jpost("{$s['serviceUrl']}/opt-in", ['msisdn' => $tel]);
    jpost("{$s['mockUrl']}/admin/mode", ['status' => 'E1318', 'subscriberId' => $tel]);

    $j1 = jpost("{$s['serviceUrl']}/jobs/daily-charge");
    T::eq($j1['graced'] ?? null, 1);
    jpost("{$s['mockUrl']}/admin/mode", ['status' => 'S1000', 'subscriberId' => $tel]);
    usleep(700_000); // throttleRetrySec=0.5
    $j2 = jpost("{$s['serviceUrl']}/jobs/daily-charge");
    T::eq($j2['charged'] ?? null, 1);
    T::eq(jget("{$s['mockUrl']}/admin/debits")['data']['successfulCount'] ?? null, 1);

    stopStack($s);
});

T::test('E1337 that cannot be reconciled: terminal → NEEDS_REVIEW, never a blind re-fire', function (): void {
    $s = bootStack();
    $tel = 'tel:2000000005';

    jpost("{$s['serviceUrl']}/opt-in", ['msisdn' => $tel]);
    // force a code outside the classification table via an unknown admin status:
    // the mock rejects it, so we hit the real path by setting E1337 globally:
    // E1337 with no stored success = duplicate we cannot reconcile → review.
    jpost("{$s['mockUrl']}/admin/mode", ['status' => 'E1337']);

    $j1 = jpost("{$s['serviceUrl']}/jobs/daily-charge");
    T::eq($j1['needsReview'] ?? null, 1, 'unreconcilable E1337 goes to review, not to retry');

    $j2 = jpost("{$s['serviceUrl']}/jobs/daily-charge");
    T::eq($j2['charged'] ?? null, 0);
    T::eq($j2['skipped'] ?? null, 1, 'terminal: never re-fires a charge in an unknown state');

    stopStack($s);
});

T::test('different billing day → new idempotency key → charged again (once)', function (): void {
    $s = bootStack();
    $tel = 'tel:2000000006';

    jpost("{$s['serviceUrl']}/opt-in", ['msisdn' => $tel]);
    $j1 = jpost("{$s['serviceUrl']}/jobs/daily-charge");
    $j2 = jpost("{$s['serviceUrl']}/jobs/daily-charge", ['day' => '2099-01-01']);
    T::eq($j1['charged'] ?? null, 1);
    T::eq($j2['charged'] ?? null, 1);

    $debits = jget("{$s['mockUrl']}/admin/debits")['data'];
    T::eq($debits['successfulCount'] ?? null, 2);
    T::ok($debits['log'][0]['external_trx_id'] !== $debits['log'][1]['external_trx_id'], 'keys differ per day');
    T::eq($debits['log'][1]['external_trx_id'], expectedExtId($tel, '2099-01-01'));

    // re-running either day still changes nothing
    jpost("{$s['serviceUrl']}/jobs/daily-charge");
    jpost("{$s['serviceUrl']}/jobs/daily-charge", ['day' => '2099-01-01']);
    T::eq(jget("{$s['mockUrl']}/admin/debits")['data']['successfulCount'] ?? null, 2);

    stopStack($s);
});

T::finish('retry');
exit(T::exitCode());
