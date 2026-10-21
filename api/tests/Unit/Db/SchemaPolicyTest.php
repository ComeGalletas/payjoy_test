<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Db;

use FxInstallments\App;
use FxInstallments\Db\SchemaCorrupt;
use FxInstallments\Db\SchemaOutOfDate;
use FxInstallments\Tests\Support\OverridesEnvironment;
use FxInstallments\Tests\Support\P1_01Schema;
use FxInstallments\Tests\Support\TestEnvironment;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The schema-apply policy: only bin/migrate.php changes the schema. The app checks the version
 * when it first opens the database and fails closed when the database is behind; it never
 * creates or alters a table itself. (EntryPointEndToEndTest checks the 500 INTERNAL answer.)
 */
final class SchemaPolicyTest extends TestCase
{
    use OverridesEnvironment;

    public function testTheAppRefusesAnEmptyDatabaseAndCreatesNothing(): void
    {
        $path = TestEnvironment::tempDir('policy') . '/empty.db';
        $this->setEnvironment('DB_DSN', 'sqlite:' . $path);

        try {
            App::fromEnvironment(dirname(__DIR__, 3))->db->pdo();
            self::fail('an unmigrated database must be refused');
        } catch (SchemaOutOfDate $e) {
            self::assertSame(0, $e->current);
            self::assertSame(3, $e->required);
            self::assertSame('Database schema is at version 0, the code needs 3: run php bin/migrate.php', $e->getMessage());
        }

        self::assertSame([], TestEnvironment::connect($path)->query('SELECT name FROM sqlite_master')->fetchAll());
    }

    public function testTheAppRefusesAP1_01DatabaseAndLeavesItAsItWas(): void
    {
        $path = TestEnvironment::tempDir('policy') . '/p1-01.db';
        P1_01Schema::apply(TestEnvironment::connect($path));
        $before = TestEnvironment::connect($path)->query('SELECT name, sql FROM sqlite_master ORDER BY name')->fetchAll();
        $this->setEnvironment('DB_DSN', 'sqlite:' . $path);

        $this->expectException(SchemaOutOfDate::class);
        try {
            App::fromEnvironment(dirname(__DIR__, 3))->db->pdo();
        } finally {
            self::assertSame($before, TestEnvironment::connect($path)->query('SELECT name, sql FROM sqlite_master ORDER BY name')->fetchAll());
        }
    }

    public function testTheAppOpensAMigratedDatabaseWithoutChangingIt(): void
    {
        $path = TestEnvironment::freshDatabasePath();
        $before = TestEnvironment::connect($path)->query('SELECT * FROM app_state')->fetchAll();
        $this->setEnvironment('DB_DSN', 'sqlite:' . $path);

        $pdo = App::fromEnvironment(dirname(__DIR__, 3))->db->pdo();

        self::assertInstanceOf(PDO::class, $pdo);
        self::assertSame($before, $pdo->query('SELECT * FROM app_state')->fetchAll());
    }

    public function testTheAppRefusesACorruptSchemaVersion(): void
    {
        $path = TestEnvironment::freshDatabasePath();
        TestEnvironment::connect($path)->exec("UPDATE app_state SET value = 'garbage' WHERE key = 'schema_version'");
        $this->setEnvironment('DB_DSN', 'sqlite:' . $path);

        $this->expectException(SchemaCorrupt::class);
        App::fromEnvironment(dirname(__DIR__, 3))->db->pdo();
    }

    public function testTheAppNeverReferencesTheMigrationRunner(): void
    {
        // Only bin/migrate.php (and tests) call Migrator::migrate(); src/ only asserts the version.
        $root = dirname(__DIR__, 3);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
        $callers = [];
        foreach ($iterator as $file) {
            $code = (string) file_get_contents((string) $file);
            if (preg_match('/->migrate\(\)/', $code) === 1) {
                $callers[] = str_replace('\\', '/', substr((string) $file, strlen($root) + 1));
            }
        }

        // Schema::dump() migrates its own in-memory database to read the result back.
        self::assertSame(['src/Db/Schema.php'], $callers);
    }
}
