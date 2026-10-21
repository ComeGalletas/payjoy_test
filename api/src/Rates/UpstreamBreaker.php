<?php

declare(strict_types=1);

namespace FxInstallments\Rates;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use FxInstallments\Db\Connection;
use FxInstallments\Metrics\ErrorCodes;
use PDOException;
use Throwable;

/**
 * The breaker (docs/PLAN.md §3b, ADR 0012): stops upstream calls after a failed refresh, so
 * quota use is bounded by time, never by traffic. Its state is one `app_state` row,
 * STATE_KEY, holding a BreakerState.
 *
 * - `quota-reached` → blocked until 00:00 UTC on the first day of the next month;
 * - `invalid-key`, `inactive-account` → blocked until `php api/bin/upstream.php reset`;
 * - every other failed refresh (timeout, connect error, malformed answer, `unsupported-code`
 *   on `/latest/USD`, a `rate_cache` write that failed) → a backoff that grows with each
 *   consecutive failure: 1, 5, 10, 20, 40, then 60 minutes at most (owner decisions
 *   2026-10-21). A successful refresh (succeeded()) clears it, so the next failure waits
 *   1 minute again;
 * - a missing key (`no_key`) doesn't trip it: no call was made.
 *
 * A trip never replaces an open one of equal or greater strength (auth > quota > backoff), so
 * a slow request that fails late can't shorten an auth or quota block, or count one outage
 * twice. Writes are select-then-write in a transaction (ADR 0010).
 */
final class UpstreamBreaker
{
    public const STATE_KEY = 'upstream_breaker';

    /**
     * The backoff after the 1st, 2nd, … consecutive failed refresh; from the last entry on,
     * every further failure waits MAX_BACKOFF_SECONDS.
     */
    public const BACKOFF_SCHEDULE = [60, 300, 600, 1200, 2400, 3600];

    /** The first backoff, after one failed refresh. */
    public const BACKOFF_SECONDS = self::BACKOFF_SCHEDULE[0];

    /** The longest backoff, from the sixth consecutive failure on. */
    public const MAX_BACKOFF_SECONDS = 3600;

    private const STRENGTH = [BreakerState::BACKOFF => 1, BreakerState::QUOTA => 2, BreakerState::AUTH => 3];

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param (Closure(): int)|null $clock UTC epoch seconds; defaults to time()
     */
    public function __construct(private readonly Connection $db, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * The stored trip, open or expired; null when there is none or the row isn't valid.
     */
    public function state(): ?BreakerState
    {
        $value = $this->storedValue();

        return $value === null ? null : BreakerState::fromJson($value);
    }

    /**
     * The trip that blocks calls now, or null when calls may go out.
     */
    public function blocking(): ?BreakerState
    {
        $state = $this->state();

        return $state !== null && $state->isOpenAt(($this->clock)()) ? $state : null;
    }

    /**
     * Records a failed refresh. Returns the trip now in force, or null when this error doesn't
     * trip the breaker.
     */
    public function trip(UpstreamError $error): ?BreakerState
    {
        $now = ($this->clock)();
        $kind = self::kindFor($error);
        if ($kind === null) {
            return null;
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $current = $this->state();
            if ($current !== null && $current->isOpenAt($now)
                && self::STRENGTH[$current->kind] >= self::STRENGTH[$kind]) {
                $pdo->commit();

                return $current;
            }
            $failures = $kind === BreakerState::BACKOFF
                ? ($current?->kind === BreakerState::BACKOFF ? $current->failures + 1 : 1)
                : 0;
            $new = new BreakerState(
                $kind,
                $error->errorCode,
                $now,
                match ($kind) {
                    BreakerState::QUOTA => self::nextMonthStart($now),
                    BreakerState::AUTH => null,
                    default => $now + self::backoffSeconds($failures),
                },
                $failures,
            );
            $this->write($new->toJson(), $now);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $new;
    }

    /**
     * Records a successful refresh: clears a backoff (open or not) or an expired trip, so the
     * next failure starts the backoff at one minute again. An open quota or auth block is
     * left alone (no refresh can succeed while it blocks; another process may just have set
     * it). Writes nothing when there is nothing to clear.
     */
    public function succeeded(): void
    {
        if ($this->storedValue() === null) {
            return;
        }
        $state = $this->state();
        if ($state !== null && $state->kind !== BreakerState::BACKOFF && $state->isOpenAt(($this->clock)())) {
            return;
        }
        $this->delete();
    }

    /**
     * Clears any trip. Returns the trip that was stored (open or not), or null.
     */
    public function reset(): ?BreakerState
    {
        $state = $this->state();
        $this->delete();

        return $state;
    }

    /**
     * Which trip an error starts, or null for none.
     */
    public static function kindFor(UpstreamError $error): ?string
    {
        if ($error->reason === 'no_key') {
            return null;
        }

        return match ($error->errorCode) {
            ErrorCodes::UPSTREAM_QUOTA => BreakerState::QUOTA,
            ErrorCodes::UPSTREAM_AUTH => BreakerState::AUTH,
            default => BreakerState::BACKOFF,
        };
    }

    /**
     * The backoff after $failures consecutive failed refreshes: BACKOFF_SCHEDULE[failures − 1]
     * seconds, then MAX_BACKOFF_SECONDS once the schedule runs out.
     */
    public static function backoffSeconds(int $failures): int
    {
        $failures = max(1, $failures);

        return self::BACKOFF_SCHEDULE[$failures - 1] ?? self::MAX_BACKOFF_SECONDS;
    }

    /**
     * 00:00:00 UTC on the first day of the month after the one $now falls in.
     */
    public static function nextMonthStart(int $now): int
    {
        $utc = new DateTimeZone('UTC');
        $date = (new DateTimeImmutable('@' . $now))->setTimezone($utc);
        $year = (int) $date->format('Y');
        $month = (int) $date->format('n') + 1;
        if ($month === 13) {
            $month = 1;
            $year++;
        }

        return (new DateTimeImmutable('now', $utc))->setDate($year, $month, 1)->setTime(0, 0)->getTimestamp();
    }

    private function storedValue(): ?string
    {
        $select = $this->db->pdo()->prepare('SELECT value FROM app_state WHERE key = ?');
        $select->execute([self::STATE_KEY]);
        $value = $select->fetchColumn();

        return is_string($value) ? $value : null;
    }

    private function delete(): void
    {
        $this->db->pdo()->prepare('DELETE FROM app_state WHERE key = ?')->execute([self::STATE_KEY]);
    }

    private function write(string $value, int $now): void
    {
        $pdo = $this->db->pdo();
        $update = $pdo->prepare('UPDATE app_state SET value = ?, updated_at = ? WHERE key = ?');
        $update->execute([$value, $now, self::STATE_KEY]);
        if ($update->rowCount() > 0) {
            return;
        }
        try {
            $pdo->prepare('INSERT INTO app_state (key, value, updated_at) VALUES (?, ?, ?)')
                ->execute([self::STATE_KEY, $value, $now]);
        } catch (PDOException) {
            // Another process inserted the row first: overwrite it.
            $update->execute([$value, $now, self::STATE_KEY]);
        }
    }
}
