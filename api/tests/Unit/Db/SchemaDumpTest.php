<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Db;

use FxInstallments\Db\Database;
use FxInstallments\Db\Schema;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * api/db/schema.sql is generated from the migration list and must never drift from it.
 */
final class SchemaDumpTest extends TestCase
{
    public function testSchemaSqlIsExactlyTheDumpOfTheMigrations(): void
    {
        self::assertSame(
            Schema::dump(),
            file_get_contents(Schema::path()),
            'api/db/schema.sql differs from the migrations: run php api/bin/migrate.php --dump',
        );
    }

    public function testTheDumpIsUtf8WithLfAndNoBom(): void
    {
        $sql = (string) file_get_contents(Schema::path());

        self::assertStringStartsNotWith("\xEF\xBB\xBF", $sql);
        self::assertStringNotContainsString("\r", $sql);
        self::assertTrue(mb_check_encoding($sql, 'UTF-8'));
        self::assertStringEndsWith(";\n", $sql);
    }

    public function testRunningTheDumpOnAnEmptyDatabaseGivesTheSevenTables(): void
    {
        $pdo = Database::connect('sqlite::memory:');
        $pdo->exec(Schema::dump());

        self::assertSame(
            ['app_state', 'events', 'ip_blocks', 'rate_cache', 'rate_limits', 'requests', 'sessions'],
            $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    public function testTheDumpHasNoFloatingPointColumnAndNoOldAmountColumn(): void
    {
        $sql = Schema::dump();

        self::assertDoesNotMatchRegularExpression('/\b(REAL|FLOAT|DOUBLE|NUMERIC|DECIMAL)\b/i', $sql);
        self::assertStringNotContainsString('amount_usd ', $sql);
        self::assertStringContainsString('amount_usd_cents INTEGER', $sql);
        self::assertStringContainsString('rate_scaled BIGINT NOT NULL', $sql);
    }
}
