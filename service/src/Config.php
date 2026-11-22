<?php
declare(strict_types=1);

namespace DailyPass;

final class Config
{
    /** @return array<string, mixed> */
    public static function fromEnv(): array
    {
        $g = static fn (string $k, string $d): string => (($v = getenv($k)) !== false && $v !== '') ? $v : $d;

        $amount = $g('DAILY_AMOUNT', '5.00');
        if (!preg_match('/^\d+\.\d{2}$/', $amount)) {
            // fail fast at boot: a malformed amount must never reach a debit request
            throw new \InvalidArgumentException('DAILY_AMOUNT must have exactly 2 decimal places (e.g. "5.00"), got: ' . $amount);
        }

        return [
            'port'          => (int)$g('PORT', '8080'),
            'platformUrl'   => rtrim($g('PLATFORM_URL', 'http://127.0.0.1:9100'), '/'),
            'applicationId' => $g('APP_ID', 'APP_000123'),
            'password'      => $g('APP_PASSWORD', 'demo-password'),
            'amount'        => $amount,
            'currency'      => $g('DAILY_CURRENCY', 'XTS'),
            'shortcode'     => $g('SMS_SHORTCODE', '3030'),
            'billingTz'     => $g('BILLING_TZ', 'UTC'),
            'dbPath'        => $g('DB_PATH', 'data/daily-pass.db'),
            'httpTimeoutMs' => (float)$g('PLATFORM_TIMEOUT_MS', '5000'),
            // bounded grace retries for E1326 — SECONDS before attempt N (length = max retries)
            'graceIntervalsSec'  => array_map('floatval', explode(',', $g('GRACE_RETRY_INTERVALS_SEC', '120,300,600'))),
            'throttleRetrySec'   => floatval($g('THROTTLE_RETRY_SEC', '1')),
            'msisdnStrict'  => $g('MSISDN_STRICT', 'true') !== 'false',
            'jobIntervalMin' => (int)$g('JOB_INTERVAL_MINUTES', '0'),
        ];
    }

    /** Billing day (YYYY-MM-DD) in the configured timezone. */
    public static function billingDay(string $tz): string
    {
        try {
            $now = new \DateTimeImmutable('now', new \DateTimeZone($tz));
        } catch (\Exception) {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }
        return $now->format('Y-m-d');
    }
}
