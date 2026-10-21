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
 * The breaker (docs/PLAN.md §3b, ADR 0012): stops upstream calls after an error that more
 * calls can't fix. Its state is one `app_state` row, STATE_KEY, holding a BreakerState.
 *
 * - `quota-reached` → blocked until 00:00 UTC on the first day of the next month;
 * - `invalid-key`, `inactive-account` → blocked until `php api/bin/upstream.php reset`;
 * - any other failed call (timeout, connect error, malformed answer) → blocked for
 *   BACKOFF_SECONDS, so quota use during an outage depends on time, not traffic;
 * - `unsupported-code` and a missing key (no call was made) don't trip it.
 *
 * A trip never replaces a stronger open one (auth > quota > backoff), so a slow request that
 * times out can't shorten an auth or quota block another request has just set. Writes are
 * select-then-write in a transaction (ADR 0010).
 */
final class UpstreamBreaker
{
    public const STATE_KEY = 'upstream_breaker';
    public const BACKOFF_SECONDS = 300;

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
     * Records a failed call. Returns the trip now in force, or null when this error doesn't
     * trip the breaker.
     */
    public function trip(UpstreamError $error): ?BreakerState
    {
        $now = ($this->clock)();
        $kind = self::kindFor($error);
        if ($kind === null) {
            return null;
        }
        $new = new BreakerState(
            $kind,
            $error->errorCode,
            $now,
            match ($kind) {
                BreakerState::QUOTA => self::nextMonthStart($now),
                BreakerState::AUTH => null,
                default => $now + self::BACKOFF_SECONDS,
            },
        );

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $current = $this->state();
            if ($current !== null && $current->isOpenAt($now)
                && self::STRENGTH[$current->kind] > self::STRENGTH[$new->kind]) {
                $pdo->commit();

                return $current;
            }
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
     * Clears any trip. Returns the trip that was stored (open or not), or null.
     */
    public function reset(): ?BreakerState
    {
        $state = $this->state();
        $this->db->pdo()->prepare('DELETE FROM app_state WHERE key = ?')->execute([self::STATE_KEY]);

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
            ErrorCodes::UPSTREAM_TIMEOUT, ErrorCodes::UPSTREAM_MALFORMED => BreakerState::BACKOFF,
            default => null,
        };
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
