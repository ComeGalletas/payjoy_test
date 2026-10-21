<?php

declare(strict_types=1);

namespace FxInstallments\Http;

use Closure;
use FxInstallments\Db\Connection;
use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;

/**
 * The `ip_blocks` table (ADR 0016 and its amendment). `ip` is the primary key, so there is at
 * most one row per IP, and every write is select-then-write in one transaction (ADR 0010):
 *
 * - blocking an IP with no row, or whose row has expired, writes a new row;
 * - a manual block replaces any row, automatic or manual, active or not (the operator's latest
 *   command wins);
 * - an automatic block never replaces an active manual block;
 * - an automatic block on an active automatic one extends its `expires_at` to the later of the
 *   two (its `blocked_at` and reason stay);
 * - unblocking ends an active block now (`expires_at = now`) instead of deleting the row, so
 *   the requests before it never count toward the next automatic block (IpBlocking) and
 *   `--prune` removes it like any expired block.
 *
 * Each write transaction starts with a no-op UPDATE on the IP's row, which takes SQLite's write
 * lock up front (no lock upgrade, so busy_timeout applies), as RateLimiter does. A concurrent
 * first INSERT for the same IP (possible on Postgres) is retried once.
 */
final class IpBlocks
{
    public const MAX_IP = 64;
    public const MAX_REASON = 200;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * The row for $ip, active or expired, or null when there is none.
     */
    public function find(string $ip): ?IpBlock
    {
        return self::select($this->db->pdo(), $ip);
    }

    /**
     * Every block active at $now, oldest first.
     *
     * @return list<IpBlock>
     */
    public function active(int $now): array
    {
        $select = $this->db->pdo()->prepare(
            'SELECT ip, reason, source, blocked_at, expires_at FROM ip_blocks'
            . ' WHERE expires_at IS NULL OR expires_at > ? ORDER BY blocked_at, ip',
        );
        $select->execute([$now]);

        return array_map(self::fromRow(...), $select->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Blocks $ip automatically from $now until $expiresAt. Returns the row as it now stands:
     * an active manual block is returned unchanged.
     */
    public function blockAutomatically(string $ip, string $reason, int $now, int $expiresAt): IpBlock
    {
        self::checkIpAndReason($ip, $reason);
        if ($expiresAt <= $now) {
            throw new InvalidArgumentException('An automatic block must expire after it starts');
        }

        return $this->write($ip, static function (PDO $pdo, ?IpBlock $existing) use ($ip, $reason, $now, $expiresAt): IpBlock {
            if ($existing !== null && $existing->isActiveAt($now)) {
                if ($existing->isManual()) {
                    return $existing;
                }
                $later = max((int) $existing->expiresAt, $expiresAt);
                if ($later !== $existing->expiresAt) {
                    $pdo->prepare('UPDATE ip_blocks SET expires_at = ? WHERE ip = ?')->execute([$later, $ip]);
                }

                return new IpBlock($ip, $existing->reason, IpBlock::AUTO, $existing->blockedAt, $later);
            }

            return self::put($pdo, $existing, new IpBlock($ip, $reason, IpBlock::AUTO, $now, $expiresAt));
        });
    }

    /**
     * Blocks $ip manually from $now until $expiresAt, or until unblocked when it is null.
     * Replaces whatever row $ip had.
     */
    public function blockManually(string $ip, string $reason, int $now, ?int $expiresAt): IpBlock
    {
        self::checkIpAndReason($ip, $reason);
        if ($expiresAt !== null && $expiresAt <= $now) {
            throw new InvalidArgumentException('A block must expire after it starts');
        }

        return $this->write($ip, static fn (PDO $pdo, ?IpBlock $existing): IpBlock => self::put(
            $pdo,
            $existing,
            new IpBlock($ip, $reason, IpBlock::MANUAL, $now, $expiresAt),
        ));
    }

    /**
     * Ends $ip's active block at $now. Returns the block that was lifted, or null when $ip had
     * no active block (nothing is written then).
     */
    public function unblock(string $ip, int $now): ?IpBlock
    {
        return $this->write($ip, static function (PDO $pdo, ?IpBlock $existing) use ($ip, $now): ?IpBlock {
            if ($existing === null || !$existing->isActiveAt($now)) {
                return null;
            }
            $pdo->prepare('UPDATE ip_blocks SET expires_at = ? WHERE ip = ?')->execute([$now, $ip]);

            return $existing;
        });
    }

    /**
     * @template T
     * @param Closure(PDO, ?IpBlock): T $work
     * @return T
     */
    private function write(string $ip, Closure $work): mixed
    {
        $pdo = $this->db->pdo();
        try {
            return self::writeOnce($pdo, $ip, $work);
        } catch (PDOException $e) {
            // A parallel request inserted a row for the same IP first: decide again.
            if (!in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                throw $e;
            }

            return self::writeOnce($pdo, $ip, $work);
        }
    }

    /**
     * @template T
     * @param Closure(PDO, ?IpBlock): T $work
     * @return T
     */
    private static function writeOnce(PDO $pdo, string $ip, Closure $work): mixed
    {
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE ip_blocks SET reason = reason WHERE ip = ?')->execute([$ip]);
            $result = $work($pdo, self::select($pdo, $ip));
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function put(PDO $pdo, ?IpBlock $existing, IpBlock $block): IpBlock
    {
        $values = [$block->reason, $block->source, $block->blockedAt, $block->expiresAt, $block->ip];
        $pdo->prepare($existing === null
            ? 'INSERT INTO ip_blocks (reason, source, blocked_at, expires_at, ip) VALUES (?, ?, ?, ?, ?)'
            : 'UPDATE ip_blocks SET reason = ?, source = ?, blocked_at = ?, expires_at = ? WHERE ip = ?')
            ->execute($values);

        return $block;
    }

    private static function select(PDO $pdo, string $ip): ?IpBlock
    {
        $select = $pdo->prepare('SELECT ip, reason, source, blocked_at, expires_at FROM ip_blocks WHERE ip = ?');
        $select->execute([$ip]);
        $row = $select->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::fromRow($row) : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function fromRow(array $row): IpBlock
    {
        return new IpBlock(
            (string) $row['ip'],
            (string) $row['reason'],
            (string) $row['source'],
            (int) $row['blocked_at'],
            $row['expires_at'] === null ? null : (int) $row['expires_at'],
        );
    }

    private static function checkIpAndReason(string $ip, string $reason): void
    {
        if ($ip === '' || strlen($ip) > self::MAX_IP) {
            throw new InvalidArgumentException('A block needs an IP of 1 to ' . self::MAX_IP . ' characters');
        }
        if ($reason === '' || strlen($reason) > self::MAX_REASON || preg_match('/[\x00-\x1F\x7F]/', $reason) === 1) {
            throw new InvalidArgumentException('A block reason must be 1 to ' . self::MAX_REASON . ' characters with no control characters');
        }
    }
}
