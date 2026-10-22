<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Metrics;

use Closure;
use FxInstallments\Db\Connection;
use FxInstallments\Metrics\Metrics;
use FxInstallments\Metrics\ReportPeriod;
use FxInstallments\Metrics\ReportQuery;
use FxInstallments\Tests\Support\FakeClock;
use FxInstallments\Tests\Support\TestEnvironment;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * ReportQuery over events written straight through Metrics (the writers' own tests check what
 * they write; these check how each event is counted).
 */
final class ReportQueryTest extends TestCase
{
    /** 2026-10-21 00:00:00 UTC. */
    private const DAY = 1792540800;

    private const IP = '203.0.113.99';

    private string $path;

    private PDO $pdo;

    private FakeClock $clock;

    private Metrics $metrics;

    protected function setUp(): void
    {
        $this->path = TestEnvironment::freshDatabasePath();
        $this->pdo = TestEnvironment::connect($this->path);
        $this->clock = new FakeClock(self::DAY);
        $this->metrics = new Metrics(Connection::wrap($this->pdo), Closure::fromCallable($this->clock), static function (): void {
        });
    }

    protected function tearDown(): void
    {
        unset($this->pdo, $this->metrics);
        self::assertTrue(TestEnvironment::removeDatabase($this->path));
    }

    public function testTheFunnelFollowsEachSessionStartedInThePeriod(): void
    {
        $t = self::DAY + 3600;
        // static: completed
        $this->session('s1', 'static', $t, [
            ['bot.step', 'amount', null, ['amount_usd_cents' => 20_000]],
            ['bot.step', 'currency', null, ['currency' => 'BRL', 'country' => 'Brazil']],
            ['bot.step', 'installments', null, ['installments' => 3]],
            ['bot.session_completed', 'result', null, ['currency' => 'BRL', 'country' => 'Brazil', 'amount_usd_cents' => 20_000, 'meta' => ['rate_scaled' => 52_291_000_000]]],
        ]);
        // static: three unclear amounts, abandoned by max retries
        $this->session('s2', 'static', $t, [
            ['bot.step', 'amount', 'VALIDATION_AMOUNT', []],
            ['bot.step', 'amount', 'VALIDATION_AMOUNT', []],
            ['bot.step', 'amount', 'VALIDATION_AMOUNT', []],
            ['bot.session_abandoned', 'amount', 'BOT_MAX_RETRIES', []],
        ]);
        // static: opened, never answered, expired
        $this->session('s3', 'static', $t, [['bot.session_abandoned', 'amount', 'BOT_SESSION_EXPIRED', []]]);
        // static: still open
        $this->session('s6', 'static', $t, [['bot.step', 'amount', null, []]]);
        // cli: a currency missing from the table re-asks it, then completes
        $this->session('s4', 'cli', $t, [
            ['bot.step', 'amount', null, []],
            ['bot.step', 'currency', null, ['currency' => 'COP']],
            ['bot.step', 'installments', null, []],
            ['bot.step', 'currency', 'UPSTREAM_UNSUPPORTED_CODE', ['meta' => ['via' => 'convert']]],
            ['bot.step', 'currency', null, ['currency' => 'EUR']],
            ['bot.session_completed', 'result', null, ['currency' => 'EUR', 'amount_usd_cents' => 1_000]],
        ]);
        // cli: an upstream error at the result step
        $this->session('s5', 'cli', $t, [
            ['bot.step', 'amount', null, []],
            ['bot.step', 'currency', null, []],
            ['bot.step', 'installments', null, []],
            ['bot.session_abandoned', 'result', 'UPSTREAM_TIMEOUT', []],
        ]);
        // Started the day before the period: not in its funnel, though it ends inside it.
        $this->session('s7', 'static', self::DAY - 600, [['bot.session_completed', 'result', null, ['currency' => 'BRL', 'amount_usd_cents' => 500]]], self::DAY + 60);
        // Started in the period, ended after it: followed to its end.
        $this->session('s8', 'cli', self::DAY + 86_000, [['bot.session_abandoned', 'amount', 'BOT_SESSION_EXPIRED', []]], self::DAY + 90_000);

        $report = $this->report('2026-10-21', '2026-10-21');
        [$static, $cli, $typebot] = $report['funnel'];

        self::assertSame([
            'channel' => 'static', 'started' => 4, 'completed' => 1, 'abandoned' => 1, 'never_answered' => 1, 'open' => 1,
            'steps' => [
                ['step' => 'amount', 'reached' => 4, 'answered' => 2, 'retries' => 3, 'abandoned' => 1],
                ['step' => 'currency', 'reached' => 2, 'answered' => 1, 'retries' => 0, 'abandoned' => 0],
                ['step' => 'installments', 'reached' => 1, 'answered' => 1, 'retries' => 0, 'abandoned' => 0],
                ['step' => 'result', 'reached' => 1, 'completed' => 1, 'abandoned' => 0],
            ],
            'abandoned_by_code' => [['code' => 'BOT_MAX_RETRIES', 'count' => 1]],
        ], $static);
        self::assertSame([
            'channel' => 'cli', 'started' => 3, 'completed' => 1, 'abandoned' => 1, 'never_answered' => 1, 'open' => 0,
            'steps' => [
                ['step' => 'amount', 'reached' => 3, 'answered' => 2, 'retries' => 0, 'abandoned' => 0],
                // The conversion retry counts at the step it re-asked, and the session once.
                ['step' => 'currency', 'reached' => 2, 'answered' => 2, 'retries' => 1, 'abandoned' => 0],
                ['step' => 'installments', 'reached' => 2, 'answered' => 2, 'retries' => 0, 'abandoned' => 0],
                ['step' => 'result', 'reached' => 2, 'completed' => 1, 'abandoned' => 1],
            ],
            'abandoned_by_code' => [['code' => 'UPSTREAM_TIMEOUT', 'count' => 1]],
        ], $cli);
        self::assertSame(['typebot', 0, 0], [$typebot['channel'], $typebot['started'], $typebot['completed']]);
        foreach ($report['funnel'] as $funnel) {
            self::assertSame($funnel['started'], $funnel['completed'] + $funnel['abandoned'] + $funnel['never_answered'] + $funnel['open'], $funnel['channel']);
        }

        // Event counts in the period's days are by event time: s7's completion is in, s8's end isn't.
        self::assertSame([7, 3, 3], [$report['summary']['sessions_started'], $report['summary']['sessions_completed'], $report['summary']['sessions_abandoned']]);
        self::assertSame(['BOT_MAX_RETRIES', 'BOT_SESSION_EXPIRED', 'UPSTREAM_TIMEOUT', 'UPSTREAM_UNSUPPORTED_CODE', 'VALIDATION_AMOUNT'], array_column($report['errors'], 'code'));
    }

