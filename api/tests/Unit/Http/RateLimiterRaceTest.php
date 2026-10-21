<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Http;

use Closure;
use FxInstallments\Db\Connection;
use FxInstallments\Db\Database;
use FxInstallments\Http\JsonResponse;
use FxInstallments\Http\RateLimiter;
use FxInstallments\Http\Request;
use FxInstallments\Tests\Support\FakeClock;
use FxInstallments\Tests\Support\RacingPdo;
use FxInstallments\Tests\Support\TestEnvironment;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Two connections to one SQLite file racing on the first request of a window: the
 * unique-clash retry path in RateLimiter::increment().
 *
 * On SQLite the clash can't happen by timing alone: the UPDATE that finds no row already takes
 * the database write lock, so the other connection waits (busy_timeout) until the first one
 * commits, and then its own UPDATE finds the row (testOnSqliteTheFirstUpdateAlreadyHoldsTheWriteLock
 * shows this). The clash is real on Postgres under READ COMMITTED, where an UPDATE doesn't see a
 * row another transaction inserts but hasn't committed yet, and the later INSERT hits the
 * primary key.
 *
 * So the race is forced through a seam instead of timing, with no change to RateLimiter: the
 * seam is the Connection it is given. Connection A is a PDO subclass (Support/RacingPdo) whose
 * first UPDATE on rate_limits first lets connection B run a whole request (B inserts the row and
 * commits), then runs as `... AND 1 = 0`, matching nothing — what A's UPDATE sees on Postgres
 * when it started before B's commit. Everything after that is real: A's INSERT fails on the
 * real primary key with SQLSTATE 23000, RateLimiter rolls back and counts again, and the count
 * comes out right.
 */
final class RateLimiterRaceTest extends TestCase
{
    private const MINUTE = 1_790_000_040;
    private const IP = '203.0.113.9';

    public function testBothRequestsAreCountedWhenTheFirstInsertClashes(): void
    {
        $path = TestEnvironment::freshDatabasePath();
        $b = $this->limiter(Database::connect('sqlite:' . $path), 2);
        $bResponse = null;
        $a = RacingPdo::open($path, function () use ($b, &$bResponse): void {
            $bResponse = self::hit($b);
        });

        $aResponse = self::hit($this->limiter($a, 2));

        // B ran inside A's request, before A's INSERT, and was the first in the window.
        self::assertSame(200, $bResponse?->status());
        // A went through the clash: stale UPDATE, INSERT failing on the primary key, then a
        // fresh attempt whose UPDATE finds B's row.
        self::assertSame(
            ['UPDATE (stale)', 'INSERT', 'UPDATE', 'SELECT'],
            $a->statements,
        );
        self::assertSame(['23000'], $a->failures);
        self::assertSame(200, $aResponse->status());
        self::assertSame(
            [['ip' => self::IP, 'window_start' => self::MINUTE, 'count' => 2]],
            TestEnvironment::connect($path)->query('SELECT ip, window_start, count FROM rate_limits')->fetchAll(),
        );
        self::assertFalse($a->inTransaction());
    }

    public function testTheRetriedCountStillEnforcesTheLimit(): void
    {
        $path = TestEnvironment::freshDatabasePath();
        $b = $this->limiter(Database::connect('sqlite:' . $path), 1);
        $a = RacingPdo::open($path, static function () use ($b): void {
            self::hit($b);
        });

        // With a limit of 1, B took the window's only slot, so A (the second request) is limited.
        $response = self::hit($this->limiter($a, 1));

        self::assertSame(429, $response->status());
        self::assertSame('RATE_LIMITED', $response->errorCode());
        self::assertSame(['23000'], $a->failures);
        self::assertSame(2, (int) TestEnvironment::connect($path)->query('SELECT count FROM rate_limits')->fetchColumn());
    }

    public function testOnSqliteTheFirstUpdateAlreadyHoldsTheWriteLock(): void
    {
        $path = TestEnvironment::freshDatabasePath();
        $a = Database::connect('sqlite:' . $path);
        $b = Database::connect('sqlite:' . $path);
        $b->exec('PRAGMA busy_timeout = 0');

        $a->beginTransaction();
        $update = $a->prepare('UPDATE rate_limits SET count = count + 1 WHERE ip = ? AND window_start = ?');
        $update->execute([self::IP, self::MINUTE]);
        self::assertSame(0, $update->rowCount());

        try {
            $b->prepare('INSERT INTO rate_limits (ip, window_start, count) VALUES (?, ?, 1)')->execute([self::IP, self::MINUTE]);
            self::fail('B should not be able to write while A holds the write lock');
        } catch (PDOException $e) {
            self::assertStringContainsString('database is locked', $e->getMessage());
        } finally {
            $a->rollBack();
        }
    }

    private function limiter(PDO $pdo, int $perMinute): RateLimiter
    {
        return new RateLimiter(Connection::wrap($pdo), $perMinute, null, Closure::fromCallable(new FakeClock(self::MINUTE)));
    }

    private static function hit(RateLimiter $limiter): JsonResponse
    {
        return $limiter->process(
            new Request('GET', '/convert', [], [], '', self::IP),
            static fn (): JsonResponse => JsonResponse::ok(),
        );
    }
}
