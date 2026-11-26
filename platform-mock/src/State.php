<?php
declare(strict_types=1);

namespace PlatformMock;

/** SQLite-backed state so the mock survives php -S worker forks and restarts. */
final class State
{
    private function __construct(public readonly \PDO $pdo)
    {
    }

    public static function open(string $path): self
    {
        @mkdir(dirname($path), 0777, true);
        $pdo = new \PDO('sqlite:' . $path, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS sub_modes (subscriber_id TEXT PRIMARY KEY, status TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS subscriptions (subscriber_id TEXT PRIMARY KEY, status TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS subscription_calls (id INTEGER PRIMARY KEY AUTOINCREMENT, at TEXT NOT NULL, action TEXT NOT NULL, subscriber_id TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS sms (id INTEGER PRIMARY KEY AUTOINCREMENT, at TEXT NOT NULL, message TEXT NOT NULL, destinations TEXT NOT NULL, source TEXT);
            CREATE TABLE IF NOT EXISTS debits_ok (external_trx_id TEXT PRIMARY KEY, result TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS debit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, at TEXT NOT NULL, external_trx_id TEXT NOT NULL, subscriber_id TEXT NOT NULL, amount TEXT, result_code TEXT NOT NULL);
        SQL);
        return new self($pdo);
    }

    /* ---------- configurable responses (the assignment requires this) ---------- */

    public function setMode(?string $subscriberId, string $status): void
    {
        if ($subscriberId === null) {
            $this->pdo->prepare('DELETE FROM sub_modes')->execute();
            $this->setSetting('mode', $status);
        } else {
            $st = $this->pdo->prepare('INSERT INTO sub_modes (subscriber_id, status) VALUES (?, ?) ON CONFLICT(subscriber_id) DO UPDATE SET status = excluded.status');
            $st->execute([$subscriberId, $status]);
        }
    }

    public function effectiveMode(string $subscriberId): string
    {
        $st = $this->pdo->prepare('SELECT status FROM sub_modes WHERE subscriber_id = ?');
        $st->execute([$subscriberId]);
        $row = $st->fetch();
        return $row['status'] ?? $this->getSetting('mode', 'S1000');
    }

    public function setLatencyMs(int $ms): void { $this->setSetting('latency_ms', (string)$ms); }
    
    public function latencyMs(): int { return (int)$this->getSetting('latency_ms', '0'); }

    private function setSetting(string $k, string $v): void
    {
        $st = $this->pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v');
        $st->execute([$k, $v]);
    }

    private function getSetting(string $k, string $default): string
    {
        $st = $this->pdo->prepare('SELECT v FROM settings WHERE k = ?');
        $st->execute([$k]);
        $row = $st->fetch();
        return $row['v'] ?? $default;
    }

    /* ---------- contract state ---------- */

    public function recordSubscription(string $subscriberId, string $status, string $action): void
    {
        $st = $this->pdo->prepare('INSERT INTO subscriptions (subscriber_id, status) VALUES (?, ?) ON CONFLICT(subscriber_id) DO UPDATE SET status = excluded.status');
        $st->execute([$subscriberId, $status]);
        $this->pdo->prepare('INSERT INTO subscription_calls (at, action, subscriber_id) VALUES (?, ?, ?)')
            ->execute([date('c'), $action, $subscriberId]);
    }

    /** @return array<string, mixed>|null */
    public function debitSuccess(string $externalTrxId): ?array
    {
        $st = $this->pdo->prepare('SELECT result FROM debits_ok WHERE external_trx_id = ?');
        $st->execute([$externalTrxId]);
        $row = $st->fetch();
        return $row ? (json_decode((string)$row['result'], true) ?: null) : null;
    }

    public function saveDebitSuccess(string $externalTrxId, array $result): void
    {
        $st = $this->pdo->prepare('INSERT INTO debits_ok (external_trx_id, result) VALUES (?, ?) ON CONFLICT(external_trx_id) DO NOTHING');
        $st->execute([$externalTrxId, json_encode($result, JSON_UNESCAPED_SLASHES)]);
    }

    public function logDebitAttempt(string $externalTrxId, string $subscriberId, ?string $amount, string $resultCode): void
    {
        $st = $this->pdo->prepare('INSERT INTO debit_log (at, external_trx_id, subscriber_id, amount, result_code) VALUES (?, ?, ?, ?, ?)');
        $st->execute([date('c'), $externalTrxId, $subscriberId, $amount, $resultCode]);
    }

    public function successfulDebitCount(): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM debits_ok')->fetchColumn();
    }

    public function addSms(string $message, array $destinations, ?string $source): void
    {
        $st = $this->pdo->prepare('INSERT INTO sms (at, message, destinations, source) VALUES (?, ?, ?, ?)');
        $st->execute([date('c'), $message, json_encode($destinations, JSON_UNESCAPED_SLASHES), $source]);
    }

    /** @return list<array<string, mixed>> */
    public function smsList(): array
    {
        return $this->pdo->query('SELECT * FROM sms ORDER BY id')->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function debitLog(): array
    {
        return $this->pdo->query('SELECT * FROM debit_log ORDER BY id')->fetchAll();
    }

    /** @return array<string, mixed> */
    public function subscriptionSnapshot(): array
    {
        $subs = [];
        foreach ($this->pdo->query('SELECT * FROM subscriptions ORDER BY subscriber_id') as $r) {
            $subs[(string)$r['subscriber_id']] = $r['status'];
        }
        $calls = $this->pdo->query('SELECT * FROM subscription_calls ORDER BY id')->fetchAll();
        return ['subscriptions' => $subs, 'calls' => $calls];
    }
}