    public function testAStepTheFlowNoLongerHasIsListedAfterTheKnownOnes(): void
    {
        $this->session('s1', 'static', self::DAY + 10, [
            ['bot.step', 'amount', null, []],
            ['bot.step', 'nickname', 'VALIDATION_CURRENCY', []],
            ['bot.session_abandoned', 'nickname', 'BOT_SESSION_EXPIRED', []],
        ]);

        $static = $this->report('2026-10-21', '2026-10-21')['funnel'][0];

        self::assertSame(['step' => 'nickname', 'reached' => null, 'answered' => 0, 'retries' => 1, 'abandoned' => 1], $static['steps'][4]);
        self::assertSame(['amount', 'currency', 'installments', 'result', 'nickname'], array_column($static['steps'], 'step'));
    }

    public function testTheSweepRunsBeforeCountingAndAFailedSweepOnlyAddsANote(): void
    {
        $order = [];
        $query = new ReportQuery(Connection::wrap($this->pdo), Closure::fromCallable($this->clock), static function () use (&$order): int {
            $order[] = 'sweep';

            return 0;
        });
        $query->run(ReportPeriod::fromStrings('2026-10-21', '2026-10-21', 'UTC', self::DAY));
        self::assertSame(['sweep'], $order);

        $failing = new ReportQuery(Connection::wrap($this->pdo), Closure::fromCallable($this->clock), static function (): int {
            throw new RuntimeException('locked');
        });
        $notes = $failing->run(ReportPeriod::fromStrings('2026-10-21', '2026-10-21', 'UTC', self::DAY))['notes'];
        self::assertStringStartsWith('Expired sessions could not be closed before counting', $notes[0]);
    }

