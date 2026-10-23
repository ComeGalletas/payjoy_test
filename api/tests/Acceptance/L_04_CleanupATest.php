<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Acceptance;

use DateTimeImmutable;
use DateTimeZone;
use DemoWriter;
use FxInstallments\App;
use FxInstallments\Config\ConfigRepository;
use FxInstallments\Db\Connection;
use FxInstallments\Db\DatabaseFileMissing;
use FxInstallments\Tests\Support\BuiltInServer;
use FxInstallments\Tests\Support\MockUpstream;
use FxInstallments\Tests\Support\TestEnvironment;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SeedDemoRefused;

require_once dirname(__DIR__, 2) . '/db/seed_demo.php';

/**
 * L-04 cleanup A (docs/PLAN.md L-04): correctness and safety, through the real entry points.
 *
 * - **A missing SQLite file** is refused, and never created, by every CLI that opens the
 *   database and by `public/index.php`; only plain `migrate.php` creates it, folder included,
 *   whatever the SQLite path.
 * - **An empty file stays empty:** the schema is checked before WAL mode is set, so a refused
 *   database gets no header either.
 * - **`seed_demo.php`** records the configured `block.duration_minutes`, refuses a
 *   `bot.session_ttl_minutes` that would place a row in the future, and keeps its fixed local
 *   times on a daylight-saving change day.
 *   (The demo numbers of the docs, with the default config, are P1_09_SeedAndDocsTest's.)
 *
 * The unit tests carry the rest of the list (ConfigRepositoryTest, ClientIpTest,
 * RateLimiterRaceTest, MetricsTest, FlowEngineTest, KernelBlockingTest, CsvWriterTest,
 * SmokeTest for the PHP floor). Every subprocess runs with ADR 0018's gate flags, APP_ENV=test
 * and a canary key behind a closed proxy port; `php -S` runs only through BuiltInServer.
 */
final class L_04_CleanupATest extends TestCase
{
    private const GATE_FLAGS = ['-d', 'allow_url_fopen=0', '-d', 'disable_functions=curl_exec,curl_multi_exec'];

    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function databaseClis(): iterable
    {
        yield 'report' => ['bin/report.php', [], 'report'];
        yield 'bot' => ['bin/bot.php', [], 'bot'];
        yield 'ip list' => ['bin/ip.php', ['list'], 'ip'];
        yield 'ip block' => ['bin/ip.php', ['block', '192.0.2.1'], 'ip'];
        yield 'ip unblock' => ['bin/ip.php', ['unblock', '192.0.2.1'], 'ip'];
        yield 'upstream status' => ['bin/upstream.php', ['status'], 'upstream'];
        yield 'upstream reset' => ['bin/upstream.php', ['reset'], 'upstream'];
        yield 'seed_demo' => ['db/seed_demo.php', [], 'seed_demo'];
        yield 'migrate --prune' => ['bin/migrate.php', ['--prune'], 'prune'];
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('databaseClis')]
    public function testEveryCliRefusesAMissingSqliteFileWithoutCreatingIt(string $script, array $args, string $prefix): void
    {
        $folder = TestEnvironment::tempDir('l04-missing') . '/not-yet';
        $path = $folder . '/app.db';

        self::assertSame(
            [1, '', "{$prefix}: failed: no database file (run php api/bin/migrate.php first)\n"],
            self::runScript($script, $args, $path),
        );
        self::assertDirectoryDoesNotExist($folder, 'neither the file nor its folder was created');
    }

