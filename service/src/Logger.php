<?php
declare(strict_types=1);

namespace DailyPass;

final class Logger
{
    /** Recursively strip secret fields — nothing that reaches a log may contain the platform password. */
    public static function sanitize(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = (is_string($k) && strtolower($k) === 'password') ? '***' : self::sanitize($v);
            }
            return $out;
        }
        return $value;
    }

    /** Stable non-reversible label for a subscriber id, safe to log. */
    public static function mask(?string $ref): string
    {
        return $ref === null || $ref === '' ? 'unknown' : 'sub#' . substr(sha1($ref), 0, 8);
    }

    /** @param array<string, mixed> $ctx */
    public static function line(string $level, string $msg, array $ctx = []): void
    {
        $line = json_encode(['ts' => Iso::now(), 'level' => $level, 'msg' => $msg] + self::sanitize($ctx), JSON_UNESCAPED_SLASHES);
        // php -S (cli-server) does not define STDOUT/STDERR constants — use streams
        $h = fopen($level === 'error' ? 'php://stderr' : 'php://stdout', 'wb');
        if ($h !== false) {
            fwrite($h, $line . PHP_EOL);
            fclose($h);
        }
    }

    public static function info(string $m, array $c = []): void { self::line('info', $m, $c); }
    public static function warn(string $m, array $c = []): void { self::line('warn', $m, $c); }
    public static function error(string $m, array $c = []): void { self::line('error', $m, $c); }
    public static function debug(string $m, array $c = []): void
    {
        if (getenv('LOG_LEVEL') === 'debug') {
            self::line('debug', $m, $c);
        }
    }
}