    public function testErrorResponsesAreCountedOnceAndBotErrorsApart(): void
    {
        $t = self::DAY + 100;
        // A /convert 400: the handler's api.request and the Kernel's error event.
        $this->event($t, 'api.request', ['error_code' => 'VALIDATION_AMOUNT', 'meta' => ['endpoint' => 'convert', 'reason' => 'format', 'http_status' => 400]]);
        $this->event($t, 'error', ['error_code' => 'VALIDATION_AMOUNT', 'meta' => ['http_status' => 400]]);
        // A /convert 503 with its upstream lookup.
        $this->event($t, 'upstream.call', ['source' => 'upstream', 'currency' => 'BRL', 'error_code' => 'UPSTREAM_TIMEOUT', 'meta' => ['result' => 'miss', 'endpoint' => 'latest', 'stale' => false, 'reason' => 'timeout']]);
        $this->event($t, 'api.request', ['currency' => 'BRL', 'error_code' => 'UPSTREAM_TIMEOUT', 'meta' => ['endpoint' => 'convert', 'reason' => 'timeout', 'http_status' => 503]]);
        $this->event($t, 'error', ['error_code' => 'UPSTREAM_TIMEOUT', 'meta' => ['http_status' => 503]]);
        // Rate-limited and INTERNAL answers have only the error event.
        $this->event($t, 'error', ['error_code' => 'RATE_LIMITED', 'meta' => ['http_status' => 429]]);
        $this->event($t, 'error', ['error_code' => 'INTERNAL', 'meta' => ['http_status' => 500]]);
        // A bot retry with the same code.
        $this->event($t, 'bot.step', ['source' => 'bot', 'channel' => 'static', 'session_id' => 'x', 'step' => 'amount', 'error_code' => 'VALIDATION_AMOUNT']);

        $report = $this->report('2026-10-21', '2026-10-21');

        self::assertSame([
            ['code' => 'INTERNAL', 'http' => 1, 'bot' => 0, 'total' => 1],
            ['code' => 'RATE_LIMITED', 'http' => 1, 'bot' => 0, 'total' => 1],
            ['code' => 'UPSTREAM_TIMEOUT', 'http' => 1, 'bot' => 0, 'total' => 1],
            ['code' => 'VALIDATION_AMOUNT', 'http' => 1, 'bot' => 1, 'total' => 2],
        ], $report['errors']);
        self::assertSame([4, 2, 2, 1, 0], [
            $report['summary']['error_responses'], $report['summary']['api_requests'], $report['summary']['api_errors'],
            $report['summary']['bot_errors'], $report['summary']['conversions'],
        ]);
        self::assertSame([['currency' => 'BRL', 'requested' => 1, 'conversions' => 0, 'conversions_api' => 0, 'conversions_bot' => 0, 'amount_usd_cents' => 0]], $report['currencies']);
    }