    public function testPlainMigrateStillCreatesTheDatabaseAndItsFolder(): void
    {
        $folder = TestEnvironment::tempDir('l04-create') . '/new/deeper';
        $path = $folder . '/app.db';

        [$exit, $out, $err] = self::runScript('bin/migrate.php', [], $path);

        self::assertSame([0, ''], [$exit, $err]);
        self::assertStringEndsWith("migrate: schema version 0 -> 3\n", $out);
        self::assertSame('wal', TestEnvironment::connect($path)->query('PRAGMA journal_mode')->fetchColumn());
        self::assertSame([0, "migrate: schema is current (version 3)\n", ''], self::runScript('bin/migrate.php', [], $path));
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('databaseClis')]
    public function testEveryCliLeavesAnEmptyFileEmpty(string $script, array $args, string $prefix): void
    {
        $path = TestEnvironment::tempDir('l04-empty') . '/empty.db';
        touch($path);

        [$exit, $out, $err] = self::runScript($script, $args, $path);

        self::assertSame([1, ''], [$exit, $out]);
        self::assertSame(
            $prefix === 'prune'
                ? "prune: Database schema is at version 0, the code needs 3: run php api/bin/migrate.php\n"
                : "{$prefix}: failed: FxInstallments\\Db\\SchemaOutOfDate\n",
            $err,
        );
        clearstatcache();
        self::assertSame(0, filesize($path), 'not even the WAL header was written');
        self::assertFileDoesNotExist($path . '-wal');
    }

    public function testTheEntryPointRefusesAMissingFileWithoutCreatingIt(): void
    {
        $folder = TestEnvironment::tempDir('l04-server') . '/not-yet';
        $path = $folder . '/app.db';
        $server = BuiltInServer::start($path);
        try {
            [$convert, , $convertBody] = $server->request('GET', '/convert?amount=200&cy=BRL');
            [$bot, , $botBody] = $server->request('POST', '/bot/message', ['Content-Type' => 'application/json'], '{"text":"hi"}');
            [$health, , $healthBody] = $server->request('GET', '/health');
        } finally {
            $server->stop();
        }

        $internal = '{"ok":false,"error":{"code":"INTERNAL","message_for_user":"Something went wrong on our side. Please try again later."}}';
        self::assertSame([500, $internal], [$convert, $convertBody], $server->log());
        self::assertSame([500, $internal], [$bot, $botBody]);
        self::assertSame(503, $health);
        self::assertSame('UNHEALTHY', json_decode($healthBody, true, 8, JSON_THROW_ON_ERROR)['error']['code']);
        self::assertStringContainsString('fx-installments INTERNAL before routing: ' . DatabaseFileMissing::class . ' at ', $server->log());
        self::assertDirectoryDoesNotExist($folder, 'no request created the database');
    }

    /**
     * L-04: the demo block records `block.duration_minutes`, and the demo unblock always lifts
     * an active block: 25 minutes in, or 30 seconds before the block would end if that is sooner.
     * The two blocked requests are written only while the block holds.
     *
     * @return iterable<string, array{int, int, list<int>}>
     */
    public static function blockDurations(): iterable
    {
        // [duration, unblock minute:second after the first 429's minute, blocked request minutes]
        yield 'the default hour' => [60, 25 * 60, [6, 9]];
        yield 'fifteen minutes' => [15, 19 * 60, [6, 9]];
        yield 'five minutes' => [5, 9 * 60, [6]];
        yield 'one minute' => [1, 5 * 60, []];
    }

