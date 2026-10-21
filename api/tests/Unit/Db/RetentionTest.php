<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Db;

use FxInstallments\Db\Retention;
use FxInstallments\Http\IpBlocking;
use FxInstallments\Tests\Support\TestEnvironment;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * An ended block is kept one block.window_minutes past its expiry (owner decision,
     * 2026-10-21), so IpBlocking's clean slate survives a prune.
     */
    public function testBlocksGoAWindowAfterTheyEndAndPermanentOnesStay(): void
    {
        $window = 10 * 60;
        $insert = $this->pdo->prepare('INSERT INTO ip_blocks (ip, reason, source, blocked_at, expires_at) VALUES (?, ?, ?, 0, ?)');
        $insert->execute(['auto-exactly-a-window', 'r', 'auto', self::NOW - $window]);
        $insert->execute(['manual-over-a-window', 'r', 'manual', self::NOW - $window - 1]);
        $insert->execute(['auto-a-second-short', 'r', 'auto', self::NOW - $window + 1]);
        $insert->execute(['manual-ended-now', 'r', 'manual', self::NOW]);
        $insert->execute(['auto-active', 'r', 'auto', self::NOW + 1]);
        $insert->execute(['manual-forever', 'r', 'manual', null]);

        self::assertSame(2, $this->prune()['ip_blocks']);
        self::assertSame(
            ['auto-a-second-short', 'auto-active', 'manual-ended-now', 'manual-forever'],
            $this->column('SELECT ip FROM ip_blocks ORDER BY ip'),
        );
    }

    public function testTheBlockWindowComesFromTheSetting(): void
    {
        $insert = $this->pdo->prepare('INSERT INTO ip_blocks (ip, reason, source, blocked_at, expires_at) VALUES (?, ?, ?, 0, ?)');
        $insert->execute(['ended-90s-ago', 'r', 'auto', self::NOW - 90]);
        $insert->execute(['ended-60s-ago', 'r', 'auto', self::NOW - 60]);
        $insert->execute(['ended-59s-ago', 'r', 'auto', self::NOW - 59]);

        self::assertSame(2, $this->prune(blockWindowMinutes: 1)['ip_blocks']);
        self::assertSame(['ended-59s-ago'], $this->column('SELECT ip FROM ip_blocks'));
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
        new Retention($this->pdo, 0, 10);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidBlockWindows(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-10];
        yield 'over a day (rate_limits are kept a day)' => [IpBlocking::MAX_WINDOW_MINUTES + 1];
    }

    #[DataProvider('invalidBlockWindows')]
    public function testTheBlockWindowIsValidatedAsIpBlockingDoes(int $minutes): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('block.window_minutes must be between 1 and 1440');
        new Retention($this->pdo, 30, $minutes);
    }

    /**
     * @return array<string, int>
     */
    private function prune(int $retentionDays = 30, int $blockWindowMinutes = 10): array
    {
        return (new Retention($this->pdo, $retentionDays, $blockWindowMinutes))->prune(self::NOW);
    }

    /**
     * @return list<mixed>
     */
    private function column(string $sql): array
    {
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    }
}
