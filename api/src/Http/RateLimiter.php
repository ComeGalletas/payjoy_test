<?php

declare(strict_types=1);

namespace FxInstallments\Http;

use Closure;
use FxInstallments\Db\Connection;
use FxInstallments\Metrics\ErrorCodes;
use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;

/**
 * Per-IP limit of `ratelimit.per_minute` requests per fixed one-minute UTC window, counted in
 * the `rate_limits` table. The request over the limit gets 429 RATE_LIMITED with Retry-After.
 * Attach it per route (it will wrap `/convert` and `/bot/message`).
 *
 * The count is written before it is read, inside one transaction: the UPDATE takes SQLite's
 * write lock up front (no lock upgrade, so busy_timeout applies), and the SQL stays portable
 * (ADR 0010). A concurrent first INSERT for the same key is retried once as an UPDATE; any
 * other constraint failure is not (isUniqueViolation()).
 *
 * The request's time is the Kernel's one clock reading (Request::receivedAt(), L-04), so a
 * 429 is counted in the minute the Kernel's block check reads; a request that didn't come
 * through the Kernel uses this limiter's own clock.
 */
final class RateLimiter implements Middleware
{
    public const WINDOW_SECONDS = 60;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param (Closure(): int)|null $clock UTC epoch seconds; defaults to time()
     */
    public function __construct(
        private readonly Connection $db,
        private readonly int $perMinute,
        private readonly ?string $trustedProxy = null,
        ?Closure $clock = null,
    ) {
        if ($perMinute < 1) {
            throw new InvalidArgumentException('ratelimit.per_minute must be at least 1');
        }
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * The start of the fixed one-minute UTC window holding $now.
     */
    public static function windowStart(int $now): int
    {
        return intdiv($now, self::WINDOW_SECONDS) * self::WINDOW_SECONDS;
    }

    /**
     * The number of $ip's windows starting in [$fromWindowStart, $toWindowStart] in which it
     * got at least one 429: automatic blocking's "rate-limited minutes" (IpBlocking).
     *
     * process() counts every request it sees, the ones it refuses included, so a window's
     * count is over the limit exactly when that window had a 429; and only this class answers
     * 429. One primary-key range read of at most one row per window, whatever the IP's
     * traffic. Throws on a database failure.
     */
    public function rateLimitedWindows(string $ip, int $fromWindowStart, int $toWindowStart): int
    {
        $select = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM rate_limits WHERE ip = ? AND window_start >= ? AND window_start <= ? AND count > ?',
        );
        $select->execute([$ip, $fromWindowStart, $toWindowStart, $this->perMinute]);

        return (int) $select->fetchColumn();
    }

    public function process(Request $request, callable $next): Response
    {
        $now = $request->receivedAt() ?? ($this->clock)();
        $windowStart = self::windowStart($now);
        $count = $this->increment(ClientIp::resolve($request, $this->trustedProxy), $windowStart);

        if ($count > $this->perMinute) {
            return JsonResponse::error(ErrorCodes::RATE_LIMITED)
                ->withHeader('Retry-After', (string) max(1, $windowStart + self::WINDOW_SECONDS - $now));
        }

        return $next($request);
    }

    /**
     * Adds one request for $ip in the window and returns the new count.
     */
    private function increment(string $ip, int $windowStart): int
    {
        $pdo = $this->db->pdo();
        try {
            return $this->incrementOnce($pdo, $ip, $windowStart);
        } catch (PDOException $e) {
            // A parallel request inserted the same (ip, window_start) first: count again.
            if (!self::isUniqueViolation($e)) {
                throw $e;
            }

            return $this->incrementOnce($pdo, $ip, $windowStart);
        }
    }

    private function incrementOnce(PDO $pdo, string $ip, int $windowStart): int
    {
        $pdo->beginTransaction();
        try {
            $update = $pdo->prepare('UPDATE rate_limits SET count = count + 1 WHERE ip = ? AND window_start = ?');
            $update->execute([$ip, $windowStart]);
            if ($update->rowCount() === 0) {
                $pdo->prepare('INSERT INTO rate_limits (ip, window_start, count) VALUES (?, ?, 1)')
                    ->execute([$ip, $windowStart]);
            }
            $select = $pdo->prepare('SELECT count FROM rate_limits WHERE ip = ? AND window_start = ?');
            $select->execute([$ip, $windowStart]);
            $count = (int) $select->fetchColumn();
            $pdo->commit();

            return $count;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Only a real unique-constraint clash on the key (L-04). SQLSTATE 23000 also covers CHECK,
     * NOT NULL, foreign-key and trigger failures, which a second attempt can't fix, so for it
     * the driver error decides: SQLite's SQLITE_CONSTRAINT (19) with its "UNIQUE constraint
     * failed" text (or the extended UNIQUE / PRIMARYKEY codes 2067 / 1555, and the old
     * "PRIMARY KEY must be unique" text), or MySQL's ER_DUP_ENTRY (1062). Postgres reports
     * unique_violation as its own SQLSTATE, 23505.
     */
    private static function isUniqueViolation(PDOException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        if ($sqlState === '23505') {
            return true;
        }
        if ($sqlState !== '23000') {
            return false;
        }
        $driverCode = $e->errorInfo[1] ?? null;
        $message = (string) ($e->errorInfo[2] ?? '');
        if ($driverCode === 2067 || $driverCode === 1555) {
            return true;
        }
        if ($driverCode === 19) {
            return preg_match('/^(UNIQUE constraint failed:|PRIMARY KEY must be unique)/', $message) === 1;
        }

        return $driverCode === 1062;
    }
}