    /**
     * @param list<int> $blockedMinutes
     */
    #[DataProvider('blockDurations')]
    public function testTheDemoBlockFollowsBlockDurationMinutes(int $duration, int $unblockAfter, array $blockedMinutes): void
    {
        [$pdo, $now] = $this->seed(['block.duration_minutes' => $duration]);

        $block = $this->events($pdo, 'ip.block');
        self::assertCount(1, $block);
        self::assertSame($duration, $block[0]['meta']['duration_minutes']);
        $blockedAt = (int) $block[0]['ts'];
        $start = $blockedAt - 4 * 60 - 30;
        self::assertSame(0, $start % 60, 'the first 429 is 30 s into a minute');

        $unblock = $this->events($pdo, 'ip.unblock');
        self::assertCount(1, $unblock);
        self::assertSame($start + $unblockAfter, (int) $unblock[0]['ts']);
        self::assertLessThan($blockedAt + $duration * 60, (int) $unblock[0]['ts'], 'the block was still active');
        self::assertTrue($unblock[0]['meta']['was_active']);

        $blocked = $pdo->query("SELECT ts FROM requests WHERE blocked = 1 ORDER BY ts")->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(array_map(static fn (int $minute): int => $start + $minute * 60, $blockedMinutes), array_map('intval', $blocked));
        self::assertLessThanOrEqual($now, max(array_map('intval', $pdo->query('SELECT ts FROM events')->fetchAll(PDO::FETCH_COLUMN))));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function longTtls(): iterable
    {
        yield 'two days' => [2 * 1440];
        yield 'a week' => [7 * 1440];
        yield 'a year' => [365 * 1440];
    }

    /**
     * L-04 (owner decision 2026-10-22: refuse, don't clamp): a `bot.session_ttl_minutes` so long
     * that a demo session declared to expire would be swept after now is refused with a fixed
     * text naming the longest TTL that works, and nothing is written. That TTL does work.
     */
    #[DataProvider('longTtls')]
    public function testASessionTtlThatWouldPlaceARowInTheFutureIsRefused(int $ttlMinutes): void
    {
        $now = time();
        $pdo = TestEnvironment::connect(TestEnvironment::freshDatabasePath());
        $writer = DemoWriter::fromConfig(Connection::wrap($pdo), new ConfigRepository(['bot.session_ttl_minutes' => $ttlMinutes]), App::DEFAULT_FLOW, $now);

        try {
            $writer->write();
            self::fail('a TTL that long must be refused');
        } catch (SeedDemoRefused $e) {
            self::assertSame(1, preg_match(
                '/^bot\.session_ttl_minutes is ' . $ttlMinutes . ', too long for the demo data: its expired sessions would close after now; seed with at most (\d+)$/D',
                $e->getMessage(),
                $match,
            ), $e->getMessage());
        }
        foreach (['events', 'sessions', 'requests'] as $table) {
            self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn(), "no {$table} row");
        }

        // The longest TTL the message names seeds, with every row at or before now.
        $longest = (int) $match[1];
        self::assertGreaterThanOrEqual(30, $longest, 'the default TTL always fits');
        [$seeded] = $this->seed(['bot.session_ttl_minutes' => $longest], $now);
        foreach (['events' => 'ts', 'requests' => 'ts', 'sessions' => 'updated_at'] as $table => $column) {
            self::assertLessThanOrEqual($now, (int) $seeded->query("SELECT MAX({$column}) FROM {$table}")->fetchColumn(), "no {$table} row is in the future");
        }
        $this->expectException(SeedDemoRefused::class);
        DemoWriter::fromConfig(Connection::wrap(TestEnvironment::connect(TestEnvironment::freshDatabasePath())), new ConfigRepository(['bot.session_ttl_minutes' => $longest + 1]), App::DEFAULT_FLOW, $now)->write();
    }

