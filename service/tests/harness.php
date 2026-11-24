<?php
declare(strict_types=1);

/**
 * Boots the REAL stack over HTTP: platform-mock + service as separate
 * `php -S` processes on ephemeral ports, with SQLite files under /tmp —
 * exactly the integration the graders run, so the tests cannot accidentally
 * bypass the money-safety machinery.
 */

// pcntl is not loaded in php:8.2-cli; posix provides posix_kill but not the
// signal constants, so define the Linux values ourselves.
defined('SIGTERM') || define('SIGTERM', 15);
defined('SIGKILL') || define('SIGKILL', 9);

function projectRoot(): string
{
    return dirname(__DIR__, 2);
}

function freePort(): int
{
    $srv = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $name = (string)stream_socket_get_name($srv, false);
    fclose($srv);
    return (int)substr($name, strrpos($name, ':') + 1);
}

function portOpen(int $port): bool
{
    $sock = @stream_socket_client("tcp://127.0.0.1:$port", $e, $s, 0.2);
    if ($sock === false) {
        return false;
    }
    fclose($sock);
    return true;
}

/**
 * php -S worker pools can be re-parented or race with startup, so we kill by
 * port: every /proc entry whose cmdline listens on 127.0.0.1:$port.
 */
function killPortProcesses(int $port, ?int $hintPid = null): void
{
    $needle = "127.0.0.1:$port";
    $targets = $hintPid && $hintPid > 0 ? [$hintPid] : [];
    foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $f) {
        $cmd = (string)@file_get_contents($f);
        if ($cmd !== '' && str_contains($cmd, $needle) && str_contains($cmd, 'php')) {
            $pid = (int)basename(dirname($f));
            if ($pid > 0 && !in_array($pid, $targets, true)) {
                $targets[] = $pid;
            }
        }
    }
    foreach ($targets as $t) {
        @posix_kill($t, SIGTERM);
    }
    $deadline = microtime(true) + 3;
    while (microtime(true) < $deadline) {
        $alive = array_filter($targets, static fn(int $t): bool => @posix_kill($t, 0));
        if ($alive === []) {
            break;
        }
        usleep(100_000);
    }
    foreach ($targets as $t) {
        if (@posix_kill($t, 0)) {
            @posix_kill($t, SIGKILL);
        }
    }
}

/** @param array<string, scalar> $env @return int pid of the php -S master */
function spawn(array $env, int $port, string $router, string $logFile): int
{
    $prefix = '';
    foreach ($env as $k => $v) {
        $prefix .= "$k=" . escapeshellarg((string)$v) . ' ';
    }
    $cmd = rtrim($prefix) . " php -S 127.0.0.1:$port $router >> " . escapeshellarg($logFile) . ' 2>&1 & echo $!';
    $ph = popen("bash -c " . escapeshellarg($cmd), 'r');
    if ($ph === false) {
        throw new \RuntimeException('failed to spawn server');
    }
    $pid = (int)trim((string)fgets($ph));
    pclose($ph);
    return $pid;
}

/** php -S with PHP_CLI_SERVER_WORKERS forks children — kill the whole tree. */
function killTree(int $pid): void
{
    if ($pid <= 0) {
        return;
    }
    $targets = array_merge([$pid], childPids($pid));
    foreach ($targets as $t) {
        @posix_kill($t, SIGTERM);
    }
    $deadline = microtime(true) + 3;
    while (microtime(true) < $deadline) {
        $alive = array_filter($targets, static fn(int $t): bool => @posix_kill($t, 0));
        if ($alive === []) {
            return;
        }
        usleep(100_000);
    }
    foreach ($targets as $t) {
        if (@posix_kill($t, 0)) {
            @posix_kill($t, SIGKILL);
        }
    }
}

/** @return list<int> */
function childPids(int $ppid): array
{
    $out = [];
    foreach (glob('/proc/[0-9]*/stat') ?: [] as $f) {
        $stat = @file_get_contents($f);
        if ($stat === false) {
            continue;
        }
        $rp = strrpos($stat, ')');
        if ($rp === false) {
            continue;
        }
        $fields = preg_split('/\s+/', trim(substr($stat, $rp + 2)));
        if (($fields[2] ?? null) === (string)$ppid) {
            $out[] = (int)basename(dirname($f));
        }
    }
    return $out;
}

function waitForPortClosed(int $port, string $logFile, float $timeoutSec = 6.0): void
{
    $deadline = microtime(true) + $timeoutSec;
    while (microtime(true) < $deadline) {
        if (!portOpen($port)) {
            return;
        }
        usleep(100_000);
    }
    throw new \Exception("port $port still busy after kill. Log tail:\n" . tailFile($logFile));
}

function waitHealth(string $url, string $logFile, float $timeoutSec = 25.0): void
{
    $deadline = microtime(true) + $timeoutSec;
    while (microtime(true) < $deadline) {
        $ctx = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        if (is_string($body) && str_contains($body, 'ok')) {
            return;
        }
        usleep(150_000);
    }
    throw new \Exception("server at $url did not come up. Log tail:\n" . tailFile($logFile));
}

function tailFile(string $path, int $lines = 25): string
{
    if (!is_file($path)) {
        return '(no log file)';
    }
    $all = file($path) ?: [];
    return implode('', array_slice($all, -$lines));
}

