<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Acceptance;

use FxInstallments\App;
use FxInstallments\Http\Kernel;
use FxInstallments\Http\Request;
use FxInstallments\Http\Response;
use FxInstallments\Metrics\CsvWriter;
use FxInstallments\Metrics\ErrorCodes;
use FxInstallments\Tests\Support\BuiltInServer;
use FxInstallments\Tests\Unit\Metrics\ReportQueryTest;
use FxInstallments\Tests\Support\FakeClock;
use FxInstallments\Tests\Support\MockUpstream;
use FxInstallments\Tests\Support\OverridesEnvironment;
use FxInstallments\Tests\Support\TestEnvironment;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * P1-08 acceptance (docs/PLAN.md §10), on the production route table through App::kernel(),
 * over events the real endpoints and the flow engine wrote (and a few written straight through
 * Metrics where no endpoint can write them):
 * - funnel counts per step and channel match the sessions that ran, with never-answered
 *   sessions apart and expired sessions closed before counting;
 * - days split on `reports.timezone`;
 * - a currency named `=CMD()` is escaped in CSV (and every other formula-like cell);
 * - public `/health` shows only `status`, also over a real socket, and answers 503 UNHEALTHY
 *   instead of a raw 500 when the database is behind the code (ADR 0019);
 * - no report or health body shows an IP or the API key.
 */
final class P1_08_ReportsTest extends TestCase
{
    use OverridesEnvironment;

    private const TOKEN = 'the-real-admin-token';

    private const CLIENT = '203.0.113.7';

    /** 2026-10-21 15:00:00 UTC, 10:00 in Bogota. */
    private const MORNING = 1792594800;

    private string $path;

    private PDO $pdo;

    private FakeClock $clock;

    protected function setUp(): void
    {
        $this->path = TestEnvironment::freshDatabasePath();
        $this->pdo = TestEnvironment::connect($this->path);
        $this->clock = new FakeClock(self::MORNING);
        $this->setEnvironment('ADMIN_TOKEN', self::TOKEN);
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        self::assertTrue(TestEnvironment::removeDatabase($this->path), 'the test database was released and removed');
    }

