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
 * (ADR 0010). A concurrent first INSERT for the same key is retried once as an UPDATE.
 */
final class RateLimiter implements Middleware
{
    private const WINDOW_SECONDS = 60;

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

    public function process(Request $request, callable $next): JsonResponse
    {
        $now = ($this->clock)();
        $windowStart = intdiv($now, self::WINDOW_SECONDS) * self::WINDOW_SECONDS;
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

    private static function isUniqueViolation(PDOException $e): bool
    {
        // SQLSTATE 23000 (SQLite, MySQL) or 23505 (Postgres).
        return in_array((string) $e->getCode(), ['23000', '23505'], true);
    }
}
