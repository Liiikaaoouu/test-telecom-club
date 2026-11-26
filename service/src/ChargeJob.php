<?php
declare(strict_types=1);

namespace DailyPass;

/**
 * F3 — the daily charging job. This is the money path; read it closely.
 *
 * Idempotency key design (§D): DP-<yyyymmdd>-<12-hex>, where the hash input
 * is subscriberRef|billingDay — deterministic, so ALL retries and re-runs of
 * one logical daily charge use the SAME externalTrxId. Even if our database
 * were lost, the platform's dedup on this key (E1337) is the second line of
 * defense against double-charging. A new billing day = a new key.
 */
final class ChargeJob
{
    private const TERMINAL_STATUSES = ['SUCCESS', 'FAILED_PERMANENT', 'GRACE_EXHAUSTED', 'NEEDS_REVIEW', 'DUPLICATE', 'CANCELLED'];

    public function __construct(
        private readonly array $cfg,
        private readonly Store $store,
        private readonly PlatformClient $platform
    ) {
    }

    public static function buildExternalTrxId(string $subscriberRef, string $billingDay): string
    {
        return 'DP-' . str_replace('-', '', $billingDay) . '-' . substr(sha1("$subscriberRef|$billingDay"), 0, 12);
    }

    /** Sends an MT SMS. Failures are logged, never thrown: an SMS problem must not break the money path. */
    public function sendSms(string $ref, string $text): void
    {
        try {
            $res = $this->platform->smsSend($ref, $text);
            if (($res['statusCode'] ?? null) !== 'S1000') {
                Logger::warn('SMS not accepted', ['sub' => Logger::mask($ref), 'statusCode' => $res['statusCode'] ?? null]);
            }
        } catch (\Throwable $e) {
            Logger::warn('SMS send failed', ['sub' => Logger::mask($ref), 'error' => (string)$e]);
        }
    }

    /**
     * Daily charging entry point. Runs "new charges for the day" plus
     * "due grace retries for the day". Re-running it any number of times in
     * the same billing day produces at most ONE charge per subscriber.
     *
     * @return array<string, int|string>
     */
    public function runDaily(?string $day = null): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $nowStr = Iso::of($now);
        $day ??= Config::billingDay($this->cfg['billingTz']);

        $summary = ['day' => $day, 'processed' => 0, 'charged' => 0, 'graced' => 0, 'exhausted' => 0, 'suspended' => 0, 'needsReview' => 0, 'skipped' => 0];

        // Audit hygiene: never leave an open GRACE row on a billing day that
        // has already passed — close it out as exhausted.
        foreach ($this->store->listStaleGrace($day, $nowStr) as $stale) {
            $this->store->updateCharge((int)$stale['id'], ['status' => 'GRACE_EXHAUSTED', 'next_retry_at' => null], null, 'billing day closed with retries unresolved');
        }

        foreach ($this->store->listSubscribers(['ACTIVE']) as $sub) {
            $ref = (string)$sub['subscriber_ref'];
            $row = $this->store->getCharge($ref, $day);
            if ($row === null) {
                // Day reservation — the UNIQUE index is the real guard against
                // races and re-runs, not any in-process flag.
                [$row] = $this->store->insertChargeReservation(
                    $ref, $day,
                    self::buildExternalTrxId((string)$sub['subscriber_ref'], $day),
                    (string)$this->cfg['amount'], (string)$this->cfg['currency']
                );
                $summary['processed']++;
            }

            if (in_array($row['status'], self::TERMINAL_STATUSES, true)) {
                $summary['skipped']++;
                continue;
            }

            // GRACE row whose budget ran out while we were away (e.g. restart):
            if ($row['status'] === 'GRACE' && !$row['next_retry_at'] && (int)$row['grace_attempts'] >= count($this->cfg['graceIntervalsSec'])) {
                $this->store->updateCharge((int)$row['id'], ['status' => 'GRACE_EXHAUSTED'], null, 'exhausted after restart');
                $summary['exhausted']++;
                continue;
            }
            if ($row['next_retry_at'] !== null && $row['next_retry_at'] > $nowStr) {
                $summary['skipped']++; // not due yet
                continue;
            }

            // Close the opt-out window as far as SQLite allows: re-read the
            // subscriber right before touching money. A STOP that landed after
            // this run's snapshot (or during an earlier attempt) never gets a
            // debit; its reserved day is closed as CANCELLED.
            if ((string)($this->store->getSubscriber($ref)['status'] ?? '') !== 'ACTIVE') {
                $this->store->updateCharge((int)$row['id'], ['status' => 'CANCELLED', 'next_retry_at' => null], null, 'subscriber no longer ACTIVE — day cancelled, no debit sent');
                $summary['skipped']++;
                continue;
            }

            $this->processAttempt($sub, $row, $now, $summary);
        }

