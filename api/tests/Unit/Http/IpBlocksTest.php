<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Http;

use FxInstallments\Db\Connection;
use FxInstallments\Http\IpBlock;
use FxInstallments\Http\IpBlocks;
use FxInstallments\Tests\Support\RacingPdo;
use FxInstallments\Tests\Support\TestEnvironment;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The block-row rules of the ADR 0016 amendment: one row per IP, select-then-write in one
 * transaction, active while now < expires_at.
 */
final class IpBlocksTest extends TestCase
{
    private const NOW = 1_790_000_000;
    private const IP = '203.0.113.7';

    private PDO $pdo;
    private IpBlocks $blocks;

    protected function setUp(): void
    {
        $this->pdo = TestEnvironment::connect(TestEnvironment::freshDatabasePath());
        $this->blocks = new IpBlocks(Connection::wrap($this->pdo));
    }

    public function testABlockIsActiveUntilButNotAtItsExpiry(): void
    {
        $block = new IpBlock(self::IP, 'r', IpBlock::AUTO, self::NOW, self::NOW + 60);

        self::assertTrue($block->isActiveAt(self::NOW));
        self::assertTrue($block->isActiveAt(self::NOW + 59));
        self::assertFalse($block->isActiveAt(self::NOW + 60), 'now == expires_at is no longer blocked');
        self::assertFalse($block->isActiveAt(self::NOW + 61));
        self::assertTrue((new IpBlock(self::IP, 'r', IpBlock::MANUAL, self::NOW, null))->isActiveAt(PHP_INT_MAX));
    }

    public function testAnAutomaticBlockOnANewIpWritesARow(): void
    {
        $block = $this->blocks->blockAutomatically(self::IP, IpBlock::REASON_RATE_LIMIT_HITS, self::NOW, self::NOW + 3600);

        self::assertEquals(new IpBlock(self::IP, 'rate_limit_hits', 'auto', self::NOW, self::NOW + 3600), $block);
        self::assertSame([[self::IP, 'rate_limit_hits', 'auto', self::NOW, self::NOW + 3600]], $this->rows());
        self::assertEquals($block, $this->blocks->find(self::IP));
        self::assertNull($this->blocks->find('198.51.100.1'));
    }

    /**
     * @return iterable<string, array{string, int|null}>
     */
    public static function expiredRows(): iterable
    {
        yield 'expired auto' => ['auto', self::NOW - 1];
        yield 'auto expiring exactly now' => ['auto', self::NOW];
        yield 'expired manual' => ['manual', self::NOW - 100];
    }

    #[DataProvider('expiredRows')]
    public function testReBlockingReplacesAnExpiredRow(string $source, ?int $expiresAt): void
    {
        $this->insert($source, 'old', self::NOW - 7200, $expiresAt);

        $this->blocks->blockAutomatically(self::IP, IpBlock::REASON_ERROR_RESPONSES, self::NOW, self::NOW + 600);

        self::assertSame([[self::IP, 'error_responses', 'auto', self::NOW, self::NOW + 600]], $this->rows());
    }

    #[DataProvider('expiredRows')]
    public function testAManualBlockReplacesAnExpiredRow(string $source, ?int $expiresAt): void
    {
        $this->insert($source, 'old', self::NOW - 7200, $expiresAt);

        $this->blocks->blockManually(self::IP, 'operator', self::NOW, null);

        self::assertSame([[self::IP, 'operator', 'manual', self::NOW, null]], $this->rows());
    }

    public function testAManualBlockReplacesAnActiveAutomaticOne(): void
    {
        $this->insert('auto', 'rate_limit_hits', self::NOW - 60, self::NOW + 3540);

        $block = $this->blocks->blockManually(self::IP, 'operator', self::NOW, self::NOW + 60);

        self::assertEquals(new IpBlock(self::IP, 'operator', 'manual', self::NOW, self::NOW + 60), $block);
        self::assertSame([[self::IP, 'operator', 'manual', self::NOW, self::NOW + 60]], $this->rows());
    }

