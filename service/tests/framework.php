<?php
declare(strict_types=1);

/** Minimal test harness (no external deps — see DECISIONS.md). */
final class T
{
    /** @var list<string> */
    public static array $failures = [];
    public static int $passed = 0;

    public static function test(string $name, callable $fn): void
    {
        try {
            $fn();
            self::$passed++;
            echo "  ✓ $name\n";
        } catch (\Throwable $e) {
            self::$failures[] = $name;
            echo "  ✗ $name\n      " . $e->getMessage() . "\n      @ " . basename($e->getFile()) . ':' . $e->getLine() . "\n";
        }
    }

    public static function ok(mixed $cond, string $m = 'assertion failed'): void
    {
        if (!$cond) {
            throw new \Exception($m);
        }
    }

    public static function eq(mixed $actual, mixed $expected, string $m = 'eq'): void
    {
        if ($actual !== $expected) {
            throw new \Exception("$m: expected " . var_export($expected, true) . ", got " . var_export($actual, true));
        }
    }

    public static function contains(string $haystack, string $needle, string $m = 'contains'): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new \Exception("$m: missing '$needle'");
        }
    }

    public static function notContains(string $haystack, string $needle, string $m = 'notContains'): void
    {
        if (str_contains($haystack, $needle)) {
            throw new \Exception("$m: found '$needle'");
        }
    }

    public static function finish(string $group): void
    {
        echo "\n";
        if (self::$failures === []) {
            echo "== $group: " . self::$passed . " passed ==\n";
        } else {
            echo "== $group: " . self::$passed . ' passed, FAILURES: ' . implode(', ', self::$failures) . " ==\n";
        }
    }

    public static function exitCode(): int
    {
        return self::$failures === [] ? 0 : 1;
    }
}
