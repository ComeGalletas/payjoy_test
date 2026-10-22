<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Metrics;

use FxInstallments\Db\Connection;
use FxInstallments\Flow\SessionStore;
use FxInstallments\Metrics\Metrics;
use FxInstallments\Tests\Support\MockUpstream;
use FxInstallments\Tests\Support\TestEnvironment;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * `php bin/report.php`, run as a separate process on a temp database, under the gate scripts'
 * flags (`-d allow_url_fopen=0 -d disable_functions=curl_exec,curl_multi_exec`, ADR 0018), like
 * BotCliTest: the report never builds the upstream client, so it needs no transport. A canary
 * key and HTTP(S)_PROXY on a closed loopback port are an extra layer.
 */
final class ReportCliTest extends TestCase
{
    private const IP = '203.0.113.99';

    private string $path;

    protected function setUp(): void
    {
        $this->path = TestEnvironment::freshDatabasePath();
        $pdo = TestEnvironment::connect($this->path);
        $metrics = new Metrics(Connection::wrap($pdo));
        $metrics->record('api.request', ['currency' => '=CMD()', 'amount_usd_cents' => 104_582, 'installments' => 3, 'meta' => ['endpoint' => 'convert', 'http_status' => 200]]);
        $metrics->record('error', ['error_code' => 'NOT_FOUND', 'meta' => ['http_status' => 404]]);
        $pdo->prepare('INSERT INTO requests (ts, ip, method, path, status, latency_ms, blocked) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([time(), self::IP, 'GET', '/convert', 200, 1, 0]);
        // An open session that expired a minute ago: the report closes it first.
        (new SessionStore(Connection::wrap($pdo), 30))->create('cli', 'amount', time() - 1860);
    }

    public function testTheDefaultIsATableOfTheLastSevenDays(): void
    {
        [$exit, $out, $err] = $this->runCli([]);

        self::assertSame(['', 0], [$err, $exit]);
        self::assertMatchesRegularExpression('/^FX Installments report: \d{4}-\d{2}-\d{2} to \d{4}-\d{2}-\d{2} \(America\/Bogota, 7 days\)$/m', $out);
        self::assertMatchesRegularExpression('/^  USD converted +1,045\.82$/m', $out);
        self::assertStringContainsString('=CMD()', $out);
        self::assertStringNotContainsString(self::IP, $out);
        self::assertStringNotContainsString(MockUpstream::CANARY_KEY, $out);

        // The expired session was closed before counting.
        $pdo = TestEnvironment::connect($this->path);
        self::assertSame(['BOT_SESSION_EXPIRED'], $pdo->query("SELECT error_code FROM events WHERE event_type = 'bot.session_abandoned'")->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(['abandoned'], $pdo->query('SELECT status FROM sessions')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testCsvIsGuardedAndJsonIsTheReportData(): void
    {
        [$exit, $csv] = $this->runCli(['--format=csv']);
        self::assertSame(0, $exit);
        self::assertStringStartsWith("section,key,subkey,metric,value\r\n", $csv);
        self::assertStringContainsString("\r\ncurrency,'=CMD(),,conversions,1\r\n", $csv);
        self::assertStringNotContainsString("\ncurrency,=CMD()", $csv);
        self::assertStringNotContainsString(self::IP, $csv);

        [$exit, $json] = $this->runCli(['--format=json', '--from=2026-01-01', '--to=2026-01-31']);
        self::assertSame(0, $exit);
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['2026-01-01', '2026-01-31', 31], [$data['period']['from'], $data['period']['to'], $data['period']['days']]);
        self::assertArrayNotHasKey('ok', $data, 'no envelope on the CLI');
        self::assertSame(0, $data['summary']['conversions'], 'the seeded conversion is outside January');
    }

    public function testUsageErrorsExitTwo(): void
    {
        foreach ([
            [['--format=xml'], "report: --format must be table, csv or json\n"],
            [['--from=2026-02-30'], "report: from and to must be calendar dates written YYYY-MM-DD\n"],
            [['--from=2026-10-21', '--to=2026-10-20'], "report: from must not be after to\n"],
            [['--from=2025-01-01', '--to=2026-01-02'], "report: a report covers at most 366 days\n"],
            [['--from=2026-10-01', '--from=2026-10-02'], ''],
            [['--since=2026-10-01'], ''],
            [['table'], ''],
        ] as [$args, $problem]) {
            [$exit, $out, $err] = $this->runCli($args);

            self::assertSame([2, '', $problem . "usage: php bin/report.php [--from=YYYY-MM-DD] [--to=YYYY-MM-DD] [--format=table|csv|json]\n"], [$exit, $out, $err], implode(' ', $args));
        }

        self::assertSame([0, "usage: php bin/report.php [--from=YYYY-MM-DD] [--to=YYYY-MM-DD] [--format=table|csv|json]\n", ''], $this->runCli(['--help']));
    }

    public function testAMissingDatabaseFileIsRefusedAndNotCreated(): void
    {
        $missing = TestEnvironment::tempDir('report-missing') . '/none.db';

        self::assertSame([1, '', "report: failed: no database file (run php bin/migrate.php first)\n"], $this->runCli([], $missing));
        self::assertFileDoesNotExist($missing);
    }

    public function testADatabaseBehindTheCodeIsRefused(): void
    {
        $empty = TestEnvironment::tempDir('report-empty') . '/empty.db';
        touch($empty);

        self::assertSame([1, '', "report: failed: FxInstallments\\Db\\SchemaOutOfDate\n"], $this->runCli([], $empty));
    }

    /**
     * @param list<string> $args
     * @return array{int, string, string}
     */
    private function runCli(array $args, ?string $path = null): array
    {
        $dir = TestEnvironment::tempDir('report-cli');
        file_put_contents($dir . '/in', '');
        $env = getenv();
        $env['APP_ENV'] = 'test';
        $env['DB_DSN'] = 'sqlite:' . ($path ?? $this->path);
        $env['EXCHANGERATE_API_KEY'] = MockUpstream::CANARY_KEY;
        $env['HTTPS_PROXY'] = $env['HTTP_PROXY'] = $env['https_proxy'] = $env['http_proxy'] = 'http://127.0.0.1:9';
        unset($env['NO_PROXY'], $env['no_proxy']);
        $process = proc_open(
            [PHP_BINARY, '-d', 'allow_url_fopen=0', '-d', 'disable_functions=curl_exec,curl_multi_exec', dirname(__DIR__, 3) . '/bin/report.php', ...$args],
            [0 => ['file', $dir . '/in', 'r'], 1 => ['file', $dir . '/out', 'w'], 2 => ['file', $dir . '/err', 'w']],
            $pipes,
            $dir,
            $env,
        );
        self::assertIsResource($process, 'could not start bin/report.php');
        $exit = proc_close($process);

        return [$exit, (string) file_get_contents($dir . '/out'), (string) file_get_contents($dir . '/err')];
    }
}
