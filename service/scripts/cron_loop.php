<?php
declare(strict_types=1);

/**
 * Optional scheduler: when JOB_INTERVAL_MINUTES > 0, POSTs /jobs/daily-charge
 * into the local service every interval. The job itself is idempotent, so it
 * does not matter who triggers it or how often.
 */

require __DIR__ . '/../src/autoload.php';

$cfg = Config::fromEnv();
$minutes = max(1, (int)$cfg['jobIntervalMin']);
$interval = $minutes * 60;
$url = 'http://127.0.0.1:' . $cfg['port'] . '/jobs/daily-charge';

sleep(5); // let the server come up
while (true) {
    $res = Http::postJson($url, [], 30_000, false);
    Logger::info('cron triggered daily charge', ['tick' => date('c'), 'result' => $res]);
    sleep($interval);
}