/** @return array<string, mixed> */
function bootStack(array $svcOverrides = [], array $mockOverrides = []): array
{
    $root = projectRoot();
    $tmp = sys_get_temp_dir() . '/dp-' . bin2hex(random_bytes(6));
    mkdir($tmp, 0777, true);
    $mockPort = freePort();
    $svcPort = freePort();

    $common = ['APP_ID' => 'APP_TEST', 'APP_PASSWORD' => 'demo-password'];

    $mockEnv = array_replace($common + [
        'PORT' => $mockPort,
        'MOCK_DB' => "$tmp/mock.db",
        'SERVICE_URL' => "http://127.0.0.1:$svcPort",
        'PHP_CLI_SERVER_WORKERS' => 4,
    ], $mockOverrides);
    $svcEnv = array_replace($common + [
        'PORT' => $svcPort,
        'DB_PATH' => "$tmp/service.db",
        'PLATFORM_URL' => "http://127.0.0.1:$mockPort",
        'DAILY_AMOUNT' => '5.00',
        'DAILY_CURRENCY' => 'XTS',
        'GRACE_RETRY_INTERVALS_SEC' => '1,2,3',
        'THROTTLE_RETRY_SEC' => '0.5',
        'JOB_INTERVAL_MINUTES' => 0,
        'PHP_CLI_SERVER_WORKERS' => 4,
    ], $svcOverrides);

    $st = [
        'tmp' => $tmp,
        'mockPort' => $mockPort,
        'svcPort' => $svcPort,
        'mockUrl' => "http://127.0.0.1:$mockPort",
        'serviceUrl' => "http://127.0.0.1:$svcPort",
        'svcDb' => (string)$svcEnv['DB_PATH'],
        'svcLog' => "$tmp/service.log",
        'mockLog' => "$tmp/mock.log",
        'mockEnv' => $mockEnv,
        'svcEnv' => $svcEnv,
    ];

    $st['mockPid'] = spawn($st['mockEnv'], $mockPort, "$root/platform-mock/public/router.php", $st['mockLog']);
    waitHealth($st['mockUrl'] . '/health', $st['mockLog']);
    $st['svcPid'] = spawn($st['svcEnv'], $svcPort, "$root/service/public/router.php", $st['svcLog']);
    waitHealth($st['serviceUrl'] . '/health', $st['svcLog']);
    return $st;
}

function stopServiceOnly(array $st): void
{
    killPortProcesses((int)$st['svcPort'], (int)($st['svcPid'] ?? 0));
    waitForPortClosed((int)$st['svcPort'], (string)$st['svcLog']);
}

/** @return array<string, mixed> updated stack (new svcPid) */
function startServiceAgain(array $st, array $overrides = []): array
{
    $root = projectRoot();
    $env = array_replace($st['svcEnv'], $overrides);
    $st['svcPid'] = spawn($env, (int)$st['svcPort'], "$root/service/public/router.php", (string)$st['svcLog']);
    waitHealth($st['serviceUrl'] . '/health', (string)$st['svcLog']);
    return $st;
}

function stopStack(array $st): void
{
    killPortProcesses((int)($st['svcPort'] ?? 0), (int)($st['svcPid'] ?? 0));
    killPortProcesses((int)($st['mockPort'] ?? 0), (int)($st['mockPid'] ?? 0));
}

/* ---------------- HTTP helpers ---------------- */

/** @return array{status: int, data: array<string, mixed>} */
function jreq(string $method, string $url, ?array $body = null, float $timeoutMs = 20000, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT_MS => (int)$timeoutMs,
    ]);
    $h = $headers;
    if ($body !== null) {
        $h[] = 'content-type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
    }
    if ($h !== []) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
    }
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($raw === false) {
        throw new \Exception("transport failure calling $url");
    }
    $data = json_decode((string)$raw, true);
    return ['status' => $status, 'data' => is_array($data) ? $data : []];
}

/** @return array{status: int, data: array<string, mixed>} */
function jget(string $url): array
{
    return jreq('GET', $url);
}

/** @return array<string, mixed> decoded body */
function jpost(string $url, ?array $body = null): array
{
    return jreq('POST', $url, $body ?? [])['data'];
}

/**
 * Fire a POST without waiting for the response (for the opt-out race test);
 * read it later with readPendingResponse().
 * @return \Socket|resource
 */
function asyncPost(string $url, array $body)
{
    $u = parse_url($url);
    $fp = stream_socket_client('tcp://' . $u['host'] . ':' . $u['port'], $errno, $errstr, 5.0);
    if ($fp === false) {
        throw new \Exception("cannot connect: $errstr");
    }
    $payload = (string)json_encode($body, JSON_UNESCAPED_SLASHES);
    fwrite($fp, "POST {$u['path']} HTTP/1.1\r\nHost: {$u['host']}\r\ncontent-type: application/json\r\ncontent-length: " . strlen($payload) . "\r\n\r\n$payload");
    stream_set_timeout($fp, 30);
    return $fp;
}

/** @param resource $fp @return array<string, mixed> */
function readPendingResponse($fp): array
{
    $raw = '';
    while (!feof($fp)) {
        $chunk = fgets($fp);
        if ($chunk === false) {
            break;
        }
        $raw .= $chunk;
    }
    fclose($fp);
    $parts = explode("\r\n\r\n", $raw, 2);
    return is_string($parts[1] ?? null) ? (json_decode($parts[1], true) ?: []) : [];
}

/** Direct DB poke (only for tests simulating a crash between debit and record). */
function pokeServiceDb(string $dbPath, string $sql, array $args = []): int
{
    $pdo = new \PDO('sqlite:' . $dbPath, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->rowCount();
}

function today(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
}

/** Mirror of ChargeJob::buildExternalTrxId — the tests pin the key design. */
function expectedExtId(string $ref, string $day): string
{
    return 'DP-' . str_replace('-', '', $day) . '-' . substr(sha1("$ref|$day"), 0, 12);
}
