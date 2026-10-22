<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Acceptance;

use ArrayObject;
use FxInstallments\App;
use FxInstallments\Config\ConfigRepository;
use FxInstallments\Db\Connection;
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
            ['bin/upstream.php', ['status'], "upstream: failed: {$class}\n"],
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

        $path = TestEnvironment::tempDir('walkthrough') . '/app.db';
        [$exit, $out, $err] = self::runDocCommand('php api/bin/migrate.php', $path);
        self::assertSame([0, ''], [$exit, $err]);
        self::assertSame(
            "migrate: applied 1 p1_01_baseline\nmigrate: applied 2 phase1_tables\nmigrate: applied 3 events_amount_usd_cents\nmigrate: schema version 0 -> 3\n",
            $out,
        );

        [$exit, $out, $err] = self::runDocCommand('php api/db/seed_demo.php', $path);
        self::assertSame([0, ''], [$exit, $err]);
        self::assertMatchesRegularExpression('~^seed_demo: wrote 14 sessions, 92 events and 97 request-log rows, \d{4}-\d{2}-\d{2} to \d{4}-\d{2}-\d{2} \(America/Bogota\)\n$~D', $out);

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
        $data = json_decode($response->body(), true, 8, JSON_THROW_ON_ERROR)['data'];
        self::assertSame(['200.00', 'BRL', '1,045.82', '348.60', '348.62', 2], [
            $data['display']['amount_usd'], $data['currency'], $data['display']['converted'],
            $data['display']['installment'], $data['display']['last_installment'], $data['display']['regular_count'],
        ]);
        self::assertSame(1, $upstream->calls(), 'one /latest/USD call, as the walkthrough says');
        self::assertSame([], $log->getArrayCopy());
        unset($app, $pdo);

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
     * @param list<string> $command
     * @return array{int, string, string}
     */
    private static function runProcess(array $command, string $path, ?string $cwd): array
    {
        $dir = TestEnvironment::tempDir('p1-09-cli');
        file_put_contents($dir . '/in', '');
        $env = getenv();
        $env['APP_ENV'] = 'test';
        $env['DB_DSN'] = 'sqlite:' . $path;
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
