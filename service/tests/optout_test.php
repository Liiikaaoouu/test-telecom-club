<?php
declare(strict_types=1);

require_once __DIR__ . '/framework.php';
require_once __DIR__ . '/harness.php';

/* F4 opt-out: STOP handling, duplicate STOPs, and the opt-out-vs-charge race. */

T::test('STOP opts out; the subscriber is never charged again', function (): void {
    $s = bootStack();
    $tel = 'tel:3000000001';

    jpost("{$s['serviceUrl']}/opt-in", ['msisdn' => $tel]);
    $j1 = jpost("{$s['serviceUrl']}/jobs/daily-charge");
    T::eq($j1['charged'] ?? null, 1, 'active subscriber is charged');

    $mo = jpost("{$s['mockUrl']}/admin/mo", ['sourceAddress' => $tel, 'message' => 'STOP']);
    T::eq($mo['webhookReply']['statusCode'] ?? null, 'S1000');

    $st = jget("{$s['serviceUrl']}/status?subscriber=" . rawurlencode($tel))['data'];
    T::eq($st['status'] ?? null, 'INACTIVE');

    $subs = jget("{$s['mockUrl']}/admin/subscriptions")['data'];
    T::eq($subs['subscriptions'][$tel] ?? null, 'UNREGISTERED', 'platform told about the opt-out');
    $action0 = array_values(array_filter($subs['calls'], fn($c) => $c['action'] === '0'));
    T::eq(count($action0), 1, 'exactly one opt-out call');

    $sms = jget("{$s['mockUrl']}/admin/sms")['data'];
    $msgs = $sms['messages'];
    $lastMsg = end($msgs);
    T::contains((string)($lastMsg['message'] ?? ''), 'cancelled', 'goodbye SMS sent');

    $j2 = jpost("{$s['serviceUrl']}/jobs/daily-charge");
    T::eq($j2['charged'] ?? null, 0);
    $j3 = jpost("{$s['serviceUrl']}/jobs/daily-charge", ['day' => '2099-01-01']);
    T::eq($j3['charged'] ?? null, 0, 'not charged on a future day either');
    T::eq(jget("{$s['mockUrl']}/admin/debits")['data']['successfulCount'] ?? null, 1, 'only the pre-opt-out charge exists');

    stopStack($s);
});

T::test('Duplicate STOPs are no-ops (single farewell SMS, single platform call)', function (): void {
    $s = bootStack();
    $tel = 'tel:3000000002';

    jpost("{$s['serviceUrl']}/opt-in", ['msisdn' => $tel]);
    jpost("{$s['mockUrl']}/admin/mo", ['sourceAddress' => $tel, 'message' => 'STOP']);
    jpost("{$s['serviceUrl']}/webhooks/mo-sms", ['sourceAddress' => $tel, 'message' => 'stop ']); // replayed, lowercase+padding
    jpost("{$s['mockUrl']}/admin/mo", ['sourceAddress' => $tel, 'message' => 'STOP']);

    $subs = jget("{$s['mockUrl']}/admin/subscriptions")['data'];
    $action0 = array_values(array_filter($subs['calls'], fn($c) => $c['action'] === '0'));
    T::eq(count($action0), 1, 'platform notified exactly once');

    $sms = jget("{$s['mockUrl']}/admin/sms")['data'];
    $goodbye = array_values(array_filter($sms['messages'], fn($m) => str_contains((string)$m['message'], 'cancelled')));
    T::eq(count($goodbye), 1, 'exactly one farewell SMS');

    stopStack($s);
});

T::test('Opt-out DURING an in-flight charge: exactly one debit, no charge afterwards', function (): void {
    $s = bootStack();
    $tel = 'tel:3000000003';

    jpost("{$s['serviceUrl']}/opt-in", ['msisdn' => $tel]);
    jpost("{$s['mockUrl']}/admin/latency", ['ms' => 600]); // slow the debit call

    $pending = asyncPost("{$s['serviceUrl']}/jobs/daily-charge", []);
    usleep(200_000); // the STOP arrives while the debit is in flight
    $mo = jpost("{$s['mockUrl']}/admin/mo", ['sourceAddress' => $tel, 'message' => 'STOP']);
    T::eq($mo['webhookReply']['statusCode'] ?? null, 'S1000', 'opt-out processed concurrently');
    $jobResp = readPendingResponse($pending);

    T::eq($jobResp['charged'] ?? null, 1, 'the in-flight debit completed (platform already took the money)');
    $debits = jget("{$s['mockUrl']}/admin/debits")['data'];
    T::eq($debits['successfulCount'] ?? null, 1, 'and nothing else was charged');

    $st = jget("{$s['serviceUrl']}/status?subscriber=" . rawurlencode($tel))['data'];
    T::eq($st['status'] ?? null, 'INACTIVE');
    T::eq($st['chargeToday']['status'] ?? null, 'SUCCESS');

    jpost("{$s['serviceUrl']}/jobs/daily-charge");
    $future = jpost("{$s['serviceUrl']}/jobs/daily-charge", ['day' => '2099-01-01']);
    T::eq($future['charged'] ?? null, 0);
    T::eq(jget("{$s['mockUrl']}/admin/debits")['data']['successfulCount'] ?? null, 1, 'still exactly one debit after re-runs');

    jpost("{$s['mockUrl']}/admin/latency", ['ms' => 0]);
    stopStack($s);
});

T::test('STOP from an unknown number is accepted and harmless', function (): void {
    $s = bootStack();
    $mo = jpost("{$s['serviceUrl']}/webhooks/mo-sms", ['sourceAddress' => 'tel:9999999999', 'message' => 'STOP']);
    T::eq($mo['statusCode'] ?? null, 'S1000');
    T::eq(jget("{$s['mockUrl']}/admin/debits")['data']['successfulCount'] ?? null, 0);
    stopStack($s);
});

T::finish('optout');
exit(T::exitCode());