    public function testAManualBlockReplacesAnActiveManualOne(): void
    {
        $this->insert('manual', 'first', self::NOW - 60, null);

        $this->blocks->blockManually(self::IP, 'second', self::NOW, self::NOW + 600);

        self::assertSame([[self::IP, 'second', 'manual', self::NOW, self::NOW + 600]], $this->rows());
    }

    /**
     * @return iterable<string, array{int|null}>
     */
    public static function activeManualExpiries(): iterable
    {
        yield 'no expiry' => [null];
        yield 'expires later than the automatic block would' => [self::NOW + 99_999];
        yield 'expires sooner than the automatic block would' => [self::NOW + 1];
    }

    #[DataProvider('activeManualExpiries')]
    public function testAnAutomaticBlockNeverReplacesAnActiveManualOne(?int $expiresAt): void
    {
        $this->insert('manual', 'operator', self::NOW - 60, $expiresAt);

        $block = $this->blocks->blockAutomatically(self::IP, IpBlock::REASON_RATE_LIMIT_HITS, self::NOW, self::NOW + 3600);

        self::assertEquals(new IpBlock(self::IP, 'operator', 'manual', self::NOW - 60, $expiresAt), $block);
        self::assertSame([[self::IP, 'operator', 'manual', self::NOW - 60, $expiresAt]], $this->rows());
    }

    public function testAnAutomaticBlockOnAnActiveAutomaticOneExtendsItToTheLaterExpiry(): void
    {
        $this->insert('auto', 'rate_limit_hits', self::NOW - 600, self::NOW + 3000);

        $block = $this->blocks->blockAutomatically(self::IP, IpBlock::REASON_ERROR_RESPONSES, self::NOW, self::NOW + 3600);

        // The expiry moves; blocked_at and the reason stay.
        self::assertEquals(new IpBlock(self::IP, 'rate_limit_hits', 'auto', self::NOW - 600, self::NOW + 3600), $block);
        self::assertSame([[self::IP, 'rate_limit_hits', 'auto', self::NOW - 600, self::NOW + 3600]], $this->rows());
    }

    public function testAnAutomaticBlockNeverShortensAnActiveAutomaticOne(): void
    {
        $this->insert('auto', 'rate_limit_hits', self::NOW - 600, self::NOW + 9000);

        $block = $this->blocks->blockAutomatically(self::IP, IpBlock::REASON_ERROR_RESPONSES, self::NOW, self::NOW + 3600);

        self::assertSame(self::NOW + 9000, $block->expiresAt);
        self::assertSame([[self::IP, 'rate_limit_hits', 'auto', self::NOW - 600, self::NOW + 9000]], $this->rows());
    }

    public function testUnblockEndsAnActiveBlockNow(): void
    {
        $this->insert('manual', 'operator', self::NOW - 60, null);

        $lifted = $this->blocks->unblock(self::IP, self::NOW);

        self::assertEquals(new IpBlock(self::IP, 'operator', 'manual', self::NOW - 60, null), $lifted);
        self::assertSame([[self::IP, 'operator', 'manual', self::NOW - 60, self::NOW]], $this->rows());
        self::assertFalse($this->blocks->find(self::IP)?->isActiveAt(self::NOW));
        self::assertSame([], $this->blocks->active(self::NOW));
    }

    public function testUnblockWithoutAnActiveBlockWritesNothing(): void
    {
        self::assertNull($this->blocks->unblock(self::IP, self::NOW));
        self::assertSame([], $this->rows());

        $this->insert('auto', 'rate_limit_hits', self::NOW - 7200, self::NOW - 3600);
        self::assertNull($this->blocks->unblock(self::IP, self::NOW));
        self::assertSame([[self::IP, 'rate_limit_hits', 'auto', self::NOW - 7200, self::NOW - 3600]], $this->rows());
    }

