<?php
declare(strict_types=1);

namespace DailyPass;

/**
 * All persistence/query logic. Every money-relevant mutation is journaled
 * into charge_audit.
 */
final class Store
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function getDb(): \PDO
    {
        return $this->pdo;
    }

    /* ---------------- subscribers ---------------- */

    /** @return array<string, mixed>|null */
    public function getSubscriber(string $ref): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM subscribers WHERE subscriber_ref = ?');
        $st->execute([$ref]);
        return $st->fetch() ?: null;
    }

    /** @param string[] $statuses @return list<array<string, mixed>> */
    public function listSubscribers(array $statuses): array
    {
        $ph = implode(',', array_fill(0, count($statuses), '?'));
        $st = $this->pdo->prepare("SELECT * FROM subscribers WHERE status IN ($ph) ORDER BY subscriber_ref");
        $st->execute(array_values($statuses));
        return $st->fetchAll();
    }

    public function upsertSubscriber(string $ref, string $status): void
    {
        $now = Iso::now();
        $st = $this->pdo->prepare(
            'INSERT INTO subscribers (subscriber_ref, status, created_at, updated_at) VALUES (?, ?, ?, ?)
             ON CONFLICT(subscriber_ref) DO UPDATE SET status = excluded.status, updated_at = excluded.updated_at');
        $st->execute([$ref, $status, $now, $now]);
    }

    public function setSubscriberStatus(string $ref, string $status): void
    {
        $st = $this->pdo->prepare('UPDATE subscribers SET status = ?, updated_at = ? WHERE subscriber_ref = ?');
        $st->execute([$status, Iso::now(), $ref]);
    }

    /** Returns true only for the call that actually changed the state (idempotent transitions). */
    public function setSubscriberStatusIfNot(string $ref, string $status, string $exceptStatus): bool
    {
        $st = $this->pdo->prepare('UPDATE subscribers SET status = ?, updated_at = ? WHERE subscriber_ref = ? AND status != ?');
        $st->execute([$status, Iso::now(), $ref, $exceptStatus]);
        return $st->rowCount() > 0;
    }

    /* ---------------- charges (money ledger) ---------------- */

    /** @return array<string, mixed>|null */
    public function getCharge(string $ref, string $day): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM charges WHERE subscriber_ref = ? AND billing_day = ?');
        $st->execute([$ref, $day]);
        return $st->fetch() ?: null;
    }

    /**
     * Reserve the billing day. Returns [row, created] — exactly one caller
     * ever gets created=true for a (subscriber, day) pair, even under races
     * (enforced by the UNIQUE index; INSERT OR IGNORE is atomic).
     *
     * @return array{0: array<string, mixed>, 1: bool}
     */
    public function insertChargeReservation(string $ref, string $day, string $extId, string $amount, string $currency): array
    {
        $now = Iso::now();
        $st = $this->pdo->prepare(
            'INSERT INTO charges (subscriber_ref, billing_day, external_trx_id, amount, currency, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, \'PENDING\', ?, ?) ON CONFLICT(subscriber_ref, billing_day) DO NOTHING');
        $st->execute([$ref, $day, $extId, $amount, $currency, $now, $now]);
        $created = $st->rowCount() > 0;
        return [$this->getCharge($ref, $day), $created];
    }

    /**
     * Update a charge row (explicit whitelist) and write an audit entry.
     *
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public function updateCharge(int $id, array $patch, ?string $platformCode = null, ?string $note = null): array
    {
        $allowed = ['status', 'attempt_count', 'last_status_code', 'reference_id', 'internal_trx_id', 'next_retry_at', 'reconciled'];
        $st = $this->pdo->prepare('SELECT * FROM charges WHERE id = ?');
        $st->execute([$id]);
        $cur = $st->fetch();
        if (!$cur) {
            throw new \RuntimeException("charge $id not found");
        }
        $sets = [];
        $vals = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $patch)) {
                $sets[] = "$k = ?";
                $vals[] = $patch[$k];
            }
        }
        $sets[] = 'updated_at = ?';
        $vals[] = Iso::now();
        $vals[] = $id;
        $this->pdo->prepare('UPDATE charges SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);

        $st2 = $this->pdo->prepare('SELECT * FROM charges WHERE id = ?');
        $st2->execute([$id]);
        $next = $st2->fetch();

        $this->pdo->prepare(
            'INSERT INTO charge_audit (charge_id, external_trx_id, from_status, to_status, platform_code, note, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $cur['external_trx_id'], $cur['status'], $next['status'], $platformCode, $note, Iso::now()]);

        return $next;
    }

    /** @return list<array<string, mixed>> */
    public function listCharges(?string $ref, ?string $day): array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM charges WHERE (?1 IS NULL OR subscriber_ref = ?1) AND (?2 IS NULL OR billing_day = ?2)
             ORDER BY id DESC LIMIT 500');
        $st->execute([$ref, $day]);
        return $st->fetchAll();
    }

    /* ---------------- webhook events (idempotency) ---------------- */

    /** Returns true if this event is new; false = duplicate delivery (already processed). */
    public function recordEvent(string $dedupKey, string $type, ?string $ref, array $payload): bool
    {
        $st = $this->pdo->prepare(
            'INSERT INTO webhook_events (dedup_key, event_type, subscriber_ref, payload, received_at)
             VALUES (?, ?, ?, ?, ?) ON CONFLICT(dedup_key) DO NOTHING');
        $st->execute([$dedupKey, $type, $ref, json_encode($payload, JSON_UNESCAPED_SLASHES), Iso::now()]);
        return $st->rowCount() > 0;
    }
}
