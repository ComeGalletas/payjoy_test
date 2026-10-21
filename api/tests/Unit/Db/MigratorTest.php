<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Db;

use Closure;
use FxInstallments\Db\Database;
use FxInstallments\Db\Migration;
use FxInstallments\Db\Migrations;
use FxInstallments\Db\Migrator;
use FxInstallments\Db\SchemaCorrupt;
use FxInstallments\Db\SchemaOutOfDate;
use FxInstallments\Tests\Support\FakeClock;
use FxInstallments\Tests\Support\P1_01Schema;
use FxInstallments\Tests\Support\TestEnvironment;
use InvalidArgumentException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    public function testMigrationOneIsExactlyWhatP1_01Shipped(): void
    {
        self::assertSame(P1_01Schema::statements(), Migrations::all()[0]->statements);
    }

    public function testVersionsRunFromOneWithNoGaps(): void
    {
        $versions = array_map(static fn (Migration $m): int => $m->version, Migrations::all());

        self::assertSame(range(1, count($versions)), $versions);
        self::assertSame(3, Migrations::latestVersion());
    }

    public function testTheVersionIsRecordedInAppStateWithTheClock(): void
    {
        $pdo = self::newDatabase();
        $migrator = new Migrator($pdo, null, Closure::fromCallable(new FakeClock(1_790_000_000)));
        self::assertSame(0, $migrator->currentVersion());
        self::assertFalse($migrator->isCurrent());

        $applied = $migrator->migrate();

        self::assertSame([1, 2, 3], array_map(static fn (Migration $m): int => $m->version, $applied));
        self::assertSame(3, $migrator->currentVersion());
        self::assertTrue($migrator->isCurrent());
        self::assertSame(
            [['key' => 'schema_version', 'value' => '3', 'updated_at' => 1_790_000_000]],
            $pdo->query('SELECT * FROM app_state')->fetchAll(),
        );
        self::assertSame([], $migrator->migrate());
    }

    public function testOnlyNewerMigrationsRun(): void
    {
        $pdo = self::newDatabase();
        (new Migrator($pdo, array_slice(Migrations::all(), 0, 2)))->migrate();
        self::assertSame(2, (new Migrator($pdo))->currentVersion());

        $applied = (new Migrator($pdo))->migrate();

        self::assertSame(['events_amount_usd_cents'], array_map(static fn (Migration $m): string => $m->name, $applied));
        self::assertSame(3, (new Migrator($pdo))->currentVersion());
    }

    public function testAFailedMigrationRollsBackAndKeepsThePreviousVersion(): void
    {
        $pdo = self::newDatabase();
        $migrations = [
            ...Migrations::all(),
            new Migration(4, 'broken', ['CREATE TABLE half_done (x INTEGER)', 'NOT SQL AT ALL']),
        ];

        try {
            (new Migrator($pdo, $migrations))->migrate();
            self::fail('the broken migration should throw');
        } catch (PDOException) {
        }

        self::assertSame(3, (new Migrator($pdo))->currentVersion());
        self::assertFalse($pdo->inTransaction());
        self::assertSame([], $pdo->query("SELECT name FROM sqlite_master WHERE name = 'half_done'")->fetchAll());
    }

    public function testAssertCurrentFailsOnlyWhenTheDatabaseIsBehind(): void
    {
        $pdo = self::newDatabase();
        P1_01Schema::apply($pdo);

        try {
            (new Migrator($pdo))->assertCurrent();
            self::fail('a P1-01 database is behind');
        } catch (SchemaOutOfDate $e) {
            self::assertSame(0, $e->current);
            self::assertSame(3, $e->required);
        }

        (new Migrator($pdo))->migrate();
        (new Migrator($pdo))->assertCurrent();

        // A database ahead of the code (a newer migration ran, then the code was rolled back).
        $pdo->exec("UPDATE app_state SET value = '9' WHERE key = 'schema_version'");
        (new Migrator($pdo))->assertCurrent();
        self::assertTrue((new Migrator($pdo))->isCurrent());
    }

    public function testAMissingAppStateTableMeansVersionZero(): void
    {
        $empty = self::newDatabase();
        $p101 = self::newDatabase();
        P1_01Schema::apply($p101);

        self::assertSame(0, (new Migrator($empty))->currentVersion());
        self::assertSame(0, (new Migrator($p101))->currentVersion());
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function corruptVersions(): iterable
    {
        yield 'no schema_version row' => [null];
        yield 'empty' => [''];
        yield 'garbage' => ['abc'];
        yield 'decimal' => ['3.5'];
        yield 'negative' => ['-1'];
        yield 'padded' => [' 3'];
        yield 'leading zero' => ['03'];
        yield 'trailing newline' => ["3\n"];
    }

    #[DataProvider('corruptVersions')]
    public function testACorruptVersionFailsClosed(?string $value): void
    {
        $pdo = TestEnvironment::connect(TestEnvironment::freshDatabasePath());
        if ($value === null) {
            $pdo->exec("DELETE FROM app_state WHERE key = 'schema_version'");
        } else {
            $pdo->prepare("UPDATE app_state SET value = ? WHERE key = 'schema_version'")->execute([$value]);
        }
        $migrator = new Migrator($pdo);

        foreach ([$migrator->currentVersion(...), $migrator->assertCurrent(...), $migrator->migrate(...)] as $call) {
            try {
                $call();
                self::fail('a corrupt schema_version must throw');
            } catch (SchemaCorrupt) {
                $this->addToAssertionCount(1);
            }
        }
        // Nothing was re-applied over the existing tables.
        self::assertSame(7, (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table'")->fetchColumn());
    }

    public function testALockedDatabasePropagatesInsteadOfCountingAsVersionZero(): void
    {
        // Rollback-journal mode (not WAL), so an exclusive lock blocks readers too.
        $path = TestEnvironment::tempDir('locked') . '/locked.db';
        $holder = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $holder->exec('CREATE TABLE app_state (key TEXT NOT NULL PRIMARY KEY, value TEXT NOT NULL, updated_at INTEGER NOT NULL)');
        $holder->exec('BEGIN EXCLUSIVE');
        $reader = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $reader->exec('PRAGMA busy_timeout = 0');

        try {
            (new Migrator($reader))->currentVersion();
            self::fail('a locked database must not count as version 0');
        } catch (PDOException $e) {
            self::assertStringContainsString('database is locked', $e->getMessage());
        } finally {
            $holder->exec('ROLLBACK');
        }
    }

    public function testAFileThatIsNotADatabasePropagates(): void
    {
        $path = TestEnvironment::tempDir('broken') . '/broken.db';
        file_put_contents($path, str_repeat('this is not an SQLite database file. ', 200));
        $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('file is not a database');
        (new Migrator($pdo))->currentVersion();
    }

    public function testTheMigrationListMustBeConsecutive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Migrator(self::newDatabase(), [new Migration(1, 'a', ['SELECT 1']), new Migration(3, 'c', ['SELECT 1'])]);
    }

    public function testEnumAndExpiryChecksRejectBadRows(): void
    {
        $pdo = TestEnvironment::connect(TestEnvironment::freshDatabasePath());
        $bad = [
            "INSERT INTO events (ts, source, event_type, status) VALUES (1, 'web', 'x', 'ok')",
            "INSERT INTO events (ts, source, channel, event_type, status) VALUES (1, 'bot', 'sms', 'x', 'ok')",
            "INSERT INTO events (ts, source, event_type, status) VALUES (1, 'api', 'x', 'maybe')",
            "INSERT INTO sessions (session_id, channel, step, status, created_at, updated_at, expires_at) VALUES ('s', 'sms', 'amount', 'open', 1, 1, 2)",
            "INSERT INTO sessions (session_id, channel, step, status, created_at, updated_at, expires_at) VALUES ('s', 'cli', 'amount', 'closed', 1, 1, 2)",
            "INSERT INTO requests (ts, ip, method, path, status, blocked) VALUES (1, 'ip', 'GET', '/', 200, 2)",
            "INSERT INTO ip_blocks (ip, reason, source, blocked_at, expires_at) VALUES ('ip', 'r', 'robot', 1, 2)",
            // Only a manual block may have no expiry.
            "INSERT INTO ip_blocks (ip, reason, source, blocked_at, expires_at) VALUES ('ip', 'r', 'auto', 1, NULL)",
        ];
        foreach ($bad as $sql) {
            try {
                $pdo->exec($sql);
                self::fail("accepted: {$sql}");
            } catch (PDOException $e) {
                self::assertSame('23000', (string) $e->getCode(), $sql);
            }
        }

        $pdo->exec("INSERT INTO ip_blocks (ip, reason, source, blocked_at, expires_at) VALUES ('manual', 'r', 'manual', 1, NULL)");
        $pdo->exec("INSERT INTO ip_blocks (ip, reason, source, blocked_at, expires_at) VALUES ('auto', 'r', 'auto', 1, 2)");
        $pdo->exec("INSERT INTO sessions (session_id, channel, step, status, created_at, updated_at, expires_at) VALUES ('s', 'cli', 'amount', 'open', 1, 1, 2)");
        self::assertSame(
            ['collected' => '{}', 'retries' => 0],
            $pdo->query('SELECT collected, retries FROM sessions')->fetch(),
        );
        self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM ip_blocks')->fetchColumn());
    }

    public function testTheSessionExpirySweepUsesTheIndex(): void
    {
        $pdo = TestEnvironment::connect(TestEnvironment::freshDatabasePath());

        $plan = implode(' | ', $pdo->query(
            "EXPLAIN QUERY PLAN SELECT session_id FROM sessions WHERE status = 'open' AND expires_at <= 100",
        )->fetchAll(PDO::FETCH_COLUMN, 3));

        self::assertStringContainsString('USING INDEX sessions_status_expires_at (status=? AND expires_at<?)', $plan);
    }

    public function testPruneQueriesUseTheirIndexes(): void
    {
        $pdo = TestEnvironment::connect(TestEnvironment::freshDatabasePath());
        $plans = [
            'SELECT * FROM rate_limits WHERE window_start < 100' => 'rate_limits_window_start',
            'SELECT * FROM requests WHERE ts < 100' => 'requests_ts',
            "SELECT COUNT(*) FROM requests WHERE ip = 'x' AND ts >= 100" => 'requests_ip_ts',
        ];
        foreach ($plans as $sql => $index) {
            $plan = implode(' | ', $pdo->query("EXPLAIN QUERY PLAN {$sql}")->fetchAll(PDO::FETCH_COLUMN, 3));
            self::assertStringContainsString("INDEX {$index}", $plan, $sql);
        }
    }

    private static function newDatabase(): PDO
    {
        return Database::connect('sqlite:' . TestEnvironment::tempDir('migrator') . '/m.db');
    }
}