    public function testActiveListsOnlyActiveBlocksOldestFirst(): void
    {
        $insert = $this->pdo->prepare('INSERT INTO ip_blocks (ip, reason, source, blocked_at, expires_at) VALUES (?, ?, ?, ?, ?)');
        $insert->execute(['198.51.100.3', 'r', 'auto', self::NOW - 10, self::NOW]);        // expires exactly now
        $insert->execute(['198.51.100.2', 'r', 'manual', self::NOW - 20, null]);
        $insert->execute(['198.51.100.1', 'r', 'auto', self::NOW - 5, self::NOW + 1]);
        $insert->execute(['198.51.100.4', 'r', 'manual', self::NOW - 30, self::NOW - 1]);  // expired

        self::assertSame(
            ['198.51.100.2', '198.51.100.1'],
            array_map(static fn (IpBlock $block): string => $block->ip, $this->blocks->active(self::NOW)),
        );
    }

    public function testInvalidBlocksAreRefused(): void
    {
        foreach ([
            fn () => $this->blocks->blockAutomatically(self::IP, 'r', self::NOW, self::NOW),
            fn () => $this->blocks->blockManually(self::IP, 'r', self::NOW, self::NOW),
            fn () => $this->blocks->blockManually(self::IP, 'r', self::NOW, self::NOW - 1),
            fn () => $this->blocks->blockManually('', 'r', self::NOW, null),
            fn () => $this->blocks->blockManually(str_repeat('1', 65), 'r', self::NOW, null),
            fn () => $this->blocks->blockManually(self::IP, '', self::NOW, null),
            fn () => $this->blocks->blockManually(self::IP, "line\nbreak", self::NOW, null),
            fn () => $this->blocks->blockManually(self::IP, str_repeat('r', 201), self::NOW, null),
        ] as $i => $attempt) {
            try {
                $attempt();
                self::fail("attempt {$i} should have been refused");
            } catch (InvalidArgumentException) {
                // expected
            }
        }
        self::assertSame([], $this->rows());
    }

    /**
     * Connection B blocks the IP by hand while A is starting an automatic block: A's
     * select-then-write sees B's committed row and leaves it (Support/RacingPdo runs B's whole
     * write just before A's first UPDATE).
     */
    public function testAConcurrentManualBlockWinsOverAnAutomaticOne(): void
    {
        $path = TestEnvironment::freshDatabasePath();
        $b = new IpBlocks(Connection::wrap(TestEnvironment::connect($path)));
        $a = RacingPdo::open($path, static function () use ($b): void {
            $b->blockManually(self::IP, 'operator', self::NOW, null);
        });

        $block = (new IpBlocks(Connection::wrap($a)))->blockAutomatically(self::IP, IpBlock::REASON_RATE_LIMIT_HITS, self::NOW, self::NOW + 3600);

        self::assertEquals(new IpBlock(self::IP, 'operator', 'manual', self::NOW, null), $block);
        self::assertSame(['UPDATE (stale)', 'SELECT'], $a->statements);
        self::assertEquals($block, $b->find(self::IP));
    }

    public function testAFailedWriteRollsBackAndLeavesNoOpenTransaction(): void
    {
        $this->pdo->exec('CREATE TRIGGER ip_blocks_fail BEFORE INSERT ON ip_blocks BEGIN SELECT RAISE(ABORT, \'no\'); END');

        try {
            $this->blocks->blockManually(self::IP, 'r', self::NOW, null);
            self::fail('the insert should have failed');
        } catch (\PDOException) {
            // expected
        }

        self::assertFalse($this->pdo->inTransaction());
        self::assertSame([], $this->rows());
    }

    private function insert(string $source, string $reason, int $blockedAt, ?int $expiresAt): void
    {
        $this->pdo->prepare('INSERT INTO ip_blocks (ip, reason, source, blocked_at, expires_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([self::IP, $reason, $source, $blockedAt, $expiresAt]);
    }

    /**
     * @return list<array{string, string, string, int, int|null}>
     */
    private function rows(): array
    {
        return array_map(
            static fn (array $row): array => [
                $row['ip'], $row['reason'], $row['source'], (int) $row['blocked_at'],
                $row['expires_at'] === null ? null : (int) $row['expires_at'],
            ],
            $this->pdo->query('SELECT ip, reason, source, blocked_at, expires_at FROM ip_blocks ORDER BY ip')->fetchAll(),
        );
    }
}