    public function testFunnelCountsPerStepAndChannelMatch(): void
    {
        $upstream = new MockUpstream([MockUpstream::latest()]);
        $app = $this->app(upstream: $upstream);
        $kernel = $app->kernel();
        $engine = $app->flowEngine();

        // static, over HTTP: completed; three bad amounts; opened and never answered.
        $this->conversation($kernel, ['', '200', 'brazil', '3']);
        $this->conversation($kernel, ['', 'x', 'y', 'z']);
        $this->conversation($kernel, ['']);
        // cli, in-process: completed with the default installments; a currency off the
        // allow-list, then left waiting at installments.
        $id = null;
        foreach (['', '200', 'BRL', ''] as $text) {
            $id = $engine->handle($id, $text, 'cli')->sessionId;
        }
        $id = null;
        foreach (['', '50', 'XYZ', 'EUR'] as $text) {
            $id = $engine->handle($id, $text, 'cli')->sessionId;
        }
        self::assertSame(1, $upstream->calls(), 'one refresh served every conversion');

        // While they are live, the two waiting sessions are open at the step they wait on, and
        // every step row adds up.
        $live = self::data($this->report(['from' => '2026-10-21', 'to' => '2026-10-21']))['funnel'];
        self::assertSame([1, 0, 0, 0], array_column($live[0]['steps'], 'open'));
        self::assertSame([0, 0, 1, 0], array_column($live[1]['steps'], 'open'));
        self::assertSame([1, 0, 1], [$live[0]['open'], $live[0]['never_answered'], $live[1]['open']]);
        ReportQueryTest::assertFunnelAddsUp($live);

        // Two hours later both waiting sessions have expired; the report closes them first.
        $this->clock->advance(7200);
        self::assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM sessions WHERE status = 'open'")->fetchColumn());
        $funnel = self::data($this->report(['from' => '2026-10-21', 'to' => '2026-10-21']))['funnel'];
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM sessions WHERE status = 'open'")->fetchColumn());

        self::assertSame([
            [
                'channel' => 'static', 'started' => 3, 'completed' => 1, 'abandoned' => 1, 'never_answered' => 1, 'open' => 0,
                'steps' => [
                    ['step' => 'amount', 'reached' => 3, 'answered' => 1, 'retries' => 3, 'abandoned' => 1, 'never_answered' => 1, 'open' => 0],
                    ['step' => 'currency', 'reached' => 1, 'answered' => 1, 'retries' => 0, 'abandoned' => 0, 'never_answered' => 0, 'open' => 0],
                    ['step' => 'installments', 'reached' => 1, 'answered' => 1, 'retries' => 0, 'abandoned' => 0, 'never_answered' => 0, 'open' => 0],
                    ['step' => 'result', 'reached' => 1, 'completed' => 1, 'abandoned' => 0, 'never_answered' => 0, 'open' => 0],
                ],
                'abandoned_by_code' => [['code' => 'BOT_MAX_RETRIES', 'count' => 1]],
            ],
            [
                'channel' => 'cli', 'started' => 2, 'completed' => 1, 'abandoned' => 1, 'never_answered' => 0, 'open' => 0,
                'steps' => [
                    ['step' => 'amount', 'reached' => 2, 'answered' => 2, 'retries' => 0, 'abandoned' => 0, 'never_answered' => 0, 'open' => 0],
                    ['step' => 'currency', 'reached' => 2, 'answered' => 2, 'retries' => 1, 'abandoned' => 0, 'never_answered' => 0, 'open' => 0],
                    ['step' => 'installments', 'reached' => 2, 'answered' => 1, 'retries' => 0, 'abandoned' => 1, 'never_answered' => 0, 'open' => 0],
                    ['step' => 'result', 'reached' => 1, 'completed' => 1, 'abandoned' => 0, 'never_answered' => 0, 'open' => 0],
                ],
                'abandoned_by_code' => [['code' => 'BOT_SESSION_EXPIRED', 'count' => 1]],
            ],
            [
                'channel' => 'typebot', 'started' => 0, 'completed' => 0, 'abandoned' => 0, 'never_answered' => 0, 'open' => 0,
                'steps' => [
                    ['step' => 'amount', 'reached' => 0, 'answered' => 0, 'retries' => 0, 'abandoned' => 0, 'never_answered' => 0, 'open' => 0],
                    ['step' => 'currency', 'reached' => 0, 'answered' => 0, 'retries' => 0, 'abandoned' => 0, 'never_answered' => 0, 'open' => 0],
                    ['step' => 'installments', 'reached' => 0, 'answered' => 0, 'retries' => 0, 'abandoned' => 0, 'never_answered' => 0, 'open' => 0],
                    ['step' => 'result', 'reached' => 0, 'completed' => 0, 'abandoned' => 0, 'never_answered' => 0, 'open' => 0],
                ],
                'abandoned_by_code' => [],
            ],
        ], $funnel);
        ReportQueryTest::assertFunnelAddsUp($funnel);

        // The other sections agree with what ran.
        $data = self::data($this->report(['from' => '2026-10-21', 'to' => '2026-10-21']));
        self::assertSame([5, 2, 3, 2, 0, 2, 40_000, '400.00'], [
            $data['summary']['sessions_started'], $data['summary']['sessions_completed'], $data['summary']['sessions_abandoned'],
            $data['summary']['conversions'], $data['summary']['conversions_api'], $data['summary']['conversions_bot'],
            $data['summary']['amount_usd_cents'], $data['summary']['amount_usd'],
        ]);
        self::assertSame(0, $data['summary']['conversions_missing_rate'], 'bot completions store the rate they used');
        self::assertSame([['country' => 'Brazil', 'requested' => 1, 'conversions' => 1, 'conversions_api' => 0, 'conversions_bot' => 1, 'amount_usd_cents' => 20_000, 'amount_usd' => '200.00']], $data['countries']);
        self::assertSame(['BRL', 'EUR'], array_column($data['currencies'], 'currency'));
        self::assertSame([
            ['code' => 'BOT_MAX_RETRIES', 'http' => 0, 'bot' => 1, 'total' => 1],
            ['code' => 'BOT_SESSION_EXPIRED', 'http' => 0, 'bot' => 2, 'total' => 2],
            ['code' => 'VALIDATION_AMOUNT', 'http' => 0, 'bot' => 3, 'total' => 3],
            ['code' => 'VALIDATION_CURRENCY', 'http' => 0, 'bot' => 1, 'total' => 1],
        ], $data['errors']);
        self::assertSame(['latest', 2, 1, 1, 1], [
            $data['upstream']['endpoints'][0]['endpoint'], $data['upstream']['endpoints'][0]['lookups'],
            $data['upstream']['endpoints'][0]['hit'], $data['upstream']['endpoints'][0]['miss'], $data['upstream']['calls'],
        ]);
    }

