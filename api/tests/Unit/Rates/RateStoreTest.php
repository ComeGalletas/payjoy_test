<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Rates;

use FxInstallments\Db\Connection;
use FxInstallments\Rates\RateStore;
use FxInstallments\Rates\RateTable;
use FxInstallments\Tests\Support\TestEnvironment;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class RateStoreTest extends TestCase
{
    private PDO $pdo;

    private RateStore $store;

    protected function setUp(): void
    {
        $this->pdo = TestEnvironment::connect(TestEnvironment::freshDatabasePath());
        $this->store = new RateStore(Connection::wrap($this->pdo));
    }

    public function testAnEmptyCacheHasNoTable(): void
    {
        self::assertNull($this->store->table());
        self::assertSame([], $this->store->codes());
    }

    public function testReplaceWritesEveryRowAndTableReadsThemBack(): void
    {
        $table = new RateTable(['USD' => 10_000_000_000, 'BRL' => 52_291_000_000, 'IRR' => 17_319_368_619_710_000], 100, 200, 150);

        $this->store->replace($table);

        self::assertEquals(new RateTable(['BRL' => 52_291_000_000, 'IRR' => 17_319_368_619_710_000, 'USD' => 10_000_000_000], 100, 200, 150), $this->store->table());
        self::assertSame(['BRL', 'IRR', 'USD'], $this->store->codes());
    }

    public function testReplaceRemovesRowsTheNewTableLacks(): void
    {
        $this->store->replace(new RateTable(['USD' => 1, 'BRL' => 2], 1, 2, 1));
        $this->store->replace(new RateTable(['USD' => 3], 5, 6, 5));

        self::assertSame(['USD' => 3], $this->store->table()?->rates);
    }

    public function testAFailedReplaceLeavesTheOldTable(): void
    {
        $this->store->replace(new RateTable(['USD' => 10_000_000_000, 'BRL' => 52_291_000_000], 1, 2, 1));

        try {
            // A null scaled rate violates NOT NULL on the second row, mid-transaction.
            /** @phpstan-ignore-next-line deliberately wrong type */
            $this->store->replace(new RateTable(['USD' => 10_000_000_000, 'EUR' => null], 5, 6, 5));
            self::fail('the write should have failed');
        } catch (PDOException) {
            // expected
        }

        self::assertSame(['BRL' => 52_291_000_000, 'USD' => 10_000_000_000], $this->store->table()?->rates);
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testMixedTimesAreReadAsTheOldestPart(): void
    {
        $this->pdo->exec("INSERT INTO rate_cache VALUES ('USD', 10000000000, 100, 300, 120), ('BRL', 52291000000, 90, 400, 110)");

        $table = $this->store->table();

        self::assertNotNull($table);
        self::assertSame(90, $table->lastUpdateUnix);
        self::assertSame(300, $table->nextUpdateUnix);
        self::assertSame(110, $table->fetchedAt);
        self::assertSame(10, $table->ageAt(100));
    }

    public function testInvalidRowsAreSkipped(): void
    {
        $this->pdo->exec("INSERT INTO rate_cache VALUES ('USD', 10000000000, 1, 2, 1), ('brl', 5, 1, 2, 1), "
            . "('ZZZ', 0, 1, 2, 1), ('YYY', -4, 1, 2, 1), ('XXX', 9000000000000000000, 1, 2, 1), ('WWW', 'abc', 1, 2, 1)");

        self::assertSame(['USD' => 10_000_000_000], $this->store->table()?->rates);
        self::assertSame(['USD'], $this->store->codes(), 'codes() skips exactly the rows table() skips');
    }

    public function testRatesAreStoredAsIntegers(): void
    {
        $this->store->replace(new RateTable(['IRR' => 17_319_368_619_710_000], 1, 2, 1));

        self::assertSame('integer', $this->pdo->query("SELECT typeof(rate_scaled) FROM rate_cache")->fetchColumn());
        self::assertSame(17_319_368_619_710_000, $this->pdo->query('SELECT rate_scaled FROM rate_cache')->fetchColumn());
    }
}
