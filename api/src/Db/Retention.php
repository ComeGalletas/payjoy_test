<?php

declare(strict_types=1);

namespace FxInstallments\Db;

use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Retention for `php bin/migrate.php --prune` (docs/PLAN.md §5, ADR 0016). Deletes, as of $now:
 *
 * - rate_limits rows whose window started more than a day ago (window_start < now - 1 day);
 * - closed sessions (completed or abandoned) last updated more than 30 days ago
 *   (updated_at < now - 30 days); open sessions are left to the P1-06 expiry sweep;
 * - requests rows older than `requests.retention_days` (ts < now - days);
 * - ip_blocks whose expiry has passed (expires_at <= now: a block lasts until, not through,
 *   expires_at). Manual blocks with no expiry are kept.
 *
 * events are never deleted. Every DELETE is portable SQL on an indexed column or a small
 * table, with epoch arithmetic done in PHP (ADR 0010). All four run in one transaction.
 */
final class Retention
{
    public const DAY_SECONDS = 86_400;
    public const RATE_LIMIT_DAYS = 1;
    public const CLOSED_SESSION_DAYS = 30;

    public function __construct(private readonly PDO $pdo, private readonly int $requestRetentionDays)
    {
        if ($requestRetentionDays < 1) {
            throw new InvalidArgumentException('requests.retention_days must be at least 1');
        }
    }

    /**
     * @return array{rate_limits: int, sessions: int, requests: int, ip_blocks: int} rows deleted per table
     */
    public function prune(int $now): array
    {
        $statements = [
            'rate_limits' => [
                'DELETE FROM rate_limits WHERE window_start < ?',
                [$now - self::RATE_LIMIT_DAYS * self::DAY_SECONDS],
            ],
            'sessions' => [
                "DELETE FROM sessions WHERE status IN ('completed', 'abandoned') AND updated_at < ?",
                [$now - self::CLOSED_SESSION_DAYS * self::DAY_SECONDS],
            ],
            'requests' => [
                'DELETE FROM requests WHERE ts < ?',
                [$now - $this->requestRetentionDays * self::DAY_SECONDS],
            ],
            'ip_blocks' => [
                'DELETE FROM ip_blocks WHERE expires_at IS NOT NULL AND expires_at <= ?',
                [$now],
            ],
        ];

        $counts = [];
        $this->pdo->beginTransaction();
        try {
            foreach ($statements as $table => [$sql, $params]) {
                $delete = $this->pdo->prepare($sql);
                $delete->execute($params);
                $counts[$table] = $delete->rowCount();
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        /** @var array{rate_limits: int, sessions: int, requests: int, ip_blocks: int} $counts */
        return $counts;
    }
}
