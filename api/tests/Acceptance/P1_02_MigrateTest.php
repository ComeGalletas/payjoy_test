<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Acceptance;

use FxInstallments\Tests\Support\P1_01Schema;
use FxInstallments\Tests\Support\TestEnvironment;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * P1-02 acceptance (docs/PLAN.md §10), through the real `php bin/migrate.php` in a child
 * process with APP_ENV=test and DB_DSN pointing at a temp SQLite file:
 * - migrating an empty database creates all seven Phase 1 tables;
 * - running it twice is a no-op;
 * - `--prune` removes only the expired rows in each table (fixtures on both sides of every
 *   boundary) and keeps events and manual blocks with no expiry;
 * - no column in any table has a floating-point type (INV-09, ADR 0017);
 * - a database created by the P1-01 schema, with REAL amounts in events, upgrades to the right
 *   cents values.
 *
 * The child process reads the clock itself, so prune fixtures sit an hour either side of each
 * boundary; RetentionTest checks the exact boundaries with a fixed clock.
 */
final class P1_02_MigrateTest extends TestCase
{
    private const SEVEN_TABLES = ['app_state', 'events', 'ip_blocks', 'rate_cache', 'rate_limits', 'requests', 'sessions'];
    private const DAY = 86_400;
    private const MARGIN = 3_600;

