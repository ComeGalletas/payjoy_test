<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Db;

use FxInstallments\Db\Connection;
use FxInstallments\Db\Database;
use FxInstallments\Tests\Support\OverridesEnvironment;
use FxInstallments\Tests\Support\TestEnvironment;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DatabaseTest extends TestCase
{
    use OverridesEnvironment;

    public function testSqliteRunsWithWalBusyTimeoutAndExceptions(): void
    {
        $pdo = Database::connect('sqlite:' . TestEnvironment::tempDir() . '/wal.db');

        self::assertSame('wal', $pdo->query('PRAGMA journal_mode')->fetchColumn());
        self::assertSame(5000, (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn());
        self::assertSame(PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(PDO::ATTR_ERRMODE));

        $this->expectException(PDOException::class);
        $pdo->query('SELECT * FROM no_such_table');
    }

    public function testTheDefaultDatabaseIsAppDbOutsidePublic(): void
    {
        $this->setEnvironment('DB_DSN', null);
        $apiRoot = dirname(__DIR__, 3);

        $dsn = Database::dsnFromEnvironment($apiRoot);

        self::assertSame('sqlite:' . $apiRoot . '/var/app.db', $dsn);
        self::assertStringNotContainsString('/public/', $dsn);
    }

    public function testTheTestSuiteNeverUsesAppDb(): void
    {
        $dsn = Database::dsnFromEnvironment(dirname(__DIR__, 3));

        self::assertStringNotContainsString('app.db', $dsn);
        self::assertStringStartsWith('sqlite:' . TestEnvironment::root() . '/', $dsn);
    }

    public function testARelativeSqlitePathIsResolvedAgainstTheRepositoryRoot(): void
    {
        $this->setEnvironment('DB_DSN', 'sqlite:api/var/other.db');

        self::assertSame('sqlite:/repo/api/var/other.db', Database::dsnFromEnvironment('/repo/api'));
    }

    public function testAbsoluteAndOtherDsnsAreUsedAsGiven(): void
    {
        foreach (['sqlite:/data/app.db', 'sqlite:C:/data/app.db', 'sqlite:C:\\data\\app.db', 'sqlite::memory:', 'pgsql:host=db;dbname=fx'] as $dsn) {
            $this->setEnvironment('DB_DSN', $dsn);
            self::assertSame($dsn, Database::dsnFromEnvironment('/repo/api'));
        }
    }

    public function testFromEnvironmentConnectsToDbDsn(): void
    {
        $path = TestEnvironment::tempDir() . '/env.db';
        $this->setEnvironment('DB_DSN', 'sqlite:' . $path);

        Database::fromEnvironment(dirname(__DIR__, 3))->exec('CREATE TABLE t (x INTEGER)');

        self::assertFileExists($path);
    }

    public function testThereIsNoFloatBindingHelper(): void
    {
        // ADR 0017: nothing binds floats any more, so floatParam() is gone.
        self::assertFalse(method_exists(Database::class, 'floatParam'));
    }

    public function testConnectingNeverCreatesOrChangesTheSchema(): void
    {
        $path = TestEnvironment::tempDir() . '/bare.db';

        Database::connect('sqlite:' . $path);

        self::assertSame([], Database::connect('sqlite:' . $path)->query("SELECT name FROM sqlite_master")->fetchAll());
    }

    public function testConnectionOpensLazilyOnceAndRetriesAfterAFailure(): void
    {
        $calls = 0;
        $connection = new Connection(static function () use (&$calls): PDO {
            $calls++;
            if ($calls === 1) {
                throw new RuntimeException('down');
            }

            return Database::connect('sqlite::memory:');
        });
        self::assertSame(0, $calls);

        try {
            $connection->pdo();
            self::fail('the first open should fail');
        } catch (RuntimeException) {
        }
        $first = $connection->pdo();

        self::assertSame($first, $connection->pdo());
        self::assertSame(2, $calls);
    }
}
