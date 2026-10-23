<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Db;

use FxInstallments\Db\Connection;
use FxInstallments\Db\Database;
use FxInstallments\Db\DatabaseFileMissing;
use FxInstallments\Db\SchemaCorrupt;
use FxInstallments\Db\SchemaOutOfDate;
use FxInstallments\Tests\Support\OverridesEnvironment;
use FxInstallments\Tests\Support\TestEnvironment;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
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

        Database::fromEnvironment(dirname(__DIR__, 3), create: true)->exec('CREATE TABLE t (x INTEGER)');

        self::assertFileExists($path);
    }

    /**
     * L-04: only a command that may create the database (migrate) creates a missing SQLite
     * file; every other caller is refused before anything is opened.
     */
    public function testFromEnvironmentRefusesAMissingSqliteFileWithoutCreatingIt(): void
    {
        $dir = TestEnvironment::tempDir('missing') . '/not-yet';
        $this->setEnvironment('DB_DSN', 'sqlite:' . $dir . '/app.db');

        try {
            Database::fromEnvironment(dirname(__DIR__, 3));
            self::fail('a missing file must be refused');
        } catch (DatabaseFileMissing $e) {
            self::assertSame('no database file (run php api/bin/migrate.php first)', $e->getMessage());
        }

        self::assertDirectoryDoesNotExist($dir);
    }

    /**
     * L-04 cleanup B (mutation M1): a file removed after the existence check is refused, not
     * made, because SQLite opens it without SQLITE_OPEN_CREATE. The race is staged with PHP's
     * stat cache: is_file() here caches "a file" for the path, another process removes the
     * file (which leaves this process's cache alone), and fromEnvironment()'s own is_file()
     * reads the cached answer. With the create flag SQLite would make an empty file, and the
     * schema check would report SchemaOutOfDate instead.
     */
    public function testAFileRemovedAfterTheCheckIsNotCreated(): void
    {
        $path = TestEnvironment::freshDatabasePath();
        $this->setEnvironment('DB_DSN', 'sqlite:' . $path);
        self::assertTrue(is_file($path));

        $process = proc_open(
            [PHP_BINARY, '-d', 'allow_url_fopen=0', '-d', 'disable_functions=curl_exec,curl_multi_exec', '-r', 'exit(unlink($argv[1]) ? 0 : 1);', $path],
            [],
            $pipes,
        );
        self::assertIsResource($process);
        self::assertSame(0, proc_close($process), 'another process removed the file');
        self::assertTrue(is_file($path), 'the stat cache still says the file is there: the race is staged');

        try {
            Database::fromEnvironment(dirname(__DIR__, 3));
            self::fail('SQLite opened a file that is gone');
        } catch (PDOException $e) {
            self::assertStringContainsString('unable to open database file', $e->getMessage());
        }

        clearstatcache();
        self::assertFileDoesNotExist($path, 'the open did not create the file');
    }

    /**
     * @return iterable<string, array{string|null, string}>
     */
    public static function creatableDsns(): iterable
    {
        // The P1-09 finding: on Windows dirname(__DIR__) has backslashes, so an explicit
        // `sqlite:api/var/app.db` never equalled the default DSN and its folder wasn't created.
        yield 'the default' => [null, 'repo/api/var/app.db'];
        yield 'the default, given explicitly' => ['sqlite:api/var/app.db', 'repo/api/var/app.db'];
        yield 'another relative file' => ['sqlite:api/var/demo.db', 'repo/api/var/demo.db'];
        yield 'a relative file in a new folder' => ['sqlite:data/one/two.db', 'repo/data/one/two.db'];
        yield 'an absolute file in a new folder' => ['sqlite:{root}/elsewhere/deep/app.db', 'elsewhere/deep/app.db'];
    }

    #[DataProvider('creatableDsns')]
    public function testMigrateCreatesTheFolderOfAnySqliteFile(?string $dsn, string $expected): void
    {
        $root = TestEnvironment::tempDir('create');
        // As dirname(__DIR__) gives it: the platform's separator.
        $apiRoot = $root . DIRECTORY_SEPARATOR . 'repo' . DIRECTORY_SEPARATOR . 'api';
        $this->setEnvironment('DB_DSN', $dsn === null ? null : str_replace('{root}', $root, $dsn));

        $pdo = Database::fromEnvironment($apiRoot, create: true);

        self::assertFileExists($root . '/' . $expected);
        self::assertSame('wal', $pdo->query('PRAGMA journal_mode')->fetchColumn());
        self::assertSame([], $pdo->query('SELECT name FROM sqlite_master')->fetchAll(), 'creating runs no migration');
    }

    /**
     * L-04: the schema is checked before the journal mode is set, so refusing an empty (or old)
     * file writes nothing to it, not even the WAL header.
     */
    public function testTheSchemaIsCheckedBeforeWalSoAnEmptyFileStaysEmpty(): void
    {
        $path = TestEnvironment::tempDir('empty') . '/empty.db';
        touch($path);
        $this->setEnvironment('DB_DSN', 'sqlite:' . $path);

        try {
            Database::fromEnvironment(dirname(__DIR__, 3));
            self::fail('an empty database is behind the code');
        } catch (SchemaOutOfDate $e) {
            self::assertSame(0, $e->current);
        }

        clearstatcache();
        self::assertSame(0, filesize($path), 'nothing was written');
        self::assertFileDoesNotExist($path . '-wal');
    }

    public function testACurrentDatabaseOpensInWalMode(): void
    {
        $path = TestEnvironment::freshDatabasePath();
        $this->setEnvironment('DB_DSN', 'sqlite:' . $path);

        $pdo = Database::fromEnvironment(dirname(__DIR__, 3));

        self::assertSame('wal', $pdo->query('PRAGMA journal_mode')->fetchColumn());
        self::assertSame(5000, (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn());
    }

    public function testACorruptVersionIsRefusedBeforeWalToo(): void
    {
        $path = TestEnvironment::freshDatabasePath();
        TestEnvironment::connect($path)->exec("UPDATE app_state SET value = 'garbage' WHERE key = 'schema_version'");
        $this->setEnvironment('DB_DSN', 'sqlite:' . $path);

        $this->expectException(SchemaCorrupt::class);
        Database::fromEnvironment(dirname(__DIR__, 3));
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function sqliteFiles(): iterable
    {
        yield 'a file' => ['sqlite:/data/app.db', '/data/app.db'];
        yield 'a Windows file' => ['sqlite:C:\\data\\app.db', 'C:\\data\\app.db'];
        yield 'in memory' => ['sqlite::memory:', null];
        yield 'a temporary database' => ['sqlite:', null];
        yield 'Postgres' => ['pgsql:host=db;dbname=fx', null];
    }

    #[DataProvider('sqliteFiles')]
    public function testTheSqliteFileOfADsn(string $dsn, ?string $file): void
    {
        self::assertSame($file, Database::sqliteFile($dsn));
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