    public function testMigratingAnEmptyDatabaseCreatesAllSevenTables(): void
    {
        $path = self::newDatabasePath();

        [$exit, $out, $err] = self::migrate($path);

        self::assertSame(0, $exit, $err);
        self::assertSame(
            "migrate: applied 1 p1_01_baseline\n"
            . "migrate: applied 2 phase1_tables\n"
            . "migrate: applied 3 events_amount_usd_cents\n"
            . "migrate: schema version 0 -> 3\n",
            $out,
        );
        $pdo = TestEnvironment::connect($path);
        self::assertSame(self::SEVEN_TABLES, self::tables($pdo));
        self::assertSame('3', $pdo->query("SELECT value FROM app_state WHERE key = 'schema_version'")->fetchColumn());
        self::assertSame(
            ['events_ts', 'rate_limits_window_start', 'requests_ip_ts', 'requests_ts', 'sessions_status_expires_at'],
            $pdo->query("SELECT name FROM sqlite_master WHERE type = 'index' AND sql IS NOT NULL ORDER BY name")->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    public function testRunningItTwiceIsANoOp(): void
    {
        $path = self::newDatabasePath();
        self::assertSame(0, self::migrate($path)[0]);
        $pdo = TestEnvironment::connect($path);
        $pdo->exec("INSERT INTO rate_limits (ip, window_start, count) VALUES ('198.51.100.1', 60, 3)");
        $schemaBefore = self::schemaObjects($pdo);
        $stateBefore = $pdo->query('SELECT * FROM app_state')->fetchAll();

        [$exit, $out, $err] = self::migrate($path);

        self::assertSame(0, $exit, $err);
        self::assertSame("migrate: schema is current (version 3)\n", $out);
        self::assertSame($schemaBefore, self::schemaObjects($pdo));
        self::assertSame($stateBefore, $pdo->query('SELECT * FROM app_state')->fetchAll());
        self::assertSame(3, (int) $pdo->query('SELECT count FROM rate_limits')->fetchColumn());
    }

    public function testPruneRemovesOnlyExpiredRowsInEachTable(): void
    {
        $path = self::newDatabasePath();
        self::assertSame(0, self::migrate($path)[0]);
        $pdo = TestEnvironment::connect($path);
        $now = time();
        $dayAgo = $now - self::DAY;
        $thirtyDaysAgo = $now - 30 * self::DAY;

        // rate_limits: windows that started more than a day ago go.
        $limit = $pdo->prepare('INSERT INTO rate_limits (ip, window_start, count) VALUES (?, ?, 1)');
        $limit->execute(['old', $dayAgo - self::MARGIN]);
        $limit->execute(['recent', $dayAgo + self::MARGIN]);
        $limit->execute(['current', $now]);

        // sessions: closed and older than 30 days go; open ones stay whatever their age.
        $session = $pdo->prepare(
            "INSERT INTO sessions (session_id, channel, step, status, created_at, updated_at, expires_at) VALUES (?, 'cli', 'amount', ?, ?, ?, ?)",
        );
        foreach ([
            ['completed-old', 'completed', $thirtyDaysAgo - self::MARGIN],
            ['abandoned-old', 'abandoned', $thirtyDaysAgo - self::MARGIN],
            ['completed-recent', 'completed', $thirtyDaysAgo + self::MARGIN],
            ['abandoned-recent', 'abandoned', $thirtyDaysAgo + self::MARGIN],
            ['open-old', 'open', $thirtyDaysAgo - self::MARGIN],
        ] as [$id, $status, $updatedAt]) {
            $session->execute([$id, $status, $updatedAt - 60, $updatedAt, $updatedAt + 1800]);
        }

        // requests: older than requests.retention_days (30) go.
        $request = $pdo->prepare("INSERT INTO requests (ts, ip, method, path, status, latency_ms, blocked) VALUES (?, ?, 'GET', '/convert', 200, 3, 0)");
        $request->execute([$thirtyDaysAgo - self::MARGIN, 'old']);
        $request->execute([$thirtyDaysAgo + self::MARGIN, 'recent']);

        // ip_blocks: expired blocks go, auto or manual; manual blocks with no expiry stay.
        $block = $pdo->prepare('INSERT INTO ip_blocks (ip, reason, source, blocked_at, expires_at) VALUES (?, ?, ?, ?, ?)');
        $block->execute(['auto-expired', 'rate_limit_hits', 'auto', $now - 2 * self::MARGIN, $now - self::MARGIN]);
        $block->execute(['manual-expired', 'operator', 'manual', $now - 2 * self::MARGIN, $now - self::MARGIN]);
        $block->execute(['auto-active', 'error_responses', 'auto', $now - self::MARGIN, $now + self::MARGIN]);
        $block->execute(['manual-forever', 'operator', 'manual', $now - 400 * self::DAY, null]);

        // events are never pruned.
        $pdo->exec("INSERT INTO events (ts, source, event_type, status) VALUES (" . ($now - 400 * self::DAY) . ", 'api', 'api.request', 'ok')");

        [$exit, $out, $err] = self::migrate($path, '--prune');

        self::assertSame(0, $exit, $err);
        self::assertSame(
            "prune: rate_limits 1 deleted\nprune: sessions 2 deleted\nprune: requests 1 deleted\nprune: ip_blocks 2 deleted\n",
            $out,
        );
        self::assertSame(['current', 'recent'], self::column($pdo, 'SELECT ip FROM rate_limits ORDER BY ip'));
        self::assertSame(
            ['abandoned-recent', 'completed-recent', 'open-old'],
            self::column($pdo, 'SELECT session_id FROM sessions ORDER BY session_id'),
        );
        self::assertSame(['recent'], self::column($pdo, 'SELECT ip FROM requests'));
        self::assertSame(['auto-active', 'manual-forever'], self::column($pdo, 'SELECT ip FROM ip_blocks ORDER BY ip'));
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn());

        // A second prune finds nothing more.
        self::assertSame(
            "prune: rate_limits 0 deleted\nprune: sessions 0 deleted\nprune: requests 0 deleted\nprune: ip_blocks 0 deleted\n",
            self::migrate($path, '--prune')[1],
        );
    }

    public function testPruneRefusesADatabaseBehindTheCode(): void
    {
        $path = self::newDatabasePath();
        P1_01Schema::apply(TestEnvironment::connect($path));

        [$exit, $out, $err] = self::migrate($path, '--prune');

        self::assertSame(1, $exit);
        self::assertSame('', $out);
        self::assertSame("prune: Database schema is at version 0, the code needs 3: run php bin/migrate.php\n", $err);
    }

    public function testNoColumnInAnyTableHasAFloatingPointType(): void
    {
        $path = self::newDatabasePath();
        self::assertSame(0, self::migrate($path)[0]);

        self::assertNoFloatColumns(TestEnvironment::connect($path));
    }

    public function testAP1_01DatabaseWithRealAmountsUpgradesToCents(): void
    {
        $path = self::newDatabasePath();
        $pdo = TestEnvironment::connect($path);
        P1_01Schema::apply($pdo);
        // REAL amounts as P1-01 stored them: canonical amounts, ones whose float is just below
        // the cent (0.29 * 100 = 28.999999999999996), the cap, an unrounded quotient, NULL.
        $amounts = [200.5, 0.01, 0.29, 1.15, 9999999.99, 1045.82 / 3, 100.0, null];
        $expectedCents = [20050, 1, 29, 115, 999999999, 34861, 10000, null];
        $insert = $pdo->prepare(
            "INSERT INTO events (ts, source, channel, event_type, session_id, step, currency, country, amount_usd, installments, status, error_code, latency_ms, meta)"
            . " VALUES (?, 'bot', 'cli', 'bot.step', 'sess', 'amount', 'BRL', 'Brazil', ?, 3, 'ok', NULL, 12, '{\"k\":1}')",
        );
        foreach ($amounts as $i => $amount) {
            $insert->bindValue(1, 1_790_000_000 + $i, PDO::PARAM_INT);
            $insert->bindValue(2, $amount === null ? null : sprintf('%.17g', $amount), $amount === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insert->execute();
        }
        $pdo->exec("INSERT INTO rate_limits (ip, window_start, count) VALUES ('198.51.100.1', 1790000040, 7)");
        self::assertSame(
            ['real', 'real', 'real', 'real', 'real', 'real', 'real', 'null'],
            self::column($pdo, 'SELECT typeof(amount_usd) FROM events ORDER BY id'),
        );
        unset($pdo);

        [$exit, $out, $err] = self::migrate($path);

        self::assertSame(0, $exit, $err);
        self::assertStringEndsWith("migrate: schema version 0 -> 3\n", $out);
        $pdo = TestEnvironment::connect($path);
        self::assertSame(self::SEVEN_TABLES, self::tables($pdo));
        $rows = $pdo->query('SELECT * FROM events ORDER BY id')->fetchAll();
        self::assertSame($expectedCents, array_column($rows, 'amount_usd_cents'));
        self::assertSame(
            ['integer', 'integer', 'integer', 'integer', 'integer', 'integer', 'integer', 'null'],
            self::column($pdo, 'SELECT typeof(amount_usd_cents) FROM events ORDER BY id'),
        );
        // Every other column is copied unchanged, ids included.
        foreach ($rows as $i => $row) {
            self::assertSame([
                'id' => $i + 1, 'ts' => 1_790_000_000 + $i, 'source' => 'bot', 'channel' => 'cli',
                'event_type' => 'bot.step', 'session_id' => 'sess', 'step' => 'amount', 'currency' => 'BRL',
                'country' => 'Brazil', 'amount_usd_cents' => $expectedCents[$i], 'installments' => 3,
                'status' => 'ok', 'error_code' => null, 'latency_ms' => 12, 'meta' => '{"k":1}',
            ], $row);
        }
        self::assertSame(
            [['ip' => '198.51.100.1', 'window_start' => 1_790_000_040, 'count' => 7]],
            $pdo->query('SELECT * FROM rate_limits')->fetchAll(),
        );

        // The upgraded schema is exactly the one an empty database gets.
        $fresh = self::newDatabasePath();
        self::assertSame(0, self::migrate($fresh)[0]);
        self::assertSame(self::schemaObjects(TestEnvironment::connect($fresh)), self::schemaObjects($pdo));
        self::assertNoFloatColumns($pdo);
        self::assertSame("migrate: schema is current (version 3)\n", self::migrate($path)[1]);
    }

    private static function assertNoFloatColumns(PDO $pdo): void
    {
        $checked = 0;
        foreach (self::tables($pdo) as $table) {
            foreach ($pdo->query("PRAGMA table_info({$table})")->fetchAll() as $column) {
                $type = strtoupper((string) $column['type']);
                $where = "{$table}.{$column['name']} is declared '{$column['type']}'";
                // SQLite gives REAL affinity to REAL/FLOA/DOUB and NUMERIC affinity (which also
                // stores floats) to NUMERIC/DECIMAL/BOOLEAN/DATE and to an empty type: none allowed.
                self::assertDoesNotMatchRegularExpression('/REAL|FLOA|DOUB|NUM|DEC|BOOL|DATE/', $type, $where);
                self::assertContains($type, ['INTEGER', 'BIGINT', 'TEXT'], $where);
                $checked++;
            }
        }
        self::assertSame(48, $checked, 'every column of the seven tables was inspected');
    }

    private static function newDatabasePath(): string
    {
        return TestEnvironment::tempDir('migrate') . '/app.db';
    }

    /**
     * Runs `php bin/migrate.php` against the SQLite file at $path.
     *
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private static function migrate(string $path, string ...$args): array
    {
        $dir = TestEnvironment::tempDir('migrate-run');
        $env = getenv();
        $env['APP_ENV'] = 'test';
        $env['DB_DSN'] = 'sqlite:' . $path;
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/migrate.php', ...$args],
            [0 => ['pipe', 'r'], 1 => ['file', $dir . '/out', 'w'], 2 => ['file', $dir . '/err', 'w']],
            $pipes,
            $dir,
            $env,
        );
        self::assertIsResource($process, 'could not start bin/migrate.php');
        fclose($pipes[0]);
        $exit = proc_close($process);

        return [$exit, (string) file_get_contents($dir . '/out'), (string) file_get_contents($dir . '/err')];
    }

    /**
     * @return list<string>
     */
    private static function tables(PDO $pdo): array
    {
        return self::column($pdo, "SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function schemaObjects(PDO $pdo): array
    {
        return $pdo->query('SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY type, name')->fetchAll();
    }

    /**
     * @return list<mixed>
     */
    private static function column(PDO $pdo, string $sql): array
    {
        return $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    }
}