    public function testUpstreamCallsAreMissesAndStalesWithoutNoKey(): void
    {
        $t = self::DAY + 100;
        $call = fn (array $meta, ?string $code = null) => $this->event($t, 'upstream.call', ['source' => 'upstream', 'currency' => 'BRL', 'error_code' => $code, 'meta' => $meta]);
        $call(['result' => 'hit', 'endpoint' => 'latest', 'stale' => false]);
        $call(['result' => 'miss', 'endpoint' => 'latest', 'stale' => false, 'currencies' => 166]);
        $call(['result' => 'miss', 'endpoint' => 'latest', 'stale' => false, 'currencies' => 166, 'store_failed' => true, 'breaker' => 'backoff']);
        // A good refresh whose table lacks the currency: a call that worked.
        $call(['result' => 'miss', 'endpoint' => 'latest', 'stale' => false, 'currencies' => 165], 'UPSTREAM_UNSUPPORTED_CODE');
        $call(['result' => 'miss', 'endpoint' => 'latest', 'stale' => false, 'reason' => 'timeout', 'breaker' => 'backoff'], 'UPSTREAM_TIMEOUT');
        $call(['result' => 'miss', 'endpoint' => 'latest', 'stale' => false, 'reason' => 'no_key'], 'UPSTREAM_AUTH');
        $call(['result' => 'stale', 'endpoint' => 'latest', 'stale' => true, 'refresh_error' => 'UPSTREAM_MALFORMED', 'reason' => 'json']);
        $call(['result' => 'stale', 'endpoint' => 'latest', 'stale' => true, 'refresh_error' => 'UPSTREAM_AUTH', 'reason' => 'no_key']);
        $call(['result' => 'blocked', 'endpoint' => 'latest', 'stale' => true, 'reason' => 'backoff']);
        $call(['result' => 'blocked', 'endpoint' => 'latest', 'stale' => false, 'reason' => 'quota'], 'UPSTREAM_QUOTA');
        // Before P1-04's meta.endpoint, and an unreadable result.
        $call(['result' => 'hit', 'stale' => false]);
        $call(['result' => 'surprise']);
        // The admin health view's /quota.
        $call(['result' => 'miss', 'endpoint' => 'quota']);
        $call(['result' => 'hit', 'endpoint' => 'quota']);
        $call(['result' => 'miss', 'endpoint' => 'quota', 'reason' => 'timeout'], 'UPSTREAM_TIMEOUT');
        $this->event($t, 'upstream.breaker_reset', ['source' => 'upstream', 'meta' => ['cleared' => 'auth', 'was_open' => true, 'by' => 'cli']]);

        $upstream = $this->report('2026-10-21', '2026-10-21')['upstream'];

        self::assertSame(7, $upstream['calls']);
        self::assertSame(1, $upstream['breaker_resets']);
        self::assertSame([
            ['endpoint' => 'latest', 'lookups' => 12, 'hit' => 2, 'miss' => 5, 'stale' => 2, 'blocked' => 2, 'unknown' => 1, 'calls' => 5, 'no_key' => 2, 'failed_calls' => 2],
            ['endpoint' => 'codes', 'lookups' => 0, 'hit' => 0, 'miss' => 0, 'stale' => 0, 'blocked' => 0, 'unknown' => 0, 'calls' => 0, 'no_key' => 0, 'failed_calls' => 0],
            ['endpoint' => 'quota', 'lookups' => 3, 'hit' => 1, 'miss' => 2, 'stale' => 0, 'blocked' => 0, 'unknown' => 0, 'calls' => 2, 'no_key' => 0, 'failed_calls' => 1],
        ], $upstream['endpoints']);
        self::assertSame([
            ['endpoint' => 'latest', 'code' => 'UPSTREAM_MALFORMED', 'count' => 1],
            ['endpoint' => 'latest', 'code' => 'UPSTREAM_TIMEOUT', 'count' => 1],
            ['endpoint' => 'quota', 'code' => 'UPSTREAM_TIMEOUT', 'count' => 1],
        ], $upstream['failures']);
    }

