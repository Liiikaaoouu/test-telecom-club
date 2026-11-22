<?php
declare(strict_types=1);

namespace DailyPass;

final class Validation
{
    public const TEL_RE = '/^tel:\d{6,15}$/';
    public const OPAQUE_RE = '/^[A-Za-z0-9:._-]{4,64}$/';
    public const AMOUNT_RE = '/^\d+\.\d{2}$/';

    /**
     * Boundary validation. Strict mode = a real MSISDN ("tel:<digits>").
     * Masked mode = a stable operator alias — treated as OPAQUE everywhere in
     * the codebase and never parsed back into a phone number.
     *
     * @return array{ok: bool, value?: string, error?: string}
     */
    public static function subscriberId(mixed $value, bool $strict = true): array
    {
        if (!is_string($value) || $value === '') {
            return ['ok' => false, 'error' => 'subscriberId must be a non-empty string'];
        }
        if ($strict) {
            return preg_match(self::TEL_RE, $value)
                ? ['ok' => true, 'value' => $value]
                : ['ok' => false, 'error' => 'subscriberId must look like tel:<6-15 digits>'];
        }
        return preg_match(self::OPAQUE_RE, $value)
            ? ['ok' => true, 'value' => $value]
            : ['ok' => false, 'error' => 'subscriberId contains unsupported characters'];
    }

    /** @return array{ok: bool, value?: string, error?: string} */
    public static function amount(mixed $value): array
    {
        return is_string($value) && preg_match(self::AMOUNT_RE, $value)
            ? ['ok' => true, 'value' => $value]
            : ['ok' => false, 'error' => 'amount must have exactly 2 decimal places, e.g. "5.00"'];
    }
}
