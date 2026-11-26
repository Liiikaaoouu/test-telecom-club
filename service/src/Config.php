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
        if (!Validation::amount($amount)['ok']) {
            // fail fast at boot: a malformed amount must never reach a debit request
            throw new \InvalidArgumentException('DAILY_AMOUNT must have exactly 2 decimal places (e.g. "5.00"), got: ' . $amount);
        }

        // Secrets come from config/env ONLY — never a hard-coded fallback.
        foreach (['APP_ID', 'APP_PASSWORD'] as $secret) {
            if (getenv($secret) === false || getenv($secret) === '') {
                throw new \InvalidArgumentException("$secret must be provided via environment — refusing to boot with a default credential");
            }
        }

        return [
            'port'          => (int)$g('PORT', '8080'),
            'platformUrl'   => rtrim($g('PLATFORM_URL', 'http://127.0.0.1:9100'), '/'),
            'applicationId' => (string)getenv('APP_ID'),
            'password'      => (string)getenv('APP_PASSWORD'),
            // optional hardening; empty string = disabled (demo default)
            'webhookSecret' => $g('CARRIER_WEBHOOK_SECRET', ''),
            'apiToken'      => $g('SERVICE_API_TOKEN', ''),
            'amount'        => $amount,
            'currency'      => $g('DAILY_CURRENCY', 'XTS'),
            'shortcode'     => $g('SMS_SHORTCODE', '3030'),
            'billingTz'     => $g('BILLING_TZ', 'UTC'),
            'dbPath'        => $g('DB_PATH', 'data/daily-pass.db'),
            'httpTimeoutMs' => (float)$g('PLATFORM_TIMEOUT_MS', '5000'),
            // bounded grace retries for E1326 (and transport errors) — SECONDS before attempt N (length = max retries)
            'graceIntervalsSec'  => array_map('floatval', explode(',', $g('GRACE_RETRY_INTERVALS_SEC', '120,300,600'))),
            // E1318 throttle: own small budget, never consumes the grace budget
            'throttleRetrySec'   => floatval($g('THROTTLE_RETRY_SEC', '1')),
            'throttleMaxRetries' => (int)$g('THROTTLE_MAX_RETRIES', '10'),
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
