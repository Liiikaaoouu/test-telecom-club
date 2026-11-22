<?php
declare(strict_types=1);

namespace DailyPass;

/**
 * §E classification. Families:
 *  - "never charge again for this day" → PERMANENT_FAIL, DUPLICATE
 *  - "same idempotency key may be re-offered later" → GRACE_RETRY, THROTTLE,
 *    TRANSIENT (network errors mid-request: a re-send with the SAME key can
 *    not double-charge because the platform dedups on the key).
 * Anything unknown → UNEXPECTED (stop, manual review — never auto-retry an
 * unknown code into a possible double charge).
 */
final class RetryPolicy
{
    public const CLASSIFICATION = [
        'S1000' => 'SUCCESS',
        'E1337' => 'DUPLICATE',       // duplicate request (same externalTrxId) — non-retry-able
        'E1308' => 'PERMANENT_FAIL',  // permanent charging error — non-retry-able → suspend
        'E1326' => 'GRACE_RETRY',     // insufficient balance — transient → bounded retries
        'E1318' => 'THROTTLE',        // per-second limit — retry-able → short backoff
    ];

    public static function classify(?string $statusCode): string
    {
        return self::CLASSIFICATION[$statusCode ?? ''] ?? 'UNEXPECTED';
    }

    /**
     * ISO timestamp for the retry of attempt N (1-based);
     * null when the retry budget is exhausted.
     *
     * @param float[] $intervalsSec
     */
    public static function nextRetryAt(\DateTimeImmutable $now, int $attempt, array $intervalsSec): ?string
    {
        if ($attempt > count($intervalsSec)) {
            return null;
        }
        return Iso::plusSeconds($now, $intervalsSec[$attempt - 1]);
    }
}
