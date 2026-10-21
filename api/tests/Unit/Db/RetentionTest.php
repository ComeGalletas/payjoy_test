<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Db;

use FxInstallments\Db\Retention;
use FxInstallments\Tests\Support\TestEnvironment;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The exact retention boundaries, with a fixed "now". The acceptance test runs the same rules
 * through `bin/migrate.php --prune`.
 */
final class RetentionTest extends TestCase
{
    private const NOW = 1_790_000_000;
    private const DAY = 86_400;

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = TestEnvironment::connect(TestEnvironment::freshDatabasePath());
    }

    public function testRateLimitWindowsOlderThanADayGo(): void
    {
        $insert = $this->pdo->prepare('INSERT INTO rate_limits (ip, window_start, count) VALUES (?, ?, 1)');
        $insert->execute(['older', self::NOW - self::DAY - 1]);
        $insert->execute(['exactly-a-day', self::NOW - self::DAY]);

        self::assertSame(1, $this->prune()['rate_limits']);
        self::assertSame(['exactly-a-day'], $this->column('SELECT ip FROM rate_limits'));
    }

    public function testClosedSessionsOlderThan30DaysGo(): void
    {
        $insert = $this->pdo->prepare(
            "INSERT INTO sessions (session_id, channel, step, status, created_at, updated_at, expires_at) VALUES (?, 'static', 'result', ?, 0, ?, ?)",
        );
        $boundary = self::NOW - 30 * self::DAY;
        $insert->execute(['completed-older', 'completed', $boundary - 1, $boundary]);
        $insert->execute(['abandoned-older', 'abandoned', $boundary - 1, $boundary]);
        $insert->execute(['completed-at', 'completed', $boundary, $boundary]);
        $insert->execute(['abandoned-at', 'abandoned', $boundary, $boundary]);
        $insert->execute(['open-older', 'open', $boundary - 1, $boundary]);

        self::assertSame(2, $this->prune()['sessions']);
        self::assertSame(['abandoned-at', 'completed-at', 'open-older'], $this->column('SELECT session_id FROM sessions ORDER BY session_id'));
    }

    public function testRequestsOlderThanTheRetentionGo(): void
    {
        $insert = $this->pdo->prepare("INSERT INTO requests (ts, ip, method, path, status, blocked) VALUES (?, ?, 'GET', '/', 404, 0)");
        $insert->execute([self::NOW - 7 * self::DAY - 1, 'older']);
        $insert->execute([self::NOW - 7 * self::DAY, 'at']);

        self::assertSame(1, $this->prune(7)['requests']);
        self::assertSame(['at'], $this->column('SELECT ip FROM requests'));
    }

    public function testBlocksGoOnceTheirExpiryIsReachedAndPermanentOnesStay(): void
    {
        $insert = $this->pdo->prepare('INSERT INTO ip_blocks (ip, reason, source, blocked_at, expires_at) VALUES (?, ?, ?, 0, ?)');
        $insert->execute(['auto-at', 'r', 'auto', self::NOW]);
        $insert->execute(['manual-before', 'r', 'manual', self::NOW - 1]);
        $insert->execute(['auto-after', 'r', 'auto', self::NOW + 1]);
        $insert->execute(['manual-forever', 'r', 'manual', null]);

        self::assertSame(2, $this->prune()['ip_blocks']);
        self::assertSame(['auto-after', 'manual-forever'], $this->column('SELECT ip FROM ip_blocks ORDER BY ip'));
    }

    public function testEventsAreNeverPrunedAndCountsCoverEveryTable(): void
    {
        $this->pdo->exec("INSERT INTO events (ts, source, event_type, status) VALUES (0, 'api', 'api.request', 'ok')");

        self::assertSame(['rate_limits' => 0, 'sessions' => 0, 'requests' => 0, 'ip_blocks' => 0], $this->prune());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM events')->fetchColumn());
    }

    public function testTheRetentionMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Retention($this->pdo, 0);
    }

    /**
     * @return array<string, int>
     */
    private function prune(int $retentionDays = 30): array
    {
        return (new Retention($this->pdo, $retentionDays))->prune(self::NOW);
    }

    /**
     * @return list<mixed>
     */
    private function column(string $sql): array
    {
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    }
}