    public function testDaysSplitOnTheReportTimezone(): void
    {
        $upstream = new MockUpstream([MockUpstream::latest()]);
        // 2026-10-21 04:30 UTC is 23:30 on the 20th in Bogota; 05:30 UTC is the 21st.
        $this->clock->now = 1792540800 + 4 * 3600 + 1800;
        $kernel = $this->app(upstream: $upstream)->kernel();
        self::assertSame(200, $this->convert($kernel, '200', 'BRL', '3')->status());
        $this->clock->now += 3600;
        self::assertSame(200, $this->convert($kernel, '1000000', 'IRR', '7')->status());
        self::assertSame(400, $this->convert($kernel, '0', 'BRL', '1')->status());

        $bogota = self::data($this->report(['from' => '2026-10-20', 'to' => '2026-10-21']));
        self::assertSame('America/Bogota', $bogota['period']['timezone']);
        self::assertSame(['2026-10-20', '2026-10-21'], array_column($bogota['days'], 'date'));
        self::assertSame([1, 1], array_column($bogota['days'], 'conversions'));
        self::assertSame([20_000, 100_000_000], array_column($bogota['days'], 'amount_usd_cents'));
        self::assertSame(['200.00', '1,000,000.00'], array_column($bogota['days'], 'amount_usd'));
        self::assertSame([1, 2], array_column($bogota['days'], 'api_requests'));
        self::assertSame([0, 1], array_column($bogota['days'], 'error_responses'));
        self::assertSame([1, 0], array_column($bogota['days'], 'upstream_calls'), 'one refresh; the second lookup was a hit, which is not a call');
        self::assertSame([['code' => 'VALIDATION_AMOUNT', 'http' => 1, 'bot' => 0, 'total' => 1]], $bogota['errors'], 'the 400 counts once, not once more for its api.request event');

        // The same events in UTC days: both conversions on the 21st.
        $utc = self::data($this->report(['from' => '2026-10-20', 'to' => '2026-10-21'], $this->app(['reports.timezone' => 'UTC'])->kernel()));
        self::assertSame([0, 2], array_column($utc['days'], 'conversions'));
        self::assertSame('2026-10-20T00:00:00Z', $utc['period']['start']);
        self::assertSame('2026-10-20T05:00:00Z', $bogota['period']['start']);
    }

    public function testACurrencyNamedCmdIsEscapedInCsv(): void
    {
        // No endpoint can store such a currency (it must be a 3-letter code on the allow-list),
        // but a hand-edited or imported row could.
        $app = $this->app();
        $app->metrics->record('api.request', ['currency' => '=CMD()', 'amount_usd_cents' => 100, 'installments' => 1, 'meta' => ['endpoint' => 'convert', 'http_status' => 200]]);
        $app->metrics->record('bot.session_completed', ['source' => 'bot', 'channel' => 'static', 'session_id' => 'x', 'step' => 'result', 'currency' => '-1+1', 'country' => '+SUM(A1)', 'amount_usd_cents' => 5]);
        $app->metrics->record('bot.step', ['source' => 'bot', 'channel' => 'static', 'session_id' => 'x', 'step' => '@step', 'error_code' => 'VALIDATION_AMOUNT']);

        $response = $this->report(['format' => 'csv', 'from' => '2026-10-21', 'to' => '2026-10-21']);

        self::assertSame(200, $response->status());
        self::assertSame('text/csv; charset=utf-8', $response->header('Content-Type'));
        $csv = $response->body();
        // Round 2: a currency that isn't a 3-letter code, a country off the map and a step
        // outside `^[a-z_]{1,32}$` are counted as `other`, so the formula text never reaches a
        // cell at all; CsvWriter would still guard it (CsvWriterTest, and the formatter check
        // below).
        self::assertStringContainsString("\r\ncurrency,other,,conversions,2\r\n", $csv);
        self::assertStringContainsString("\r\ncountry,other,,conversions,1\r\n", $csv);
        foreach (['=CMD()', '-1+1', '+SUM(A1)', '@step'] as $formula) {
            self::assertStringNotContainsString($formula, $csv);
        }
        foreach (explode("\r\n", rtrim($csv, "\r\n")) as $line) {
            foreach (str_getcsv($line, ',', '"', '') as $cell) {
                foreach (explode(';', (string) $cell) as $segment) {
                    self::assertDoesNotMatchRegularExpression('/^\s*[=+\-@]|^[\t\r]/', $segment, $line);
                }
            }
        }
        $json = $this->report(['from' => '2026-10-21', 'to' => '2026-10-21'])->body();
        self::assertStringNotContainsString('=CMD()', $json);

        // Were such a label ever to reach a cell, the CSV writer escapes it (ReportFormatterTest
        // runs a whole report with a `=CMD()` currency through it).
        self::assertSame("'=CMD()", CsvWriter::cell('=CMD()'));
        self::assertSame("\"X;'=CMD()\"", CsvWriter::cell('X;=CMD()'));
    }

