<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Rates;

use Closure;
use FxInstallments\Db\Connection;
use FxInstallments\Metrics\ErrorCodes;
use FxInstallments\Metrics\Metrics;
use FxInstallments\Rates\BreakerState;
use FxInstallments\Rates\Quota;
use FxInstallments\Rates\QuotaCache;
use FxInstallments\Rates\QuotaStatus;
use FxInstallments\Rates\UpstreamBreaker;
use FxInstallments\Rates\UpstreamError;
use FxInstallments\Tests\Support\FakeClock;
use FxInstallments\Tests\Support\MockUpstream;
use FxInstallments\Tests\Support\TestEnvironment;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuotaCacheTest extends TestCase
{
    private const NOW = 1792638000;

    private string $path;

    private PDO $pdo;

    private FakeClock $clock;

    protected function setUp(): void
    {
        $this->path = TestEnvironment::freshDatabasePath();
        $this->pdo = TestEnvironment::connect($this->path);
        $this->clock = new FakeClock(self::NOW);
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        self::assertTrue(TestEnvironment::removeDatabase($this->path));
    }

    public function testUpstreamIsAskedOncePerCachePeriod(): void
    {
        $upstream = new MockUpstream([self::quota(1500, 1497, 21), self::quota(1500, 1490, 21)]);
        $cache = $this->cache($upstream);

        $first = $cache->status();
        self::assertSame([QuotaStatus::UPSTREAM, self::NOW, null], [$first->source, $first->checkedAt, $first->errorCode]);
        self::assertEquals(new Quota(1500, 1497, 21), $first->quota);

        $this->clock->advance(59 * 60 + 59);
        $cached = $cache->status();
        self::assertSame([QuotaStatus::CACHE, self::NOW], [$cached->source, $cached->checkedAt]);
        self::assertEquals(new Quota(1500, 1497, 21), $cached->quota);
        self::assertSame(1, $upstream->calls());

        $this->clock->advance(1);
        self::assertSame(1490, $cache->status()->quota?->requestsRemaining);
        self::assertSame(2, $upstream->calls());
        self::assertSame(['quota'], array_map(static fn (string $url): string => substr($url, strrpos($url, '/') + 1), array_unique($upstream->urls())));

        self::assertSame([
            ['upstream', null, 'ok', null, ['result' => 'miss', 'endpoint' => 'quota']],
            ['upstream', null, 'ok', null, ['result' => 'hit', 'endpoint' => 'quota']],
            ['upstream', null, 'ok', null, ['result' => 'miss', 'endpoint' => 'quota']],
        ], $this->events());
    }

    public function testAFailureIsCachedTooSoAFailingUpstreamIsNotAskedOnEveryView(): void
    {
        $upstream = new MockUpstream([MockUpstream::json(500, 'oops ' . MockUpstream::FAKE_KEY)]);
        $cache = $this->cache($upstream);

        $failed = $cache->status();
        self::assertSame([QuotaStatus::UPSTREAM, null, ErrorCodes::UPSTREAM_MALFORMED], [$failed->source, $failed->quota, $failed->errorCode]);
        $again = $cache->status();
        self::assertSame([QuotaStatus::CACHE, ErrorCodes::UPSTREAM_MALFORMED], [$again->source, $again->errorCode]);
        self::assertSame(1, $upstream->calls());

        self::assertSame([
            ['upstream', null, 'error', 'UPSTREAM_MALFORMED', ['result' => 'miss', 'endpoint' => 'quota', 'reason' => 'http_status']],
            ['upstream', null, 'error', 'UPSTREAM_MALFORMED', ['result' => 'hit', 'endpoint' => 'quota']],
        ], $this->events());
        self::assertStringNotContainsString(MockUpstream::FAKE_KEY, (string) $this->pdo->query('SELECT group_concat(value) FROM app_state')->fetchColumn());
        self::assertStringNotContainsString('oops', (string) $this->pdo->query('SELECT group_concat(meta) FROM events')->fetchColumn());
    }

    public function testNoKeyIsRecordedAsNoCall(): void
    {
        $upstream = new MockUpstream();
        $cache = new QuotaCache(Connection::wrap($this->pdo), $upstream->client(null), $this->breaker(), $this->metrics(), 60, Closure::fromCallable($this->clock));

        self::assertSame(ErrorCodes::UPSTREAM_AUTH, $cache->status()->errorCode);
        self::assertSame(0, $upstream->calls());
        self::assertSame([['upstream', null, 'error', 'UPSTREAM_AUTH', ['result' => 'miss', 'endpoint' => 'quota', 'reason' => 'no_key']]], $this->events());
    }

    public function testAnAuthBlockSkipsUpstreamAndKeepsTheLastAnswer(): void
    {
        $upstream = new MockUpstream([self::quota(1500, 1200, 3)]);
        $cache = $this->cache($upstream);
        $cache->status();
        $this->breaker()->trip(new UpstreamError(ErrorCodes::UPSTREAM_AUTH, 'invalid-key'));
        $this->clock->advance(3600);

        $blocked = $cache->status();

        self::assertSame([QuotaStatus::BLOCKED, self::NOW, ErrorCodes::UPSTREAM_AUTH], [$blocked->source, $blocked->checkedAt, $blocked->errorCode]);
        self::assertSame(1200, $blocked->quota?->requestsRemaining);
        self::assertSame(1, $upstream->calls());
        self::assertSame(['result' => 'blocked', 'endpoint' => 'quota', 'reason' => 'auth'], $this->events()[1][4]);
    }

    /**
     * Round 2: `mayAsk: false` (a database that can't be written) never asks upstream; a fresh
     * cached answer is still a hit, an old one comes back as `blocked`.
     */
    public function testWithoutAskingTheLastAnswerIsShown(): void
    {
        $upstream = new MockUpstream([self::quota(1500, 1497, 21)]);
        $cache = $this->cache($upstream);
        self::assertSame(QuotaStatus::BLOCKED, $cache->status(false)->source, 'nothing stored yet');
        $cache->status();
        self::assertSame(QuotaStatus::CACHE, $cache->status(false)->source);
        $this->clock->advance(7200);

        $old = $cache->status(false);

        self::assertSame([QuotaStatus::BLOCKED, self::NOW, 1497, null], [$old->source, $old->checkedAt, $old->quota?->requestsRemaining, $old->errorCode]);
        self::assertSame(1, $upstream->calls());
        self::assertSame(['result' => 'blocked', 'endpoint' => 'quota', 'reason' => 'read_only'], $this->events()[3][4]);
    }

    public function testOtherTripsDoNotStopTheQuotaCall(): void
    {
        $upstream = new MockUpstream([self::quota(1500, 0, 1)]);
        $this->breaker()->trip(new UpstreamError(ErrorCodes::UPSTREAM_QUOTA, 'quota-reached'));

        self::assertSame(0, $this->cache($upstream)->status()->quota?->requestsRemaining);
        self::assertSame(BreakerState::QUOTA, $this->breaker()->state()?->kind, 'a /quota call never changes the breaker');
    }

    public function testAReadOnlyDatabaseStillAnswers(): void
    {
        $upstream = new MockUpstream([self::quota(1500, 1497, 21), self::quota(1500, 1496, 21)]);
        $readOnly = TestEnvironment::connectReadOnly($this->path);
        $db = Connection::wrap($readOnly);
        $cache = new QuotaCache($db, $upstream->client(), new UpstreamBreaker($db, Closure::fromCallable($this->clock)), new Metrics($db, null, static function (): void {
        }), 60, Closure::fromCallable($this->clock));

        self::assertSame(1497, $cache->status()->quota?->requestsRemaining);
        self::assertSame(1496, $cache->status()->quota?->requestsRemaining, 'nothing was cached, so upstream is asked again');
        unset($cache, $db, $readOnly);
    }

    public function testADamagedRowIsIgnored(): void
    {
        $this->pdo->prepare('INSERT INTO app_state (key, value, updated_at) VALUES (?, ?, ?)')->execute([QuotaCache::STATE_KEY, '{"checked_at":' . self::NOW . ',"plan_quota":"x"}', self::NOW]);
        $upstream = new MockUpstream([self::quota(1500, 1497, 21)]);

        self::assertSame(QuotaStatus::UPSTREAM, $this->cache($upstream)->status()->source);
        self::assertSame(1, $upstream->calls());
    }

    public function testTheCachePeriodIsAtLeastAMinute(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cache(new MockUpstream(), 0);
    }

    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function refreshDates(): iterable
    {
        // NOW is 2026-10-22 (UTC).
        yield 'later this month' => [25, self::NOW, '2026-10-25'];
        yield 'today' => [22, self::NOW, '2026-10-22'];
        yield 'next month' => [21, self::NOW, '2026-11-21'];
        yield 'the 31st in November is the 30th' => [31, gmmktime(0, 0, 0, 11, 2, 2026), '2026-11-30'];
        yield 'into a short February' => [30, gmmktime(0, 0, 0, 1, 31, 2027), '2027-02-28'];
        yield 'into next year' => [1, gmmktime(0, 0, 0, 12, 5, 2026), '2027-01-01'];
    }

    #[DataProvider('refreshDates')]
    public function testTheNextUpstreamRefreshDate(int $day, int $now, string $expected): void
    {
        self::assertSame($expected, (new Quota(1500, 10, $day))->nextRefreshDate($now));
    }

    private function cache(MockUpstream $upstream, int $minutes = 60): QuotaCache
    {
        return new QuotaCache(Connection::wrap($this->pdo), $upstream->client(), $this->breaker(), $this->metrics(), $minutes, Closure::fromCallable($this->clock));
    }

    private function breaker(): UpstreamBreaker
    {
        return new UpstreamBreaker(Connection::wrap($this->pdo), Closure::fromCallable($this->clock));
    }

    private function metrics(): Metrics
    {
        return new Metrics(Connection::wrap($this->pdo), Closure::fromCallable($this->clock));
    }

    private static function quota(int $plan, int $remaining, int $day): \GuzzleHttp\Psr7\Response
    {
        return MockUpstream::json(200, json_encode([
            'result' => 'success',
            'plan_quota' => $plan,
            'requests_remaining' => $remaining,
            'refresh_day_of_month' => $day,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<array{string, ?string, string, ?string, array<string, mixed>}>
     */
    private function events(): array
    {
        return array_map(
            static fn (array $row): array => [$row['source'], $row['currency'], $row['status'], $row['error_code'], json_decode((string) $row['meta'], true, flags: JSON_THROW_ON_ERROR)],
            $this->pdo->query("SELECT source, currency, status, error_code, meta FROM events WHERE event_type = 'upstream.call' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
        );
    }
}