    public function testDaysSplitOnTheReportTimezoneAndMoneyIsAnIntegerSum(): void
    {
        // 2026-10-21 04:30 UTC is 23:30 on the 20th in Bogota; 05:30 UTC is the 21st.
        $late = self::DAY + 4 * 3600 + 1800;
        $early = self::DAY + 5 * 3600 + 1800;
        $this->event($late, 'api.request', ['currency' => 'BRL', 'amount_usd_cents' => 20_001, 'installments' => 3, 'meta' => ['endpoint' => 'convert', 'stale' => false, 'http_status' => 200, 'rate_scaled' => 52_291_000_000, 'rate_as_of' => self::DAY]]);
        $this->event($early, 'api.request', ['currency' => 'BRL', 'amount_usd_cents' => 99_999_999, 'installments' => 1, 'meta' => ['endpoint' => 'convert', 'stale' => false, 'http_status' => 200]]);
        $this->event($early, 'bot.step', ['source' => 'bot', 'channel' => 'static', 'session_id' => 'a', 'step' => 'currency', 'currency' => 'MXN', 'country' => 'Mexico']);
        $this->event($early, 'bot.session_completed', ['source' => 'bot', 'channel' => 'static', 'session_id' => 'a', 'step' => 'result', 'currency' => 'MXN', 'country' => 'Mexico', 'amount_usd_cents' => 1, 'meta' => ['rate_scaled' => 1, 'rate_as_of' => self::DAY]]);

        $bogota = $this->report('2026-10-20', '2026-10-21', 'America/Bogota');
        self::assertSame(['2026-10-20', '2026-10-21'], array_column($bogota['days'], 'date'));
        self::assertSame([1, 2], array_column($bogota['days'], 'conversions'));
        self::assertSame([20_001, 100_000_000], array_column($bogota['days'], 'amount_usd_cents'));
        self::assertSame([1, 1], array_column($bogota['days'], 'api_requests'));
        self::assertSame(100_020_001, $bogota['summary']['amount_usd_cents']);
        self::assertIsInt($bogota['summary']['amount_usd_cents']);
        self::assertSame([3, 2, 1, 1], [$bogota['summary']['conversions'], $bogota['summary']['conversions_api'], $bogota['summary']['conversions_bot'], $bogota['summary']['conversions_missing_rate']]);
        self::assertSame([
            ['currency' => 'BRL', 'requested' => 2, 'conversions' => 2, 'conversions_api' => 2, 'conversions_bot' => 0, 'amount_usd_cents' => 100_019_999 + 1],
            ['currency' => 'MXN', 'requested' => 1, 'conversions' => 1, 'conversions_api' => 0, 'conversions_bot' => 1, 'amount_usd_cents' => 1],
        ], $bogota['currencies']);
        self::assertSame([['country' => 'Mexico', 'requested' => 1, 'conversions' => 1, 'conversions_api' => 0, 'conversions_bot' => 1, 'amount_usd_cents' => 1]], $bogota['countries']);
        self::assertStringContainsString('1 conversion(s) in this period have no stored rate_scaled', implode("\n", $bogota['notes']));

        // In UTC both are on the 21st.
        $utc = $this->report('2026-10-20', '2026-10-21', 'UTC');
        self::assertSame([0, 3], array_column($utc['days'], 'conversions'));
    }