    public function testSeedDemoStillRunsAsAScript(): void
    {
        // seed_demo.php runs its script part only when PHP starts with it; this file requires
        // it for DemoWriter alone.
        $path = TestEnvironment::freshDatabasePath();
        [$exit, $out, $err] = self::runScript('db/seed_demo.php', [], $path);

        self::assertSame([0, ''], [$exit, $err]);
        self::assertStringStartsWith('seed_demo: wrote 14 sessions, ', $out);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function daylightSavingChanges(): iterable
    {
        // [time zone, now (UTC)]: each `now` puts a change day among the six days before it.
        yield 'Madrid, clocks go back' => ['Europe/Madrid', '2026-10-27 12:00:00'];
        yield 'Madrid, clocks go forward' => ['Europe/Madrid', '2026-03-31 12:00:00'];
        yield 'New York, clocks go back' => ['America/New_York', '2026-11-03 18:00:00'];
        yield 'Santiago, clocks go forward' => ['America/Santiago', '2026-09-08 18:00:00'];
    }

    /**
     * L-04: the demo's fixed local times are local times on every day, a daylight-saving change
     * day included (adding hours to the day's start shifted them by an hour on that day).
     */
    #[DataProvider('daylightSavingChanges')]
    public function testTheDemoTimesHoldOnADaylightSavingChangeDay(string $timezone, string $nowUtc): void
    {
        $zone = new DateTimeZone($timezone);
        $now = (new DateTimeImmutable($nowUtc, new DateTimeZone('UTC')))->getTimestamp();
        [$pdo] = $this->seed(['reports.timezone' => $timezone], $now);

        $today = (new DateTimeImmutable('@' . $now))->setTimezone($zone);
        $offsets = [];
        foreach (range(1, 6) as $days) {
            $offsets[] = $today->modify("-{$days} days")->setTime(12, 0)->getOffset();
        }
        self::assertGreaterThan(1, count(array_unique($offsets)), 'the period holds a change day');

        $local = static fn (int $ts): string => (new DateTimeImmutable('@' . $ts))->setTimezone($zone)->format('Y-m-d H:i');
        $starts = [];
        foreach ($this->events($pdo, 'bot.session_start') as $event) {
            $starts[(string) $event['session_id']] = (int) $event['ts'];
        }
        $checked = 0;
        foreach (SEED_SESSIONS as $index => [, $days, $when]) {
            if ($days === 0) {
                continue;
            }
            $id = substr(hash('sha256', 'fx-installments demo session ' . ($index + 1)), 0, 32);
            self::assertSame($today->modify("-{$days} days")->format('Y-m-d') . ' ' . $when, $local($starts[$id]), "demo session {$id}");
            $checked++;
        }
        self::assertSame(12, $checked);

        $conversions = array_map(static fn (array $event): string => $local((int) $event['ts']), array_values(array_filter(
            $this->events($pdo, 'api.request'),
            static fn (array $event): bool => $event['meta']['endpoint'] === 'convert',
        )));
        $expected = array_map(static fn (array $conversion): string => $today->modify("-{$conversion[0]} days")->format('Y-m-d') . ' ' . $conversion[1], SEED_CONVERSIONS);
        sort($expected);
        sort($conversions);
        self::assertSame($expected, $conversions);
    }

    /**
     * Seeds a fresh database in-process with DemoWriter (seed_demo.php) and config overrides,
     * as the script does, in one transaction.
     *
     * @param array<string, mixed> $config
     * @return array{PDO, int} the database and the seed's now
     */
    private function seed(array $config, ?int $now = null): array
    {
        $pdo = TestEnvironment::connect(TestEnvironment::freshDatabasePath());
        $now ??= time();
        $writer = DemoWriter::fromConfig(Connection::wrap($pdo), new ConfigRepository($config), App::DEFAULT_FLOW, $now);

        $pdo->beginTransaction();
        $counts = $writer->write();
        $pdo->commit();

        self::assertSame(14, $counts['sessions']);

        return [$pdo, $now];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function events(PDO $pdo, string $type): array
    {
        $select = $pdo->prepare('SELECT * FROM events WHERE event_type = ? ORDER BY id');
        $select->execute([$type]);

        return array_map(static function (array $row): array {
            $row['meta'] = $row['meta'] === null ? null : json_decode((string) $row['meta'], true, 8, JSON_THROW_ON_ERROR);

            return $row;
        }, $select->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Runs api/$script with DB_DSN on $path.
     *
     * @param list<string> $args
     * @return array{int, string, string}
     */
    private static function runScript(string $script, array $args, string $path): array
    {
        $dir = TestEnvironment::tempDir('l04-cli');
        file_put_contents($dir . '/in', '');
        $env = getenv();
        $env['APP_ENV'] = 'test';
        $env['DB_DSN'] = 'sqlite:' . $path;
        $env['EXCHANGERATE_API_KEY'] = MockUpstream::CANARY_KEY;
        $env['HTTPS_PROXY'] = $env['HTTP_PROXY'] = $env['https_proxy'] = $env['http_proxy'] = 'http://127.0.0.1:9';
        unset($env['NO_PROXY'], $env['no_proxy']);
        $process = proc_open(
            [PHP_BINARY, ...self::GATE_FLAGS, dirname(__DIR__, 2) . '/' . $script, ...$args],
            [0 => ['file', $dir . '/in', 'r'], 1 => ['file', $dir . '/out', 'w'], 2 => ['file', $dir . '/err', 'w']],
            $pipes,
            $dir,
            $env,
        );
        self::assertIsResource($process, 'could not start ' . $script);
        $exit = proc_close($process);

        return [$exit, (string) file_get_contents($dir . '/out'), (string) file_get_contents($dir . '/err')];
    }
}
