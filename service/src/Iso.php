<?php
declare(strict_types=1);

namespace DailyPass;

/** ISO-8601 UTC with milliseconds + "Z" — lexicographically comparable. */
final class Iso
{
    public static function now(): string
    {
        return self::of(new \DateTimeImmutable('now'));
    }

    public static function of(\DateTimeImmutable $d): string
    {
        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v') . 'Z';
    }

    public static function plusSeconds(\DateTimeImmutable $d, float $sec): string
    {
        $us = (int) round($sec * 1_000_000);
        return self::of($d->modify("+{$us} microseconds"));
    }
}
