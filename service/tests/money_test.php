<?php
declare(strict_types=1);

require_once __DIR__ . '/framework.php';
require_once __DIR__ . '/harness.php';

/* Money-critical paths: charge-once, duplicate webhooks, crash recovery, restarts. */

T::test('F3: job re-runs on the same billing day charge exactly ONCE', function (): void {
    $s = bootStack();
    $tel = 'tel:1000000001';

    $opt = jpost("{$s['serviceUrl']}/opt-in", ['msisdn' => $tel]);
    T::ok(($opt['ok'] ?? false) === true, 'opt-in ok');
    T::eq(jget("{$s['mockUrl']}/admin/sms")['data']['count'] ?? 0, 1, 'welcome SMS sent');

    $j1 = jpost("{$s['serviceUrl']}/jobs/daily-charge");
    $j2 = jpost("{$s['serviceUrl']}/jobs/daily-charge");
    $j3 = jpost("{$s['serviceUrl']}/jobs/daily-charge");

    T::eq($j1['charged'] ?? null, 1, 'first run charges');
    T::eq($j2['charged'] ?? null, 0, 'second run must not charge');
    T::eq($j3['charged'] ?? null, 0, 'third run must not charge');
    T::eq($j2['skipped'] ?? null, 1, 'second run skips the reserved day');

    $debits = jget("{$s['mockUrl']}/admin/debits")['data'];
    T::eq($debits['successfulCount'] ?? null, 1, 'exactly one successful debit ever');
    T::eq(count($debits['log'] ?? []), 1, 'exactly one debit CALL (not just success)');

    $sms = jget("{$s['mockUrl']}/admin/sms")['data'];
    T::eq($sms['count'] ?? null, 2, 'welcome + receipt');
    T::contains((string)($sms['messages'][1]['message'] ?? ''), 'Ref ', 'receipt carries referenceId');

    $st = jget("{$s['serviceUrl']}/status?subscriber=" . rawurlencode($tel))['data'];
    T::eq($st['chargeToday']['status'] ?? null, 'SUCCESS');
    T::eq($st['externalTrxIdToday'] ?? null, expectedExtId($tel, today()), 'deterministic idempotency key');

    stopStack($s);
});

T::test('F2: duplicate subscription notifications neither re-activate nor double-charge', function (): void {
    $s = bootStack();
    $tel = 'tel:1000000002';

    $notif = jpost("{$s['mockUrl']}/admin/notify", ['subscriberId' => $tel, 'status' => 'REGISTERED']);
    T::eq($notif['webhookReply']['statusCode'] ?? null, 'S1000', 'webhook replies S1000');
    $payload = $notif['payload'];

    // re-deliver the EXACT same event twice (same timeStamp → same dedup key)
    $dup1 = jpost("{$s['serviceUrl']}/webhooks/subscription-notification", $payload);
    $dup2 = jpost("{$s['serviceUrl']}/webhooks/subscription-notification", $payload);
    T::eq($dup1['statusCode'] ?? null, 'S1000');
    T::eq($dup2['statusCode'] ?? null, 'S1000');

    $sms = jget("{$s['mockUrl']}/admin/sms")['data'];
    T::eq($sms['count'] ?? null, 1, 'no welcome-SMS spam on duplicates');

    $j = jpost("{$s['serviceUrl']}/jobs/daily-charge");
    T::eq($j['charged'] ?? null, 1);
    $j2 = jpost("{$s['serviceUrl']}/jobs/daily-charge");
    T::eq($j2['charged'] ?? null, 0);
    T::eq(jget("{$s['mockUrl']}/admin/debits")['data']['successfulCount'] ?? null, 1, 'duplicates did not double-charge');

    stopStack($s);
});

T::test('Crash between debit and record → E1337 → reconcile, never a second charge', function (): void {
    $s = bootStack();
    $tel = 'tel:1000000003';

    jpost("{$s['serviceUrl']}/opt-in", ['msisdn' => $tel]);
    $j1 = jpost("{$s['serviceUrl']}/jobs/daily-charge");
    T::eq($j1['charged'] ?? null, 1);

    $before = jget("{$s['serviceUrl']}/admin/charges?subscriber=" . rawurlencode($tel) . '&day=' . today())['data']['charges'][0] ?? [];
    $refBefore = $before['reference_id'] ?? null;
    T::ok(is_string($refBefore) && $refBefore !== '', 'reference recorded on first charge');

    // simulate: platform processed the debit, but we died before recording it
    $rows = pokeServiceDb($s['svcDb'],
        "UPDATE charges SET status='PENDING', attempt_count=0, last_status_code=NULL, reference_id=NULL, internal_trx_id=NULL, reconciled=0 WHERE subscriber_ref=?",
        [$tel]);
    T::eq($rows, 1);

    $j2 = jpost("{$s['serviceUrl']}/jobs/daily-charge");
    T::eq($j2['charged'] ?? null, 1, 'reconciled to the original success');

    $debits = jget("{$s['mockUrl']}/admin/debits")['data'];
    T::eq($debits['successfulCount'] ?? null, 1, 'platform deduped the retry (E1337)');
    T::eq(count($debits['log'] ?? []), 2, 'two debit CALLS total: original + reconciled duplicate');
    T::eq($debits['log'][1]['result_code'] ?? null, 'E1337', 'second call was refused as duplicate');

    $after = jget("{$s['serviceUrl']}/admin/charges?subscriber=" . rawurlencode($tel) . '&day=' . today())['data']['charges'][0] ?? [];
    T::eq($after['status'] ?? null, 'SUCCESS');
    T::eq($after['reconciled'] ?? null, true, 'reconciled flag set');
    T::eq($after['reference_id'] ?? null, $refBefore, 'original referenceId restored');

    stopStack($s);
});

T::test('Service restart: charge-once state survives the process', function (): void {
    $s = bootStack();
    $tel = 'tel:1000000004';

    jpost("{$s['serviceUrl']}/opt-in", ['msisdn' => $tel]);
    $j1 = jpost("{$s['serviceUrl']}/jobs/daily-charge");
    T::eq($j1['charged'] ?? null, 1);

    stopServiceOnly($s);            // hard restart of the service only
    $s = startServiceAgain($s);     // same DB file, same port

    $j2 = jpost("{$s['serviceUrl']}/jobs/daily-charge");
    T::eq($j2['charged'] ?? null, 0, 'restarted service does not re-charge the day');
    T::eq(jget("{$s['mockUrl']}/admin/debits")['data']['successfulCount'] ?? null, 1);

    $st = jget("{$s['serviceUrl']}/status?subscriber=" . rawurlencode($tel))['data'];
    T::eq($st['status'] ?? null, 'ACTIVE', 'subscriber state persisted across restart');

    stopStack($s);
});

T::finish('money');
exit(T::exitCode());
