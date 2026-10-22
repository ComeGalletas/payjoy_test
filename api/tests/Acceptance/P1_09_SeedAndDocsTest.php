<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Acceptance;

use ArrayObject;
use FxInstallments\App;
use FxInstallments\Config\ConfigRepository;
use FxInstallments\Convert\InstallmentCalculator;
use FxInstallments\Db\Connection;
use FxInstallments\Db\Retention;
use FxInstallments\Http\Request;
use FxInstallments\Tests\Support\MockUpstream;
use FxInstallments\Tests\Support\TestEnvironment;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * P1-09 acceptance (docs/PLAN.md §10): "following `INSTALL_LOCAL.md` on a clean checkout
 * reaches a working `/convert` with demo data, without Docker", and "the CLI exits 1 on a
 * corrupt schema version".
 *
 * - **Corrupt schema version.** Every `bin/` CLI that opens the database, and `db/seed_demo.php`,
 *   is run on a database whose `app_state.schema_version` is text or missing (SchemaCorrupt,
 *   ADR 0020). `report.php`, `bot.php`, `ip.php list`, `upstream.php status` and `seed_demo.php`
 *   exit 1 naming only the class; `migrate.php` and `migrate.php --prune` exit 1 with the class
 *   and its fixed message (their contract), and nothing is written. `migrate.php --dump`
 *   (in-memory, it writes `api/db/schema.sql`), `lint.php` and `install-hooks.php` never open
 *   the database, so they are not run.
 * - **The walkthrough, automated.** The commands are read from the code blocks of
 *   `docs/INSTALL_LOCAL.md` and run as written, from the repository root, on a temp database
 *   (DB_DSN replaces the default `api/var/app.db`): migrate, seed_demo, report. Then the
 *   documented `curl` URL is answered in-process with a mocked upstream (ADR 0018) in place of
 *   the owner's real key. So the doc and this test can't drift apart without failing it.
 * - **The REPORTS example** in `docs/REPORTS.md` is checked against a real report on demo data,
 *   block by block.
 * - **seed_demo's contract:** only `events`, `sessions` and `requests`; no rates; documentation
 *   IPs only; integers only; refuses a second run, a missing file and an old schema.
 *
 * Every subprocess runs with ADR 0018's gate flags (`-d allow_url_fopen=0 -d disable_functions=
 * curl_exec,curl_multi_exec`), APP_ENV=test and a canary key behind a closed proxy port.
 */
final class P1_09_SeedAndDocsTest extends TestCase
{
    private const GATE_FLAGS = ['-d', 'allow_url_fopen=0', '-d', 'disable_functions=curl_exec,curl_multi_exec'];

    /** The documentation ranges of RFC 5737. */
    private const DOC_RANGES = ['192.0.2.', '198.51.100.', '203.0.113.'];

    private const FIXED_NOTES = [
        'The funnel counts the sessions that started in the period, followed to their end.',
        'Upstream calls are /latest/USD refreshes (miss and stale lookups, except reason no_key) and /quota calls from the admin health view; /codes is never called in Phase 1.',
        'Each error response is counted once (its error event); a /convert error\'s api.request event is not counted again.',
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function corruptions(): iterable
    {
        yield 'text version' => ['garbage'];
        yield 'no version row' => ['missing'];
    }

    #[DataProvider('corruptions')]
    public function testEveryCliThatOpensTheDatabaseExitsOneOnACorruptSchemaVersion(string $corruption): void
    {
        $path = TestEnvironment::freshDatabasePath();
        $pdo = TestEnvironment::connect($path);
        if ($corruption === 'missing') {
            $pdo->exec("DELETE FROM app_state WHERE key = 'schema_version'");
            $message = 'app_state exists but has no schema_version row';
        } else {
            $pdo->exec("UPDATE app_state SET value = 'garbage' WHERE key = 'schema_version'");
            $message = 'app_state.schema_version is not a non-negative integer';
        }
        $before = self::snapshot($pdo);

        $class = 'FxInstallments\\Db\\SchemaCorrupt';
        foreach ([
            ['bin/report.php', [], "report: failed: {$class}\n"],
            ['bin/bot.php', [], "bot: failed: {$class}\n"],
            ['bin/ip.php', ['list'], "ip: failed: {$class}\n"],
            // Round 2: the write subcommands refuse before writing anything too.
            ['bin/ip.php', ['block', '192.0.2.1'], "ip: failed: {$class}\n"],
            ['bin/ip.php', ['unblock', '192.0.2.1'], "ip: failed: {$class}\n"],
            ['bin/upstream.php', ['status'], "upstream: failed: {$class}\n"],
            ['bin/upstream.php', ['reset'], "upstream: failed: {$class}\n"],
            ['db/seed_demo.php', [], "seed_demo: failed: {$class}\n"],
            ['bin/migrate.php', [], "migrate: failed: {$class}: {$message}\n"],
            ['bin/migrate.php', ['--prune'], "migrate: failed: {$class}: {$message}\n"],
        ] as [$script, $args, $stderr]) {
            self::assertSame([1, '', $stderr], self::runScript($script, $args, $path), $script . ' ' . implode(' ', $args));
        }

        self::assertSame($before, self::snapshot($pdo), 'no CLI changed the corrupt database');
    }

    public function testTheInstallWalkthroughReachesAWorkingConvertWithDemoData(): void
    {
        $commands = self::installCommands();
        foreach (['php api/bin/migrate.php', 'php api/db/seed_demo.php', 'php api/bin/report.php'] as $command) {
            self::assertContains($command, $commands, "INSTALL_LOCAL.md shows `{$command}`");
        }
        // Every php script the doc names exists, so no copied command points nowhere.
        foreach ($commands as $command) {
            if (preg_match('~^php (?:-d \S+ )*(api/\S+\.php)~', $command, $match) === 1) {
                self::assertFileExists(self::repoRoot() . '/' . $match[1], $command);
            }
        }

        // Round 2: every output block the doc shows is compared with the real output.
        $path = TestEnvironment::tempDir('walkthrough') . '/app.db';
        [$exit, $out, $err] = self::runDocCommand('php api/bin/migrate.php', $path);
        self::assertSame([0, ''], [$exit, $err]);
        self::assertSame(self::installBlock('output: migrate') . "\n", $out, 'INSTALL_LOCAL.md migrate output');

        [$exit, $out, $err] = self::runDocCommand('php api/bin/migrate.php --prune', $path);
        self::assertSame([0, ''], [$exit, $err]);
        self::assertSame(self::installBlock('output: prune') . "\n", $out, 'INSTALL_LOCAL.md prune output');

        [$exit, $out, $err] = self::runDocCommand('php api/db/seed_demo.php', $path);
        self::assertSame([0, ''], [$exit, $err]);
        // The dates are the default report period, which moves with the day.
        $undated = static fn (string $text): string => (string) preg_replace('/\d{4}-\d{2}-\d{2}/', 'YYYY-MM-DD', $text);
        self::assertSame($undated(self::installBlock('output: seed_demo') . "\n"), $undated($out), 'INSTALL_LOCAL.md seed_demo output');
        self::assertMatchesRegularExpression('~^seed_demo: wrote \d+ sessions, \d+ events and \d+ request-log rows, \d{4}-\d{2}-\d{2} to \d{4}-\d{2}-\d{2} \(America/Bogota\)\n$~D', $out);

        // The ids INSTALL_LOCAL.md gives for the two open sessions, whose later expiry events
        // carry no demo marker.
        $open = TestEnvironment::connect($path)->query("SELECT session_id FROM sessions WHERE status = 'open' ORDER BY channel DESC")->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(2, $open);
        $install = (string) file_get_contents(self::repoRoot() . '/docs/INSTALL_LOCAL.md');
        self::assertStringContainsString("`{$open[0]}` (static) and\n`{$open[1]}` (cli)", $install);

        [$exit, $table, $err] = self::runDocCommand('php api/bin/report.php', $path);
        self::assertSame([0, ''], [$exit, $err]);
        self::assertMatchesRegularExpression('/^  Sessions started +14$/m', $table);
        self::assertMatchesRegularExpression('/^  Conversions +11$/m', $table);
        self::assertMatchesRegularExpression('/^  USD converted +6,089\.88$/m', $table);
        self::assertMatchesRegularExpression('/^  started 9, completed 4, abandoned 3, never answered 1, still open 1$/m', $table);
        self::assertMatchesRegularExpression('/^  started 5, completed 2, abandoned 2, never answered 0, still open 1$/m', $table);
        self::assertStringNotContainsString('other', $table, 'demo data has no label outside its allow-list');
        self::assertStringNotContainsString('no stored rate', $table);
        foreach (self::DOC_RANGES as $range) {
            self::assertStringNotContainsString($range, $table, 'a report never shows an IP');
        }

        $report = self::jsonReport($path);
        self::assertSame(self::FIXED_NOTES, $report['notes'], 'demo data adds no note');
        self::assertSame([
            'sessions_started' => 14, 'sessions_completed' => 6, 'sessions_abandoned' => 6, 'conversions' => 11,
            'amount_usd_cents' => 608_988, 'amount_usd' => '6,089.88', 'api_requests' => 7, 'error_responses' => 11,
            'upstream_calls' => 0, 'conversions_api' => 5, 'conversions_bot' => 6, 'unreadable_amounts' => 0,
            'api_errors' => 2, 'bot_errors' => 17, 'conversions_missing_rate' => 0,
        ], $report['summary']);
        self::assertSame(7, count($report['days']));
        self::assertSame(14, array_sum(array_column($report['days'], 'sessions_started')));
        self::assertSame(['BRL', 'COP', 'EUR', 'JPY', 'MXN'], array_column($report['currencies'], 'currency'));
        self::assertSame(['Brazil', 'Colombia', 'Japan', 'Mexico'], array_column($report['countries'], 'country'));
        self::assertSame(
            ['ADMIN_UNAUTHORIZED', 'BOT_MAX_RETRIES', 'BOT_SESSION_EXPIRED', 'NOT_FOUND', 'RATE_LIMITED', 'VALIDATION_AMOUNT', 'VALIDATION_CURRENCY', 'VALIDATION_INSTALLMENTS'],
            array_column($report['errors'], 'code'),
        );
        self::assertSame(['total' => 97, 'blocked' => 2], ['total' => $report['requests']['total'], 'blocked' => $report['requests']['blocked']]);
        self::assertSame([['block_source' => 'auto', 'reason' => 'rate_limit_hits', 'count' => 1, 'extended' => 0]], $report['ip_blocks']['blocks']);
        self::assertSame([['block_source' => 'auto', 'count' => 1]], $report['ip_blocks']['unblocks']);

        $funnel = array_column($report['funnel'], null, 'channel');
        self::assertSame([9, 4, 3, 1, 1], [$funnel['static']['started'], $funnel['static']['completed'], $funnel['static']['abandoned'], $funnel['static']['never_answered'], $funnel['static']['open']]);
        self::assertSame([5, 2, 2, 0, 1], [$funnel['cli']['started'], $funnel['cli']['completed'], $funnel['cli']['abandoned'], $funnel['cli']['never_answered'], $funnel['cli']['open']]);
        foreach ($report['funnel'] as $channel) {
            self::assertSame(['amount', 'currency', 'installments', 'result'], array_column($channel['steps'], 'step'), 'no step outside the flow');
            foreach ($channel['steps'] as $step) {
                // The funnel identity of ADR 0023 item 6.
                self::assertSame($step['reached'], ($step['passed'] ?? $step['completed']) + $step['abandoned'] + $step['never_answered'] + $step['open']);
            }
        }
        $latest = array_column($report['upstream']['endpoints'], null, 'endpoint')['latest'];
        self::assertSame([11, 11, 0], [$latest['lookups'], $latest['hit'], $latest['calls']], 'demo lookups are hits: no upstream call');

        // The documented curl, answered in-process; a mocked upstream stands in for the real key.
        $urls = self::curlUrls();
        self::assertNotSame([], $urls, 'INSTALL_LOCAL.md shows a /convert curl');
        self::assertCount(1, array_unique($urls), 'every curl line asks the same /convert');
        $url = parse_url($urls[0]);
        parse_str((string) $url['query'], $query);
        $upstream = new MockUpstream([MockUpstream::latest()]);
        $log = new ArrayObject();
        $pdo = TestEnvironment::connect($path);
        $app = new App(new ConfigRepository(), Connection::wrap($pdo), null, static function (string $line) use ($log): void {
            $log[] = $line;
        }, $upstream->client());
        $response = $app->kernel()->handle(new Request('GET', (string) $url['path'], $query, remoteAddr: '127.0.0.1'));

        self::assertSame(200, $response->status(), $response->body());
        self::assertSame(self::installBlock('output: convert'), $response->body(), 'INSTALL_LOCAL.md sample /convert answer');
        self::assertSame(1, $upstream->calls(), 'one /latest/USD call, as the walkthrough says');
        self::assertSame([], $log->getArrayCopy());
        unset($app, $pdo);

        [$exit, $table, $err] = self::runDocCommand('php api/bin/report.php', $path);
        self::assertSame([0, ''], [$exit, $err]);
        foreach (explode("\n", self::installBlock('output: report after the conversion')) as $line) {
            self::assertStringContainsString("\n{$line}\n", $table, 'INSTALL_LOCAL.md report lines after the conversion');
        }

        $after = self::jsonReport($path);
        self::assertSame([14, 12, 6, 8], [$after['summary']['sessions_started'], $after['summary']['conversions'], $after['summary']['conversions_api'], $after['summary']['api_requests']]);
        self::assertSame(1, array_column($after['upstream']['endpoints'], null, 'endpoint')['latest']['calls'], 'the real call is the latest row\'s only call');
    }

    public function testTheReportsExampleIsCopiedFromARealRun(): void
    {
        $doc = (string) file_get_contents(self::repoRoot() . '/docs/REPORTS.md');
        preg_match_all('/<!-- seed_demo example: (table|csv) -->\n```text\n(.*?)\n```/s', $doc, $blocks, PREG_SET_ORDER);
        self::assertSame(['table', 'csv'], array_values(array_unique(array_column($blocks, 1))), 'REPORTS.md has a table and a CSV example');

        $path = TestEnvironment::tempDir('example') . '/app.db';
        self::assertSame(0, self::runScript('bin/migrate.php', [], $path)[0]);
        self::assertSame(0, self::runScript('db/seed_demo.php', [], $path)[0]);
        $outputs = [
            'table' => self::runScript('bin/report.php', [], $path)[1],
            'csv' => str_replace("\r\n", "\n", self::runScript('bin/report.php', ['--format=csv'], $path)[1]),
        ];
        foreach ($blocks as [, $format, $block]) {
            foreach (preg_split('/\n\n+/', $block) ?: [] as $part) {
                self::assertStringContainsString($part . "\n", $outputs[$format], "REPORTS.md {$format} example");
            }
        }
    }

    public function testSeedDemoWritesOnlyDemoRowsWithDocumentationIpsAndIntegers(): void
    {
        $path = TestEnvironment::freshDatabasePath();
        self::assertSame(0, self::runScript('db/seed_demo.php', [], $path)[0]);
        $pdo = TestEnvironment::connect($path);

        // No rates, no breaker or quota state, no blocks or rate-limit windows (owner decision).
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM rate_cache')->fetchColumn());
        self::assertSame(['schema_version'], $pdo->query('SELECT key FROM app_state')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM ip_blocks')->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM rate_limits')->fetchColumn());

        foreach ($pdo->query('SELECT DISTINCT ip FROM requests')->fetchAll(PDO::FETCH_COLUMN) as $ip) {
            self::assertTrue(self::isDocumentationIp((string) $ip), "{$ip} is a documentation address");
        }
        $events = $pdo->query('SELECT * FROM events')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($events as $event) {
            foreach (self::DOC_RANGES as $range) {
                self::assertStringNotContainsString($range, (string) json_encode($event), 'no event holds an IP');
            }
            $meta = json_decode((string) $event['meta'], true, 8, JSON_THROW_ON_ERROR);
            self::assertTrue($meta['demo'], 'every demo event is marked');
            array_walk_recursive($meta, static function (mixed $value): void {
                self::assertFalse(is_float($value), 'no float in meta');
            });
        }
        foreach (['events' => ['ts', 'amount_usd_cents', 'installments', 'latency_ms'], 'sessions' => ['retries', 'created_at', 'updated_at', 'expires_at'], 'requests' => ['ts', 'status', 'latency_ms', 'blocked']] as $table => $columns) {
            foreach ($columns as $column) {
                $types = $pdo->query("SELECT DISTINCT typeof({$column}) FROM {$table}")->fetchAll(PDO::FETCH_COLUMN);
                self::assertSame([], array_diff($types, ['integer', 'null']), "{$table}.{$column} holds integers only");
            }
        }
        $collected = $pdo->query('SELECT collected FROM sessions')->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(14, $collected);

        // Deterministic: a second new database gets the same rows (only the times move).
        $second = TestEnvironment::freshDatabasePath();
        self::assertSame(0, self::runScript('db/seed_demo.php', [], $second)[0]);
        self::assertSame(self::fingerprint($pdo), self::fingerprint(TestEnvironment::connect($second)));

        // A second run on the same database is refused and writes nothing.
        $before = self::snapshot($pdo);
        self::assertSame([1, '', "seed_demo: failed: the database already has events; demo data goes only into a new database\n"], self::runScript('db/seed_demo.php', [], $path));
        self::assertSame($before, self::snapshot($pdo));
    }

    public function testSeedDemoRefusesAMissingFileAnOldSchemaAndBadArguments(): void
    {
        $missing = TestEnvironment::tempDir('seed-missing') . '/none.db';
        self::assertSame([1, '', "seed_demo: failed: no database file (run php api/bin/migrate.php first)\n"], self::runScript('db/seed_demo.php', [], $missing));
        self::assertFileDoesNotExist($missing);

        $empty = TestEnvironment::tempDir('seed-empty') . '/empty.db';
        touch($empty);
        self::assertSame([1, '', "seed_demo: failed: FxInstallments\\Db\\SchemaOutOfDate\n"], self::runScript('db/seed_demo.php', [], $empty));
        // Opening the file sets WAL mode (a header), as every CLI does; no table was created.
        self::assertSame(0, (int) TestEnvironment::connect($empty)->query('SELECT COUNT(*) FROM sqlite_master')->fetchColumn(), 'nothing was written to the old database');

        $path = TestEnvironment::freshDatabasePath();
        TestEnvironment::connect($path)->exec("INSERT INTO sessions (session_id, channel, step, status, created_at, updated_at, expires_at) VALUES ('" . str_repeat('a', 32) . "', 'cli', 'amount', 'open', 1, 1, 2)");
        self::assertSame([1, '', "seed_demo: failed: the database already has sessions; demo data goes only into a new database\n"], self::runScript('db/seed_demo.php', [], $path));

        foreach ([['--force'], ['--help', '--help'], ['x']] as $args) {
            self::assertSame([2, '', "usage: php api/db/seed_demo.php\n"], self::runScript('db/seed_demo.php', $args, $path), implode(' ', $args));
        }
        self::assertSame([0, "usage: php api/db/seed_demo.php\n", ''], self::runScript('db/seed_demo.php', ['--help'], $path));
    }

    public function testSeedDemoNeitherRoundsNorUsesFloats(): void
    {
        // INV-01 and INV-09 for the seed script (RoundingRuleTest scans api/src only).
        foreach (token_get_all((string) file_get_contents(dirname(__DIR__, 2) . '/db/seed_demo.php')) as $token) {
            if (!is_array($token)) {
                continue;
            }
            self::assertNotSame(T_DNUMBER, $token[0], 'no float literal: ' . $token[1]);
            self::assertNotSame(T_DOUBLE_CAST, $token[0], 'no (float) cast');
            if ($token[0] === T_STRING) {
                self::assertNotContains(strtolower($token[1]), ['round', 'number_format', 'floatval', 'floor', 'ceil'], 'no rounding call');
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function failingWrites(): iterable
    {
        // Each trigger lets the seed get partway, then refuses one insert.
        yield 'an event' => ["CREATE TRIGGER p1_09_fail BEFORE INSERT ON events WHEN (SELECT COUNT(*) FROM events) >= 40 BEGIN SELECT RAISE(ABORT, 'p1-09 test'); END"];
        yield 'a request-log row' => ["CREATE TRIGGER p1_09_fail BEFORE INSERT ON requests WHEN (SELECT COUNT(*) FROM requests) >= 50 BEGIN SELECT RAISE(ABORT, 'p1-09 test'); END"];
        yield 'a session row' => ["CREATE TRIGGER p1_09_fail BEFORE INSERT ON sessions WHEN (SELECT COUNT(*) FROM sessions) >= 5 BEGIN SELECT RAISE(ABORT, 'p1-09 test'); END"];
    }

    #[DataProvider('failingWrites')]
    public function testAWriteThatFailsPartwayLeavesNothing(string $trigger): void
    {
        $path = TestEnvironment::freshDatabasePath();
        $pdo = TestEnvironment::connect($path);
        $pdo->exec($trigger);

        [$exit, $out, $err] = self::runScript('db/seed_demo.php', [], $path);

        self::assertSame([1, ''], [$exit, $out]);
        self::assertMatchesRegularExpression('/^seed_demo: failed: (RuntimeException|PDOException)\n$/D', $err);
        foreach (['events', 'sessions', 'requests'] as $table) {
            self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn(), "{$table}: the transaction rolled back");
        }
    }

    public function testConversionMetaMatchesWhatTheAppWouldStore(): void
    {
        $path = TestEnvironment::freshDatabasePath();
        self::assertSame(0, self::runScript('db/seed_demo.php', [], $path)[0]);
        $pdo = TestEnvironment::connect($path);

        $rows = $pdo->query("SELECT ts, event_type, currency, amount_usd_cents, installments, meta FROM events WHERE event_type IN ('api.request', 'bot.session_completed') AND status = 'ok'")->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(11, $rows);
        $templates = [];
        foreach ($rows as $row) {
            $meta = json_decode((string) $row['meta'], true, 8, JSON_THROW_ON_ERROR);
            $ts = (int) $row['ts'];
            // Upstream updates at 00:00:01 UTC; the stored time is the last update before the conversion.
            self::assertLessThanOrEqual($ts, $meta['rate_as_of'], 'rate_as_of is not after the conversion');
            self::assertGreaterThan($ts - 86_400, $meta['rate_as_of'], 'rate_as_of is the latest daily update');
            self::assertSame(1, $meta['rate_as_of'] % 86_400);
            self::assertIsInt($meta['rate_scaled']);
            if ($row['event_type'] !== 'bot.session_completed') {
                continue;
            }
            $display = InstallmentCalculator::display((int) $row['amount_usd_cents'], $meta['rate_scaled'], (int) $row['installments'], (string) $row['currency'], $meta['rate_as_of']);
            $expected = (int) $row['installments'] === 1 ? 'single' : ($display->even ? 'even' : 'uneven');
            self::assertSame($expected, $meta['template'], "the template the flow engine would pick for {$row['currency']}");
            $templates[] = $meta['template'];
        }
        sort($templates);
        self::assertSame(['even', 'single', 'single', 'uneven', 'uneven', 'uneven'], $templates, 'the demo shows every result template');
    }

    public function testTheBackupLineInTheGuideMakesAConsistentCopy(): void
    {
        $line = self::installBlock('command: backup');
        self::assertMatchesRegularExpression('/^php -r "[^"]+"$/D', $line, 'one php -r line without inner double quotes');
        self::assertStringNotContainsString('$', $line, 'nothing a PowerShell or POSIX double-quoted string would expand');
        self::assertStringNotContainsString('`', $line);
        $code = substr($line, strlen('php -r "'), -1);

        // A first install: nothing to back up, and no database file is created.
        $root = TestEnvironment::tempDir('backup-root');
        self::assertSame([0, 'nothing to back up' . PHP_EOL, ''], self::runIn([PHP_BINARY, ...self::GATE_FLAGS, '-r', $code], $root));
        self::assertDirectoryDoesNotExist($root . '/api');

        // A seeded database: one self-contained copy with the same rows.
        mkdir($root . '/api/var', 0777, true);
        $path = $root . '/api/var/app.db';
        self::assertSame(0, self::runScript('bin/migrate.php', [], $path)[0]);
        self::assertSame(0, self::runScript('db/seed_demo.php', [], $path)[0]);
        self::assertSame([0, 'backed up' . PHP_EOL, ''], self::runIn([PHP_BINARY, ...self::GATE_FLAGS, '-r', $code], $root));
        $copies = glob($root . '/api/var/app.db.bak-*') ?: [];
        self::assertCount(1, $copies);
        self::assertMatchesRegularExpression('/app\.db\.bak-\d{8}-\d{6}$/', $copies[0]);
        self::assertFileDoesNotExist($copies[0] . '-wal');
        $copy = TestEnvironment::connect($copies[0]);
        self::assertSame('ok', $copy->query('PRAGMA integrity_check')->fetchColumn());
        self::assertSame(self::snapshot(TestEnvironment::connect($path)), self::snapshot($copy));
    }

    public function testAShellDbDsnWinsOverTheDotenvFile(): void
    {
        // INSTALL_LOCAL.md section 6 relies on this: Bootstrap loads `.env` with
        // Dotenv::createImmutable(), and the app reads variables through Env. A fixture file
        // stands in for `.env`, which tests never touch.
        $dir = TestEnvironment::tempDir('dotenv');
        file_put_contents($dir . '/fixture.env', "DB_DSN=sqlite:from-the-file.db\nFX_P1_09_FILE_ONLY=from-the-file\n");
        $code = 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'
            . ' Dotenv\Dotenv::createImmutable(' . var_export($dir, true) . ", 'fixture.env')->safeLoad();"
            . " echo FxInstallments\\Env::get('DB_DSN'), ' ', FxInstallments\\Env::get('FX_P1_09_FILE_ONLY'), PHP_EOL;";
        foreach (['GPCS', 'EGPCS'] as $order) {
            self::assertSame(
                [0, 'sqlite:from-the-shell.db from-the-file' . PHP_EOL, ''],
                self::runIn([PHP_BINARY, ...self::GATE_FLAGS, '-d', "variables_order={$order}", '-r', $code], $dir, ['DB_DSN' => 'sqlite:from-the-shell.db']),
                "variables_order={$order}",
            );
        }
    }

    public function testConfigMistakesFailAsTheInstallGuideSays(): void
    {
        // Read while each request is wired (App::kernel()), so index.php answers 500 to all.
        foreach ([
            ['ratelimit.per_minute' => '30'], ['ratelimit.per_minute' => 0], ['cors.allowed_origins' => 'https://a.example'],
            ['reports.timezone' => 5], ['rates.max_stale_hours' => '48'], ['health.quota_cache_minutes' => '60'],
            ['block.window_minutes' => 0], ['block.window_minutes' => 1441], ['block.rate_limit_hits' => 0],
            ['block.error_responses' => 0], ['block.duration_minutes' => 525_601], ['block.duration_minutes' => '60'],
        ] as $override) {
            try {
                TestEnvironment::app(config: $override)->kernel();
                self::fail('kernel() accepted ' . json_encode($override));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        // A key read only on use leaves the kernel buildable.
        TestEnvironment::app(config: ['amount.max' => '1000000'])->kernel();

        // --prune: Retention and the config getter refuse what the guide says.
        $pdo = TestEnvironment::connect(TestEnvironment::freshDatabasePath());
        foreach ([[0, 10], [30, 0], [30, 1441]] as [$days, $window]) {
            try {
                new Retention($pdo, $days, $window);
                self::fail("Retention accepted {$days} days, {$window} minutes");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        (new ConfigRepository(['requests.retention_days' => '30']))->int('requests.retention_days');
    }

    /**
     * The fenced block right after `<!-- $marker -->` in INSTALL_LOCAL.md, without its last
     * line break.
     */
    private static function installBlock(string $marker): string
    {
        $doc = (string) file_get_contents(self::repoRoot() . '/docs/INSTALL_LOCAL.md');
        $count = preg_match_all('/<!-- ' . preg_quote($marker, '/') . ' -->\n```[a-z]*\n(.*?)\n```/s', $doc, $match);
        self::assertSame(1, $count, "INSTALL_LOCAL.md has one block marked `{$marker}`");

        return $match[1][0];
    }

    /**
     * Every line inside a fenced code block of INSTALL_LOCAL.md, trimmed.
     *
     * @return list<string>
     */
    private static function installCommands(): array
    {
        $doc = (string) file_get_contents(self::repoRoot() . '/docs/INSTALL_LOCAL.md');
        preg_match_all('/^ *```[a-z]*\n(.*?)^ *```$/ms', $doc, $blocks);
        $lines = [];
        foreach ($blocks[1] as $block) {
            foreach (explode("\n", $block) as $line) {
                if (trim($line) !== '') {
                    $lines[] = trim($line);
                }
            }
        }

        return $lines;
    }

    /**
     * The URLs of the doc's `curl` / `curl.exe` lines for `/convert`.
     *
     * @return list<string>
     */
    private static function curlUrls(): array
    {
        $urls = [];
        foreach (self::installCommands() as $line) {
            if (preg_match('~^curl(?:\.exe)? "(http://127\.0\.0\.1:8080/convert\?[^"]+)"$~', $line, $match) === 1) {
                $urls[] = $match[1];
            }
        }

        return $urls;
    }

    /**
     * Runs a documented `php api/...` command as written (gate flags added), from the
     * repository root, with DB_DSN on $path.
     *
     * @return array{int, string, string}
     */
    private static function runDocCommand(string $command, string $path): array
    {
        $words = explode(' ', $command);
        self::assertSame('php', $words[0]);
        self::assertStringStartsWith('api/', $words[1]);

        return self::runProcess([PHP_BINARY, ...self::GATE_FLAGS, ...array_slice($words, 1)], $path, self::repoRoot());
    }

    /**
     * @param list<string> $args
     * @return array{int, string, string}
     */
    private static function runScript(string $script, array $args, string $path): array
    {
        return self::runProcess([PHP_BINARY, ...self::GATE_FLAGS, dirname(__DIR__, 2) . '/' . $script, ...$args], $path, null);
    }

    /**
     * A command in $cwd with the test environment plus $env (no DB_DSN of its own).
     *
     * @param list<string> $command
     * @param array<string, string> $env
     * @return array{int, string, string}
     */
    private static function runIn(array $command, string $cwd, array $env = []): array
    {
        return self::runProcess($command, null, $cwd, $env);
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $extra
     * @return array{int, string, string}
     */
    private static function runProcess(array $command, ?string $path, ?string $cwd, array $extra = []): array
    {
        $dir = TestEnvironment::tempDir('p1-09-cli');
        file_put_contents($dir . '/in', '');
        $env = getenv();
        $env['APP_ENV'] = 'test';
        unset($env['DB_DSN']);
        if ($path !== null) {
            $env['DB_DSN'] = 'sqlite:' . $path;
        }
        $env = $extra + $env;
        $env['EXCHANGERATE_API_KEY'] = MockUpstream::CANARY_KEY;
        $env['HTTPS_PROXY'] = $env['HTTP_PROXY'] = $env['https_proxy'] = $env['http_proxy'] = 'http://127.0.0.1:9';
        unset($env['NO_PROXY'], $env['no_proxy']);
        $process = proc_open(
            $command,
            [0 => ['file', $dir . '/in', 'r'], 1 => ['file', $dir . '/out', 'w'], 2 => ['file', $dir . '/err', 'w']],
            $pipes,
            $cwd ?? $dir,
            $env,
        );
        self::assertIsResource($process, 'could not start ' . implode(' ', $command));
        $exit = proc_close($process);

        return [$exit, (string) file_get_contents($dir . '/out'), (string) file_get_contents($dir . '/err')];
    }

    /**
     * @return array<string, mixed>
     */
    private static function jsonReport(string $path): array
    {
        [$exit, $json, $err] = self::runScript('bin/report.php', ['--format=json'], $path);
        self::assertSame([0, ''], [$exit, $err]);

        return json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    }

    /**
     * Every table's rows, for "nothing changed" checks.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private static function snapshot(PDO $pdo): array
    {
        $tables = [];
        foreach (['app_state', 'events', 'sessions', 'requests', 'rate_cache', 'rate_limits', 'ip_blocks'] as $table) {
            $tables[$table] = $pdo->query("SELECT * FROM {$table} ORDER BY 1, 2")->fetchAll(PDO::FETCH_ASSOC);
        }

        return $tables;
    }

    /**
     * The seeded rows without their times.
     *
     * @return array<string, list<string>>
     */
    private static function fingerprint(PDO $pdo): array
    {
        return [
            'events' => $pdo->query("SELECT event_type || '|' || source || '|' || COALESCE(channel, '') || '|' || COALESCE(session_id, '') || '|' || COALESCE(step, '') || '|' || COALESCE(currency, '') || '|' || COALESCE(country, '') || '|' || COALESCE(amount_usd_cents, '') || '|' || COALESCE(installments, '') || '|' || status || '|' || COALESCE(error_code, '') FROM events ORDER BY id")->fetchAll(PDO::FETCH_COLUMN),
            'sessions' => $pdo->query("SELECT session_id || '|' || channel || '|' || step || '|' || status || '|' || collected || '|' || retries FROM sessions ORDER BY session_id")->fetchAll(PDO::FETCH_COLUMN),
            'requests' => $pdo->query("SELECT ip || '|' || method || '|' || path || '|' || status || '|' || blocked FROM requests ORDER BY id")->fetchAll(PDO::FETCH_COLUMN),
        ];
    }

    private static function isDocumentationIp(string $ip): bool
    {
        foreach (self::DOC_RANGES as $range) {
            if (str_starts_with($ip, $range) && preg_match('/^\d{1,3}$/D', substr($ip, strlen($range))) === 1 && (int) substr($ip, strlen($range)) <= 255) {
                return true;
            }
        }

        return false;
    }

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
