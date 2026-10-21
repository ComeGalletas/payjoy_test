<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Db;

use FxInstallments\Db\Database;
use FxInstallments\Db\Schema;
use FxInstallments\Tests\Support\TestEnvironment;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase
{
    public function testItCreatesOnlyEventsAndRateLimitsWithTheSection5Columns(): void
    {
        $pdo = Database::connect('sqlite:' . TestEnvironment::tempDir() . '/schema.db');
        Schema::apply($pdo);

        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['events', 'rate_limits'], $tables);

        self::assertSame(
            ['id', 'ts', 'source', 'channel', 'event_type', 'session_id', 'step', 'currency', 'country',
                'amount_usd', 'installments', 'status', 'error_code', 'latency_ms', 'meta'],
            self::columns($pdo, 'events'),
        );
        self::assertSame(['ip', 'window_start', 'count'], self::columns($pdo, 'rate_limits'));
    }

    public function testApplyingTwiceIsANoOp(): void
    {
        $pdo = Database::connect('sqlite:' . TestEnvironment::tempDir() . '/twice.db');
        Schema::apply($pdo);
        $pdo->exec("INSERT INTO rate_limits (ip, window_start, count) VALUES ('198.51.100.1', 60, 3)");

        Schema::apply($pdo);

        self::assertSame(3, (int) $pdo->query('SELECT count FROM rate_limits')->fetchColumn());
    }

    public function testStatementsSkipComments(): void
    {
        $statements = Schema::statements();

        self::assertCount(3, $statements);
        foreach ($statements as $statement) {
            self::assertStringStartsWith('CREATE ', $statement);
            self::assertStringContainsString('IF NOT EXISTS', $statement);
        }
    }

    public function testEnumColumnsRejectUnknownValues(): void
    {
        $pdo = Database::connect('sqlite:' . TestEnvironment::tempDir() . '/check.db');
        Schema::apply($pdo);

        $this->expectException(PDOException::class);
        $pdo->exec("INSERT INTO events (ts, source, event_type, status) VALUES (1, 'web', 'x', 'ok')");
    }

    /**
     * @return list<string>
     */
    private static function columns(PDO $pdo, string $table): array
    {
        return array_map(
            static fn (array $column): string => $column['name'],
            $pdo->query("PRAGMA table_info({$table})")->fetchAll(),
        );
    }
}
