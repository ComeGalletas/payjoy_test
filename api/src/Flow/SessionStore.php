<?php

declare(strict_types=1);

namespace FxInstallments\Flow;

use Closure;
use FxInstallments\Db\Connection;
use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;

/**
 * The `sessions` table (docs/PLAN.md §5, §9): server-issued ids, a sliding TTL and the expiry
 * sweep. Portable SQL only (§8): no upserts, no date or JSON functions.
 *
 * - **Ids** are `bin2hex(random_bytes(16))`: 32 lowercase hex characters. Anything else is never
 *   looked up.
 * - **TTL.** Every saved message moves an open session's `expires_at` to `now + ttl`
 *   (`bot.session_ttl_minutes`). An open session is live while `now < expires_at`.
 * - **Sweep.** sweepExpired() closes open sessions with `expires_at <= now` as abandoned,
 *   keeping their step, in one transaction, and hands each one this call closed to the
 *   caller, so `bot.session_abandoned` is recorded exactly once even when two processes sweep
 *   at the same moment (SessionSweeper).
 * - **Concurrency.** save() is a compare-and-set: it updates the row only if it still holds
 *   the state the caller read. Every transition changes the step, the retry count or the
 *   status, so two messages that read the same state can't both be saved; the loser reads again.
 */
final class SessionStore
{
    public const ID_PATTERN = '/^[0-9a-f]{32}$/D';

    /** Expired sessions closed per sweep; the rest are closed by later sweeps. */
    public const SWEEP_LIMIT = 500;

    private const COLUMNS = 'session_id, channel, step, status, collected, retries, created_at, updated_at, expires_at';

    public readonly int $ttlSeconds;

    /**
     * @param int $ttlMinutes `bot.session_ttl_minutes`, at least 1
     */
    public function __construct(private readonly Connection $db, int $ttlMinutes)
    {
        if ($ttlMinutes < 1) {
            throw new InvalidArgumentException('bot.session_ttl_minutes must be at least 1');
        }
        $this->ttlSeconds = $ttlMinutes * 60;
    }

    public static function newId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function isWellFormedId(string $id): bool
    {
        return preg_match(self::ID_PATTERN, $id) === 1;
    }

    /**
     * Inserts a new open session at $step.
     */
    public function create(string $channel, string $step, int $now): Session
    {
        $session = new Session(self::newId(), $channel, $step, Session::OPEN, new Collected(), 0, $now, $now, $now + $this->ttlSeconds);
        $this->db->pdo()
            ->prepare('INSERT INTO sessions (' . self::COLUMNS . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $session->id, $session->channel, $session->step, $session->status, $session->collected->toJson(),
                $session->retries, $session->createdAt, $session->updatedAt, $session->expiresAt,
            ]);

        return $session;
    }

    /**
     * The session with this id in any status, or null when the id is malformed, unknown, or its
     * row can't be read back (a damaged `collected`).
     */
    public function find(string $id): ?Session
    {
        if (!self::isWellFormedId($id)) {
            return null;
        }
        $statement = $this->db->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM sessions WHERE session_id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::fromRow($row) : null;
    }

    /**
     * Writes $new over $old if the row is still open and holds $old's state. Returns false when
     * another request changed (or swept) the session first, or $old isn't open: a closed session
     * is never written again.
     */
    public function save(Session $old, Session $new): bool
    {
        if (!$old->isOpen() || $new->id !== $old->id) {
            return false;
        }
        $statement = $this->db->pdo()->prepare(
            'UPDATE sessions SET step = ?, status = ?, collected = ?, retries = ?, updated_at = ?, expires_at = ?'
            . " WHERE session_id = ? AND status = 'open' AND step = ? AND collected = ? AND retries = ? AND expires_at = ?",
        );
        $statement->execute([
            $new->step, $new->status, $new->collected->toJson(), $new->retries, $new->updatedAt, $new->expiresAt,
            $old->id, $old->step, $old->collected->toJson(), $old->retries, $old->expiresAt,
        ]);

        return $statement->rowCount() === 1;
    }

    /**
     * Closes up to SWEEP_LIMIT open sessions whose `expires_at <= now` as abandoned, calling
     * $onClosed for each one this call closed (as it was: open, at its last step), all in one
     * transaction: the select, every close and whatever $onClosed writes (the
     * `bot.session_abandoned` events) commit together, or none do.
     *
     * When another connection holds the write lock (SQLITE_BUSY or SQLITE_LOCKED, after the
     * busy timeout), the sweep is rolled back and returns []: sweeping is housekeeping, and the
     * next message or report sweeps again. Any other failure is rolled back and rethrown.
     *
     * @param Closure(Session): void $onClosed
     * @return list<Session> the sessions this call closed
     */
    public function sweepExpired(int $now, Closure $onClosed): array
    {
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $statement = $pdo->prepare(
                'SELECT ' . self::COLUMNS . " FROM sessions WHERE status = 'open' AND expires_at <= ? ORDER BY expires_at LIMIT " . self::SWEEP_LIMIT,
            );
            $statement->execute([$now]);
            $closed = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (!$this->closeExpired((string) $row['session_id'], $now)) {
                    continue;
                }
                // A damaged row is still closed; it is reported with nothing collected.
                $session = self::fromRow($row) ?? new Session(
                    (string) $row['session_id'],
                    (string) $row['channel'],
                    (string) $row['step'],
                    Session::OPEN,
                    new Collected(),
                    0,
                    (int) $row['created_at'],
                    (int) $row['updated_at'],
                    (int) $row['expires_at'],
                );
                $onClosed($session);
                $closed[] = $session;
            }
            if ($own) {
                $pdo->commit();
            }

            return $closed;
        } catch (PDOException $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($own && self::isBusy($e)) {
                return [];
            }
            throw $e;
        } catch (Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Closes one expired open session; false when it was no longer open and expired.
     */
    public function closeExpired(string $id, int $now): bool
    {
        $statement = $this->db->pdo()->prepare(
            "UPDATE sessions SET status = 'abandoned', updated_at = ? WHERE session_id = ? AND status = 'open' AND expires_at <= ?",
        );
        $statement->execute([$now, $id, $now]);

        return $statement->rowCount() === 1;
    }

    /**
     * SQLITE_BUSY (5) or SQLITE_LOCKED (6), extended codes included (BUSY_SNAPSHOT is 517).
     */
    private static function isBusy(PDOException $e): bool
    {
        $code = $e->errorInfo[1] ?? null;

        return is_int($code) && in_array($code & 0xFF, [5, 6], true);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function fromRow(array $row): ?Session
    {
        $collected = Collected::fromJson((string) $row['collected']);
        if ($collected === null) {
            return null;
        }

        return new Session(
            (string) $row['session_id'],
            (string) $row['channel'],
            (string) $row['step'],
            (string) $row['status'],
            $collected,
            (int) $row['retries'],
            (int) $row['created_at'],
            (int) $row['updated_at'],
            (int) $row['expires_at'],
        );
    }
}