    public function testTheRequestLogIsSplitByKindAndNeverShowsAnIp(): void
    {
        $t = self::DAY + 100;
        foreach ([['/bot/widget', 200, 0], ['/bot/widget.js', 200, 0], ['/bot/widget.css', 200, 0], ['/bot/widget.svg', 200, 0],
            ['/favicon.ico', 204, 0], ['/favicon.ico', 403, 1], ['/convert', 200, 0], ['/convert', 429, 0], ['/nope', 404, 0],
            ['/health', 503, 0], ['/bot/message', 403, 1], ['/odd', 99, 0]] as [$path, $status, $blocked]) {
            $this->pdo->prepare('INSERT INTO requests (ts, ip, method, path, status, latency_ms, blocked) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$t, self::IP, 'GET', $path, $status, 1, $blocked]);
        }
        // Outside the period.
        $this->pdo->prepare('INSERT INTO requests (ts, ip, method, path, status, latency_ms, blocked) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([self::DAY - 1, self::IP, 'GET', '/convert', 200, 1, 0]);
        $this->event($t, 'ip.block', ['meta' => ['block_source' => 'auto', 'reason' => 'error_responses', 'duration_minutes' => 60, 'extended' => false]]);
        $this->event($t, 'ip.block', ['meta' => ['block_source' => 'auto', 'reason' => 'error_responses', 'duration_minutes' => 60, 'extended' => true]]);
        $this->event($t, 'ip.block', ['meta' => ['block_source' => 'manual', 'reason' => 'given', 'duration_minutes' => null, 'by' => 'cli']]);
        $this->event($t, 'ip.unblock', ['meta' => ['block_source' => 'manual', 'was_active' => true, 'by' => 'cli']]);
        $this->event($t, 'ip.unblock', ['meta' => ['block_source' => null, 'was_active' => false, 'by' => 'cli']]);
        $this->pdo->prepare('INSERT INTO ip_blocks (ip, reason, source, blocked_at, expires_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([self::IP, 'manual', 'manual', $t, null]);

        $report = $this->report('2026-10-21', '2026-10-21');

        self::assertSame([
            'total' => 12,
            'blocked' => 2,
            'kinds' => [
                ['kind' => 'api', 'total' => 6, '2xx' => 1, '3xx' => 0, '4xx' => 3, '5xx' => 1, 'other' => 1, 'blocked' => 1],
                ['kind' => 'widget_asset', 'total' => 4, '2xx' => 4, '3xx' => 0, '4xx' => 0, '5xx' => 0, 'other' => 0, 'blocked' => 0],
                ['kind' => 'favicon', 'total' => 2, '2xx' => 1, '3xx' => 0, '4xx' => 1, '5xx' => 0, 'other' => 0, 'blocked' => 1],
            ],
        ], $report['requests']);
        self::assertSame([
            'blocks' => [
                ['block_source' => 'auto', 'reason' => 'error_responses', 'count' => 2, 'extended' => 1],
                ['block_source' => 'manual', 'reason' => 'given', 'count' => 1, 'extended' => 0],
            ],
            'unblocks' => [['block_source' => 'manual', 'count' => 1], ['block_source' => 'none', 'count' => 1]],
        ], $report['ip_blocks']);
        self::assertStringNotContainsString(self::IP, json_encode($report, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('203.0.113', serialize($report));
    }

    public function testLabelsFromEventColumnsAreMadeSafe(): void
    {
        $t = self::DAY + 100;
        $this->event($t, 'api.request', ['currency' => '=CMD()', 'amount_usd_cents' => 100, 'meta' => ['http_status' => 200]]);
        $this->event($t, 'api.request', ['currency' => "B\x1b[31mR", 'amount_usd_cents' => 100, 'meta' => ['http_status' => 200]]);
        $this->event($t, 'api.request', ['currency' => "\xC3(", 'amount_usd_cents' => 100, 'meta' => ['http_status' => 200]]);
        $this->event($t, 'api.request', ['currency' => str_repeat('x', 100), 'amount_usd_cents' => 100, 'meta' => ['http_status' => 200]]);

        $codes = array_column($this->report('2026-10-21', '2026-10-21')['currencies'], 'currency');

        // Sorted as strings; the formula stays as it is here (CSV guards it, see CsvWriter).
        self::assertSame(['=CMD()', '?(', "B\u{FFFD}[31mR", str_repeat('x', 64)], $codes);
        self::assertSame(null, ReportQuery::label(null));
        self::assertSame('7', ReportQuery::label(7));
    }

    public function testTheRetentionNoteAppearsWhenThePeriodStartsBeforeTheKeptRequests(): void
    {
        $this->clock->now = self::DAY + 40 * 86_400;
        $query = new ReportQuery(Connection::wrap($this->pdo), Closure::fromCallable($this->clock), null, 30);

        $old = $query->run(ReportPeriod::fromStrings('2026-10-21', '2026-10-21', 'UTC', $this->clock->now))['notes'];
        $recent = $query->run(ReportPeriod::fromStrings(null, null, 'UTC', $this->clock->now))['notes'];

        self::assertStringContainsString('Request-log counts cover only the last 30 days', implode("\n", $old));
        self::assertStringNotContainsString('Request-log counts', implode("\n", $recent));
    }

    /**
     * Records a session: its start at $start, then each [type, step, code, fields] event one
     * second apart (or from $endAt when given, for the last one).
     *
     * @param list<array{string, string, ?string, array<string, mixed>}> $events
     */
    private function session(string $id, string $channel, int $start, array $events, ?int $endAt = null): void
    {
        $base = ['source' => 'bot', 'channel' => $channel, 'session_id' => $id];
        $this->event($start, 'bot.session_start', $base + ['step' => 'greet', 'meta' => ['flow' => 'fx_installments']]);
        $count = count($events);
        foreach ($events as $index => [$type, $step, $code, $fields]) {
            $ts = $endAt !== null && $index === $count - 1 ? $endAt : $start + $index + 1;
            $this->event($ts, $type, $base + ['step' => $step, 'error_code' => $code] + $fields);
        }
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function event(int $ts, string $type, array $fields): void
    {
        $this->clock->now = $ts;
        self::assertTrue($this->metrics->record($type, $fields), "{$type} was recorded");
    }

    /**
     * @return array<string, mixed>
     */
    private function report(string $from, string $to, string $timezone = 'UTC'): array
    {
        $this->clock->now = self::DAY + 5 * 86_400;

        return (new ReportQuery(Connection::wrap($this->pdo), Closure::fromCallable($this->clock)))
            ->run(ReportPeriod::fromStrings($from, $to, $timezone, $this->clock->now));
    }
}