    public function testPublicHealthShowsOnlyTheStatus(): void
    {
        $kernel = $this->app(upstream: new MockUpstream())->kernel();

        $public = $kernel->handle(new Request('GET', '/health', remoteAddr: self::CLIENT));
        self::assertSame(200, $public->status());
        self::assertSame(['ok' => true, 'data' => ['status' => 'ok']], json_decode($public->body(), true, flags: JSON_THROW_ON_ERROR));

        $admin = $kernel->handle(new Request('GET', '/health', [], ['Authorization' => 'Bearer ' . self::TOKEN], remoteAddr: self::CLIENT));
        self::assertSame(['status', 'checked_at', 'database', 'rates', 'breaker', 'quota'], array_keys(self::data($admin)));

        // Over a real socket, with the gate flags (BuiltInServer, ADR 0018).
        $server = BuiltInServer::start($this->path);
        [$status, $headers, $body] = $server->request('GET', '/health');
        [$adminStatus] = $server->request('GET', '/health', ['Authorization' => 'Bearer anything']);
        $server->stop();
        self::assertSame(200, $status);
        self::assertSame('{"ok":true,"data":{"status":"ok"}}', $body);
        self::assertSame(['application/json'], $headers['content-type']);
        self::assertSame(401, $adminStatus, 'the server runs with an empty ADMIN_TOKEN, which refuses every admin request');

        // A database behind the code: /health still answers, as an envelope (ADR 0019 decision),
        // while every other path keeps failing closed with 500 INTERNAL.
        $behind = BuiltInServer::start(TestEnvironment::tempDir('p1-08-behind') . '/behind.db');
        [$healthStatus, , $healthBody] = $behind->request('GET', '/health');
        [$widgetStatus, , $widgetBody] = $behind->request('GET', '/bot/widget');
        // Round 2: only GET /health skips the open; another method fails closed (500), instead
        // of reaching the router's 404, which would count toward blocking.
        [$postStatus, , $postBody] = $behind->request('POST', '/health', ['Content-Type' => 'application/json'], '{}');
        [$headStatus] = $behind->request('HEAD', '/health');
        $behind->stop();
        self::assertSame([500, 'INTERNAL', 500], [$postStatus, json_decode($postBody, true, flags: JSON_THROW_ON_ERROR)['error']['code'], $headStatus]);
        self::assertSame(503, $healthStatus);
        self::assertSame(['ok' => false, 'error' => ['code' => 'UNHEALTHY', 'message_for_user' => ErrorCodes::messageForUser(ErrorCodes::UNHEALTHY)]], json_decode($healthBody, true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(500, $widgetStatus);
        self::assertSame('INTERNAL', json_decode($widgetBody, true, flags: JSON_THROW_ON_ERROR)['error']['code']);
    }

    public function testNoReportOrHealthBodyShowsAnIpOrTheKey(): void
    {
        $upstream = new MockUpstream([MockUpstream::latest(), MockUpstream::json(200, '{"result":"success","plan_quota":1500,"requests_remaining":1499,"refresh_day_of_month":21}')]);
        $app = $this->app(upstream: $upstream);
        $kernel = $app->kernel();
        $this->convert($kernel, '200', 'BRL', '3');
        $kernel->handle(new Request('GET', '/bot/widget', remoteAddr: self::CLIENT));
        $kernel->handle(new Request('GET', '/favicon.ico', remoteAddr: self::CLIENT));
        $kernel->handle(new Request('GET', '/no/such/path', remoteAddr: self::CLIENT));
        $app->ipBlocks()->blockManually(self::CLIENT, 'manual', ($this->clock)(), null);
        $kernel->handle(new Request('GET', '/convert', remoteAddr: self::CLIENT));
        $health = $kernel->handle(new Request('GET', '/health', [], ['Authorization' => 'Bearer ' . self::TOKEN], remoteAddr: '198.51.100.1'));

        $json = $this->report(['from' => '2026-10-21', 'to' => '2026-10-21'], remote: '198.51.100.1');
        $csv = $this->report(['format' => 'csv', 'from' => '2026-10-21', 'to' => '2026-10-21'], remote: '198.51.100.1');

        // Widget and favicon hits apart; api: the conversion and the admin /health (2xx), the
        // 404 and the blocked 403 (4xx). The report's own request is logged after it answers.
        $data = self::data($json);
        self::assertSame([
            ['kind' => 'api', 'total' => 4, '2xx' => 2, '3xx' => 0, '4xx' => 2, '5xx' => 0, 'other' => 0, 'blocked' => 1],
            ['kind' => 'widget_asset', 'total' => 1, '2xx' => 1, '3xx' => 0, '4xx' => 0, '5xx' => 0, 'other' => 0, 'blocked' => 0],
            ['kind' => 'favicon', 'total' => 1, '2xx' => 1, '3xx' => 0, '4xx' => 0, '5xx' => 0, 'other' => 0, 'blocked' => 0],
        ], $data['requests']['kinds']);
        // The admin view's /quota call is counted with the refresh.
        self::assertSame(2, $upstream->calls());
        self::assertSame([2, 'quota', 1], [$data['upstream']['calls'], $data['upstream']['endpoints'][2]['endpoint'], $data['upstream']['endpoints'][2]['calls']]);
        foreach ([$json->body(), $csv->body(), $health->body()] as $body) {
            self::assertStringNotContainsString(self::CLIENT, $body);
            self::assertStringNotContainsString('198.51.100.1', $body);
            self::assertStringNotContainsString(MockUpstream::FAKE_KEY, $body);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function app(array $config = [], ?MockUpstream $upstream = null): App
    {
        return TestEnvironment::app($this->clock, $config, $this->pdo, upstream: $upstream);
    }

    /**
     * Sends each text to `/bot/message` as the widget does, on one session.
     *
     * @param list<string> $texts
     */
    private function conversation(Kernel $kernel, array $texts): void
    {
        $session = null;
        foreach ($texts as $text) {
            $body = ['text' => $text] + ($session === null ? [] : ['session_id' => $session]);
            $response = $kernel->handle(new Request('POST', '/bot/message', [], ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR), self::CLIENT));
            self::assertSame(200, $response->status(), $response->body());
            $session = self::data($response)['session_id'];
        }
    }

    private function convert(Kernel $kernel, string $amount, string $currency, string $installments): Response
    {
        return $kernel->handle(new Request('GET', '/convert', ['amount' => $amount, 'cy' => $currency, 'installments' => $installments], remoteAddr: self::CLIENT));
    }

    /**
     * @param array<string, string> $query
     */
    private function report(array $query, ?Kernel $kernel = null, string $remote = self::CLIENT): Response
    {
        $kernel ??= $this->app()->kernel();

        return $kernel->handle(new Request('GET', '/reports', $query, ['Authorization' => 'Bearer ' . self::TOKEN], remoteAddr: $remote));
    }

    /**
     * @return array<string, mixed>
     */
    private static function data(Response $response): array
    {
        self::assertSame(200, $response->status(), $response->body());
        $envelope = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($envelope['ok']);

        return $envelope['data'];
    }
}
