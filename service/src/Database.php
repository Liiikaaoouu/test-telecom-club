<?php
declare(strict_types=1);

namespace DailyPass;

final class Database
{
    private const SCHEMA = <<<'SQL'
CREATE TABLE IF NOT EXISTS subscribers (
  subscriber_ref TEXT PRIMARY KEY,
  status TEXT NOT NULL CHECK (status IN ('PENDING','OPTING_IN','ACTIVE','SUSPENDED','INACTIVE')),
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS charges (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  subscriber_ref TEXT NOT NULL,
  billing_day TEXT NOT NULL,
  external_trx_id TEXT NOT NULL,
  amount TEXT NOT NULL,
  currency TEXT NOT NULL,
  status TEXT NOT NULL CHECK (status IN
    ('PENDING','GRACE','SUCCESS','FAILED_PERMANENT','DUPLICATE','GRACE_EXHAUSTED','NEEDS_REVIEW','CANCELLED')),
  attempt_count INTEGER NOT NULL DEFAULT 0,
  grace_attempts INTEGER NOT NULL DEFAULT 0,
  throttle_attempts INTEGER NOT NULL DEFAULT 0,
  last_status_code TEXT,
  reference_id TEXT,
  internal_trx_id TEXT,
  reconciled INTEGER NOT NULL DEFAULT 0,
  next_retry_at TEXT,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  -- MONEY SAFETY: one ledger row per subscriber per billing day.
  UNIQUE (subscriber_ref, billing_day)
);
CREATE INDEX IF NOT EXISTS idx_charges_day ON charges (billing_day, status);

CREATE TABLE IF NOT EXISTS webhook_events (
  dedup_key TEXT PRIMARY KEY,
  event_type TEXT NOT NULL,
  subscriber_ref TEXT,
  payload TEXT NOT NULL,
  received_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS charge_audit (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  charge_id INTEGER NOT NULL,
  external_trx_id TEXT NOT NULL,
  from_status TEXT,
  to_status TEXT,
  platform_code TEXT,
  note TEXT,
  created_at TEXT NOT NULL
);
SQL;

    public static function open(string $path): \PDO
    {
        if ($path !== ':memory:') {
            @mkdir(dirname($path), 0777, true);
        }
        $pdo = new \PDO('sqlite:' . $path, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        // WAL + busy_timeout: several php -S workers share this file
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec(self::SCHEMA);
        return $pdo;
    }
}
