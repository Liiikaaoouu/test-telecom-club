<?php
declare(strict_types=1);

namespace DailyPass;

/** Entry point for `php -S` (service). Every request wires fresh deps — state lives in SQLite. */

require __DIR__ . '/../src/autoload.php';

$cfg = Config::fromEnv(); // fails fast on a malformed DAILY_AMOUNT
$store = new Store(Database::open((string)$cfg['dbPath']));
$platform = new PlatformClient($cfg);
$job = new ChargeJob($cfg, $store, $platform);
$handlers = new Handlers($cfg, $store, $platform, $job);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$handlers->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', (string)$path);