        Logger::info('daily charge run finished', $summary);
        return $summary;
    }

    /**
     * One debit call + classification + ledger update. NEVER re-issues a
     * charge with a fresh key: every attempt reuses the reserved
     * externalTrxId, so the platform's dedup protects the subscriber even if
     * our own state were wiped.
     *
     * @param array<string, mixed> $sub
     * @param array<string, mixed> $row
     * @param array<string, int>   $summary
     */
    private function processAttempt(array $sub, array $row, \DateTimeImmutable $now, array &$summary): void
    {
        $ref = (string)$sub['subscriber_ref'];
        $attemptCount = (int)$row['attempt_count'] + 1;
        $this->store->updateCharge((int)$row['id'], ['attempt_count' => $attemptCount, 'last_status_code' => null], null, "debit attempt #$attemptCount");

        try {
            $res = $this->platform->directDebit((string)$row['external_trx_id'], $ref);
            $code = $res['statusCode'] ?? null;
        } catch (\Throwable $e) {
            // Network failure mid-request: the charge MAY have reached the platform.
            // Safe to retry later with the SAME key (platform dedups) — bounded.
            Logger::error('debit transport error', ['sub' => Logger::mask($ref), 'externalTrxId' => $row['external_trx_id'], 'error' => (string)$e]);
            $res = [];
            $code = 'NETWORK';
        }

        $kind = $code === 'NETWORK' ? 'TRANSIENT' : RetryPolicy::classify(is_string($code) ? $code : null);

        switch ($kind) {
            case 'SUCCESS':
                $this->store->updateCharge((int)$row['id'], [
                    'status' => 'SUCCESS',
                    'last_status_code' => 'S1000',
                    'reference_id' => $res['referenceId'] ?? null,
                    'internal_trx_id' => $res['internalTrxId'] ?? null,
                    'next_retry_at' => null,
                ], 'S1000', 'charged');
                $summary['charged']++;
                $this->sendSms($ref, sprintf(
                    'Daily Pass %s %s charged. Ref %s. Reply STOP to cancel.',
                    $this->cfg['amount'], $this->cfg['currency'], (string)($res['referenceId'] ?? 'n/a')
                ));
                return;

            case 'GRACE_RETRY':
            case 'TRANSIENT':
                $this->scheduleGrace($row, $now, $code, $summary);
                return;

            case 'THROTTLE':
                // E1318 has its own small budget: a busy gateway must not eat
                // the subscriber's balance-retry budget for the day.
                $throttleAttempt = (int)$row['throttle_attempts'] + 1;
                $patch = ['throttle_attempts' => $throttleAttempt];
                if ($throttleAttempt <= (int)$this->cfg['throttleMaxRetries']) {
                    $patch += ['status' => 'GRACE', 'last_status_code' => $code,
                        'next_retry_at' => Iso::plusSeconds($now, (float)$this->cfg['throttleRetrySec'])];
                    $this->store->updateCharge((int)$row['id'], $patch, is_string($code) ? $code : null, "throttle backoff (retry $throttleAttempt of {$this->cfg['throttleMaxRetries']})");
                    $summary['graced']++;
                } else {
                    $this->store->updateCharge((int)$row['id'], $patch, is_string($code) ? $code : null, 'throttle budget exhausted');
                    $this->scheduleGrace($row, $now, $code, $summary);
                }
                return;

            case 'PERMANENT_FAIL':
                // E1308: the operator says this account can never be charged → suspend.
                $this->store->updateCharge((int)$row['id'], ['status' => 'FAILED_PERMANENT', 'last_status_code' => 'E1308', 'next_retry_at' => null],
                    'E1308', 'permanent charging error → suspend');
                $this->store->setSubscriberStatus($ref, 'SUSPENDED');
                $summary['suspended']++;
                Logger::warn('subscriber suspended', ['sub' => Logger::mask($ref), 'reason' => 'E1308']);
                return;

            case 'DUPLICATE':
                // E1337: platform already saw this key — a previous attempt got
                // through (typically: crash after the debit, before recording).
                // NEVER re-charge; recover the original result instead.
                $original = $this->platform->reconcileDebit((string)$row['external_trx_id']);
                if ($original !== null) {
                    $this->store->updateCharge((int)$row['id'], [
                        'status' => 'SUCCESS', 'last_status_code' => 'E1337', 'reconciled' => 1,
                        'reference_id' => $original['referenceId'] ?? null,
                        'internal_trx_id' => $original['internalTrxId'] ?? null,
                        'next_retry_at' => null,
                    ], 'E1337', 'duplicate → reconciled to original success');
                    $summary['charged']++;
                } else {
                    $this->store->updateCharge((int)$row['id'], ['status' => 'DUPLICATE', 'last_status_code' => 'E1337', 'next_retry_at' => null],
                        'E1337', 'duplicate, original result not found — needs manual review');
                    $summary['needsReview']++;
                    Logger::error('duplicate debit without reconciliation result', ['sub' => Logger::mask($ref), 'externalTrxId' => $row['external_trx_id']]);
                }
                return;

            default:
                // UNEXPECTED: unknown code — stop; do not auto-retry into an unknown state.
                $this->store->updateCharge((int)$row['id'], ['status' => 'NEEDS_REVIEW', 'last_status_code' => $code, 'next_retry_at' => null],
                    is_string($code) ? $code : null, 'unexpected platform code');
                $summary['needsReview']++;
                Logger::error('unexpected platform status code', ['sub' => Logger::mask($ref), 'statusCode' => $code]);
        }
    }

    /**
     * Grace (E1326 / transport errors) uses its OWN attempt counter, so the
     * balance-retry budget is exactly GRACE_RETRY_INTERVALS_SEC long no matter
     * how many throttles or attempts happened before.
     *
     * @param array<string, mixed> $row
     * @param array<string, int>   $summary
     */
    private function scheduleGrace(array $row, \DateTimeImmutable $now, mixed $code, array &$summary): void
    {
        $graceAttempt = (int)$row['grace_attempts'] + 1;
        $intervals = $this->cfg['graceIntervalsSec'];
        $next = RetryPolicy::nextRetryAt($now, $graceAttempt, $intervals);
        if ($next !== null) {
            $this->store->updateCharge((int)$row['id'],
                ['status' => 'GRACE', 'grace_attempts' => $graceAttempt, 'last_status_code' => $code, 'next_retry_at' => $next],
                is_string($code) ? $code : null, "grace retry scheduled (grace attempt $graceAttempt of " . count($intervals) . ')');
            $summary['graced']++;
        } else {
            $this->store->updateCharge((int)$row['id'],
                ['status' => 'GRACE_EXHAUSTED', 'grace_attempts' => $graceAttempt, 'last_status_code' => $code, 'next_retry_at' => null],
                is_string($code) ? $code : null, 'grace retries exhausted for this day');
            $summary['exhausted']++;
        }
    }
}
