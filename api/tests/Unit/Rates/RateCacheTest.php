<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Rates;

use ArrayObject;
use Closure;
use FxInstallments\Db\Connection;
use FxInstallments\Metrics\ErrorCodes;
use FxInstallments\Metrics\Metrics;
use FxInstallments\Rates\BreakerState;
use FxInstallments\Rates\RateCache;
use FxInstallments\Rates\RateStore;
use FxInstallments\Rates\UpstreamBreaker;
use FxInstallments\Rates\UpstreamError;
use FxInstallments\Tests\Support\FakeClock;
use FxInstallments\Tests\Support\MockUpstream;
use FxInstallments\Tests\Support\TestEnvironment;
use GuzzleHttp\Exception\ConnectTimeoutException;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RateCacheTest extends TestCase
{
    private const HOUR = 3600;

    private string $path;

    private PDO $pdo;

    private FakeClock $clock;

    /** @var ArrayObject<int, string> */
    private ArrayObject $log;

    protected function setUp(): void
    {
        $this->path = TestEnvironment::freshDatabasePath();
        $this->pdo = TestEnvironment::connect($this->path);
        // An hour after upstream's update, well inside the refresh window.
        $this->clock = new FakeClock(MockUpstream::LAST_UPDATE + self::HOUR);
        $this->log = new ArrayObject();
    }

    protected function tearDown(): void
    {
        // Release the database and delete it: Windows keeps an open SQLite file, and the
        // suite's shutdown cleanup would leave it in %TEMP%.
        unset($this->pdo);
        self::assertTrue(TestEnvironment::removeDatabase($this->path), 'the test database was released and removed');
    }

    /**
     * Every kind of failed refresh. Each must back off, so five lookups in the next five
     * minutes cost exactly one upstream call.
     *
     * @return iterable<string, array{\Closure(): mixed}>
     */
    public static function failedRefreshes(): iterable
    {
        $request = static fn (): \Psr\Http\Message\RequestInterface => MockUpstream::request('k');
        yield 'connect timeout' => [static fn (): mixed => new ConnectTimeoutException('t', $request())];
        yield 'connect refused' => [static fn (): mixed => new \GuzzleHttp\Exception\ConnectException('c', $request())];
        yield 'network error' => [static fn (): mixed => new \GuzzleHttp\Exception\NetworkException('n', $request())];
        yield 'other throwable' => [static fn (): mixed => new \RuntimeException('x')];
        yield 'HTTP 500' => [static fn (): mixed => MockUpstream::json(500, '')];
        yield 'HTTP 404 page' => [static fn (): mixed => MockUpstream::json(404, '<html></html>')];
        yield 'not JSON' => [static fn (): mixed => MockUpstream::json(200, 'nope')];
        yield 'bad shape' => [static fn (): mixed => MockUpstream::json(200, '{"result":"success"}')];
        yield 'no valid rate' => [static fn (): mixed => MockUpstream::latest(['BRL' => -1])];
        yield 'unsupported-code' => [static fn (): mixed => MockUpstream::error('unsupported-code', 404)];
        yield 'unknown error-type' => [static fn (): mixed => MockUpstream::error('malformed-request', 400)];
        yield 'too large' => [static fn (): mixed => new \GuzzleHttp\Psr7\Response(200, ['Content-Length' => '99999999'], '{}')];
        yield 'gzip encoded' => [static fn (): mixed => new \GuzzleHttp\Psr7\Response(200, ['Content-Encoding' => 'gzip'], (string) gzencode(MockUpstream::latestBody()))];
        yield 'quota-reached' => [static fn (): mixed => MockUpstream::error('quota-reached', 429)];
        yield 'invalid-key' => [static fn (): mixed => MockUpstream::error('invalid-key', 403)];
        yield 'inactive-account' => [static fn (): mixed => MockUpstream::error('inactive-account', 403)];
    }

    /**
     * @param \Closure(): mixed $failure
     */
    #[DataProvider('failedRefreshes')]
    public function testFiveLookupsAfterAFailedRefreshMakeOneCall(\Closure $failure): void
    {
        foreach ([false, true] as $cached) {
            $this->pdo->exec('DELETE FROM rate_cache');
            $this->pdo->exec('DELETE FROM app_state WHERE key = \'upstream_breaker\'');
            if ($cached) {
                $this->seed();
            }
            $this->clock->now = MockUpstream::NEXT_UPDATE;
            // Spare answers: a second call would get a good table instead of failing.
            $upstream = new MockUpstream([$failure(), MockUpstream::latest(), MockUpstream::latest()]);
            $cache = $this->cache($upstream);

            // Five lookups inside the first (1-minute) backoff.
            foreach ([0, 10, 20, 40, 59] as $offset) {
                $this->clock->now = MockUpstream::NEXT_UPDATE + $offset;
                try {
                    $result = $cache->rate('BRL');
                    self::assertTrue($result->stale, 'only a stale rate can be served');
                } catch (UpstreamError) {
                    self::assertFalse($cached, 'with a cached table the stale rate is served');
                }
            }

            self::assertSame(1, $upstream->calls(), $cached ? 'with a cached table' : 'cold cache');
        }
    }

    public function testFiveLookupsAfterAFailedWriteMakeOneCall(): void
    {
        $readOnly = Connection::wrap(TestEnvironment::connectReadOnly($this->path));
        $writable = Connection::wrap($this->pdo);
        $clock = Closure::fromCallable($this->clock);
        $upstream = new MockUpstream([MockUpstream::latest(), MockUpstream::latest(), MockUpstream::latest()]);
        $cache = new RateCache(new RateStore($readOnly), $upstream->client(), new UpstreamBreaker($writable, $clock), new Metrics($writable, $clock), 48, $clock);

        self::assertSame(52_291_000_000, $cache->rate('BRL')->rateScaled, 'the fetched rate is served once');
        foreach ([1, 20, 40, 59] as $offset) {
            $this->clock->now = MockUpstream::LAST_UPDATE + 3600 + $offset;
            $error = $this->failure(fn () => $cache->rate('BRL'));
            self::assertSame('blocked_backoff', $error->reason);
        }

        self::assertSame(1, $upstream->calls());
        self::assertSame(BreakerState::BACKOFF, $this->events()[0]['meta']['breaker']);
        unset($cache, $readOnly);
    }

    public function testRepeatedFailuresCallUpstreamOnTheBackoffScheduleAndASuccessResetsIt(): void
    {
        $this->clock->now = MockUpstream::NEXT_UPDATE;
        $start = $this->clock->now;
        $queue = array_fill(0, 6, MockUpstream::json(503, ''));
        $queue[] = MockUpstream::latest(next: $start + 10 * 86400);
        $queue[] = MockUpstream::json(503, '');
        $upstream = new MockUpstream($queue);
        $cache = $this->cache($upstream);

        // Look up every minute for 4 hours: calls go out only when each backoff ends.
        $callTimes = [];
        for ($minute = 0; $minute <= 240; $minute++) {
            $this->clock->now = $start + 60 * $minute;
            $before = $upstream->calls();
            try {
                $cache->rate('BRL');
            } catch (UpstreamError) {
                // cold cache: no stale table
            }
            if ($upstream->calls() > $before) {
                $callTimes[] = $minute;
            }
        }
        // Failures at 0, 1, 6, 16, 36, 76 (waits 1, 5, 10, 20, 40, 60); the call at 136 succeeds.
        self::assertSame([0, 1, 6, 16, 36, 76, 136], $callTimes);
        self::assertNull((new UpstreamBreaker(Connection::wrap($this->pdo)))->state(), 'the success cleared the backoff');

        // Once the table is due again, the next failure waits 1 minute, not 60.
        $this->clock->now = $start + 10 * 86400;
        $error = $this->failure(fn () => $cache->rate('BRL'));
        self::assertSame(ErrorCodes::UPSTREAM_MALFORMED, $error->errorCode, 'the table is too old to serve stale');
        $state = (new UpstreamBreaker(Connection::wrap($this->pdo)))->state();
        self::assertSame(1, $state?->failures);
        self::assertSame(60, (int) $state?->until - $state->since);
        self::assertSame(8, $upstream->calls());
    }

    public function testAColdCacheRefreshesOnceAndStoresEveryRow(): void
    {
        $upstream = new MockUpstream([MockUpstream::latest()]);

        $result = $this->cache($upstream)->rate('BRL');

        self::assertSame(1, $upstream->calls());
        self::assertSame('BRL', $result->currency);
        self::assertSame(52_291_000_000, $result->rateScaled);
        self::assertSame(5.2291, $result->rate());
        self::assertSame(MockUpstream::LAST_UPDATE, $result->rateAsOf);
        self::assertFalse($result->stale);
        self::assertSame($this->clock->now, $result->fetchedAt);

        $rows = $this->pdo->query('SELECT * FROM rate_cache ORDER BY currency')->fetchAll();
        self::assertCount(count(MockUpstream::RATES), $rows);
        foreach ($rows as $row) {
            self::assertSame(MockUpstream::LAST_UPDATE, $row['last_update_unix']);
            self::assertSame(MockUpstream::NEXT_UPDATE, $row['next_update_unix']);
            self::assertSame($this->clock->now, $row['fetched_at']);
            self::assertIsInt($row['rate_scaled']);
        }
        self::assertSame(17_319_368_619_710_000, $this->scaled('IRR'));

        self::assertSame([['miss', 'ok', null]], $this->results());
        self::assertSame(count(MockUpstream::RATES), $this->events()[0]['meta']['currencies']);
    }

    public function testLookupsInOneRefreshWindowCostOneCall(): void
    {
        $upstream = new MockUpstream([MockUpstream::latest()]);
        $cache = $this->cache($upstream);

        $cache->rate('BRL');
        $this->clock->now = MockUpstream::NEXT_UPDATE - 1;
        $brl = $cache->rate('BRL');
        $eur = $cache->rate('EUR');

        self::assertSame(1, $upstream->calls());
        self::assertSame(52_291_000_000, $brl->rateScaled);
        self::assertSame(8_594_000_000, $eur->rateScaled);
        self::assertSame([['miss', 'ok', null], ['hit', 'ok', null], ['hit', 'ok', null]], $this->results());
    }

    public function testAHitIsServedFromTheStoredTableAcrossInstances(): void
    {
        $this->cache(new MockUpstream([MockUpstream::latest()]))->rate('USD');
        $second = new MockUpstream();

        self::assertSame(10_000_000_000, $this->cache($second)->rate('USD')->rateScaled);
        self::assertSame(0, $second->calls());
    }

    public function testTheNextUpdateTimeStartsANewRefresh(): void
    {
        $upstream = new MockUpstream([
            MockUpstream::latest(),
            MockUpstream::latest(['USD' => 1, 'BRL' => 5.3], MockUpstream::NEXT_UPDATE, MockUpstream::NEXT_UPDATE + 86400),
        ]);
        $cache = $this->cache($upstream);
        $cache->rate('BRL');

        $this->clock->now = MockUpstream::NEXT_UPDATE;
        $result = $cache->rate('BRL');

        self::assertSame(2, $upstream->calls());
        self::assertSame(53_000_000_000, $result->rateScaled);
        self::assertSame(MockUpstream::NEXT_UPDATE, $result->rateAsOf);
        self::assertSame(['BRL', 'USD'], $this->pdo->query('SELECT currency FROM rate_cache ORDER BY currency')->fetchAll(PDO::FETCH_COLUMN),
            'a refresh replaces every row: currencies upstream dropped are gone');
    }

    public function testANextUpdateTimeAlreadyPastWaitsTheMinimumBeforeRefreshingAgain(): void
    {
        // Upstream is late: its next update time is before now.
        $this->clock->now = MockUpstream::NEXT_UPDATE + 600;
        $upstream = new MockUpstream([MockUpstream::latest(), MockUpstream::latest()]);
        $cache = $this->cache($upstream);

        $cache->rate('BRL');
        self::assertSame($this->clock->now + RateCache::MIN_REFRESH_SECONDS, $this->pdo->query('SELECT next_update_unix FROM rate_cache LIMIT 1')->fetchColumn());
        $this->clock->advance(RateCache::MIN_REFRESH_SECONDS - 1);
        $cache->rate('BRL');
        self::assertSame(1, $upstream->calls());
        $this->clock->advance(1);
        $cache->rate('BRL');
        self::assertSame(2, $upstream->calls());
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function staleAges(): iterable
    {
        // Age = now - last_update_unix; served stale while strictly below 48 hours.
        yield 'just after the next update (24 h)' => [24 * self::HOUR, true];
        yield 'one second below the limit' => [48 * self::HOUR - 1, true];
        yield 'exactly the limit' => [48 * self::HOUR, false];
        yield 'past the limit' => [48 * self::HOUR + 1, false];
    }

    #[DataProvider('staleAges')]
    public function testARefreshFailureServesTheStoredTableWhileYoungerThanTheLimit(int $age, bool $stale): void
    {
        $this->seed();
        $this->clock->now = MockUpstream::LAST_UPDATE + $age;
        $upstream = new MockUpstream([MockUpstream::json(503, 'Service Unavailable')]);

        if ($stale) {
            $result = $this->cache($upstream)->rate('BRL');
            self::assertTrue($result->stale);
            self::assertSame(52_291_000_000, $result->rateScaled);
            self::assertSame(MockUpstream::LAST_UPDATE, $result->rateAsOf);
            $event = $this->events()[0];
            self::assertSame(['stale', 'ok', null], [$event['meta']['result'], $event['status'], $event['error_code']]);
            self::assertSame(ErrorCodes::UPSTREAM_MALFORMED, $event['meta']['refresh_error']);
            self::assertSame('http_status', $event['meta']['reason']);
            self::assertSame(503, $event['meta']['http_status']);
            self::assertTrue($event['meta']['stale']);
        } else {
            $error = $this->failure(fn () => $this->cache($upstream)->rate('BRL'));
            self::assertSame(ErrorCodes::UPSTREAM_MALFORMED, $error->errorCode);
            self::assertSame([['miss', 'error', ErrorCodes::UPSTREAM_MALFORMED]], $this->results());
        }
        self::assertSame(1, $upstream->calls());
    }

    public function testTheStaleAgeIsMeasuredFromUpstreamsUpdateNotTheFetch(): void
    {
        // Fetched only an hour ago, but the rate itself is 49 hours old: not served stale.
        $this->seed(fetchedAt: MockUpstream::LAST_UPDATE + 48 * self::HOUR, nextUpdate: MockUpstream::LAST_UPDATE + 48 * self::HOUR + 1);
        $this->clock->now = MockUpstream::LAST_UPDATE + 49 * self::HOUR;

        $error = $this->failure(fn () => $this->cache(new MockUpstream([new ConnectTimeoutException('t', MockUpstream::request('k'))]))->rate('BRL'));

        self::assertSame(ErrorCodes::UPSTREAM_TIMEOUT, $error->errorCode);
    }

    public function testMaxStaleHoursZeroNeverServesStale(): void
    {
        $this->seed();
        $this->clock->now = MockUpstream::NEXT_UPDATE;

        $error = $this->failure(fn () => $this->cache(new MockUpstream([MockUpstream::json(500, '')]), 0)->rate('BRL'));

        self::assertSame(ErrorCodes::UPSTREAM_MALFORMED, $error->errorCode);
    }

    public function testARefreshFailureOnAColdCacheReturnsTheMappedError(): void
    {
        $upstream = new MockUpstream([new ConnectTimeoutException('timed out', MockUpstream::request('k'))]);

        $error = $this->failure(fn () => $this->cache($upstream)->rate('BRL'));

        self::assertSame(ErrorCodes::UPSTREAM_TIMEOUT, $error->errorCode);
        self::assertSame('timeout', $error->reason);
        self::assertSame([['miss', 'error', ErrorCodes::UPSTREAM_TIMEOUT]], $this->results());
        self::assertSame(BreakerState::BACKOFF, $this->events()[0]['meta']['breaker']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM rate_cache')->fetchColumn());
    }

    public function testAfterAFailedRefreshLookupsBackOffForTheFirstBackoff(): void
    {
        $this->seed();
        $this->clock->now = MockUpstream::NEXT_UPDATE;
        $upstream = new MockUpstream([MockUpstream::json(502, ''), MockUpstream::latest(next: MockUpstream::NEXT_UPDATE + 86400)]);
        $cache = $this->cache($upstream);

        self::assertTrue($cache->rate('BRL')->stale);
        $this->clock->advance(UpstreamBreaker::BACKOFF_SECONDS - 1);
        $during = $cache->rate('EUR');
        self::assertTrue($during->stale);
        self::assertSame(1, $upstream->calls(), 'no call during the backoff');

        $this->clock->advance(1);
        self::assertFalse($cache->rate('BRL')->stale);
        self::assertSame(2, $upstream->calls());

        self::assertSame([['stale', 'ok', null], ['blocked', 'ok', null], ['miss', 'ok', null]], $this->results());
        self::assertSame(BreakerState::BACKOFF, $this->events()[1]['meta']['reason']);
        self::assertTrue($this->events()[1]['meta']['stale']);
    }

    public function testQuotaReachedBlocksCallsUntilNextMonth(): void
    {
        $this->seed();
        $this->clock->now = MockUpstream::NEXT_UPDATE;
        $upstream = new MockUpstream([MockUpstream::error('quota-reached', 429), MockUpstream::latest(
            ['USD' => 1, 'BRL' => 5.4],
            gmmktime(0, 0, 1, 11, 1, 2026),
            gmmktime(0, 0, 1, 11, 2, 2026),
        )]);
        $cache = $this->cache($upstream);

        $first = $cache->rate('BRL');
        self::assertTrue($first->stale, 'served stale after quota-reached');
        self::assertSame(BreakerState::QUOTA, $this->events()[0]['meta']['breaker']);

        // Still within the stale limit: blocked, served stale, no call.
        $this->clock->advance(12 * self::HOUR);
        self::assertTrue($cache->rate('BRL')->stale);
        // Past the stale limit: blocked, UPSTREAM_QUOTA, still no call.
        $this->clock->now = gmmktime(23, 59, 59, 10, 31, 2026);
        $error = $this->failure(fn () => $cache->rate('BRL'));
        self::assertSame(ErrorCodes::UPSTREAM_QUOTA, $error->errorCode);
        self::assertSame('blocked_quota', $error->reason);
        self::assertSame(1, $upstream->calls());

        // 00:00 UTC on the first: calls resume.
        $this->clock->now = gmmktime(0, 0, 0, 11, 1, 2026);
        $result = $cache->rate('BRL');
        self::assertFalse($result->stale);
        self::assertSame(54_000_000_000, $result->rateScaled);
        self::assertSame(2, $upstream->calls());

        self::assertSame([
            ['stale', 'ok', null],
            ['blocked', 'ok', null],
            ['blocked', 'error', ErrorCodes::UPSTREAM_QUOTA],
            ['miss', 'ok', null],
        ], $this->results());
    }

    #[DataProvider('authTypes')]
    public function testAnAuthErrorBlocksCallsUntilReset(string $type): void
    {
        $upstream = new MockUpstream([MockUpstream::error($type, 403), MockUpstream::latest()]);
        $db = Connection::wrap($this->pdo);
        $cache = $this->cache($upstream);

        self::assertSame(ErrorCodes::UPSTREAM_AUTH, $this->failure(fn () => $cache->rate('BRL'))->errorCode);
        $this->clock->advance(40 * 86400);
        $blocked = $this->failure(fn () => $cache->rate('BRL'));
        self::assertSame(ErrorCodes::UPSTREAM_AUTH, $blocked->errorCode);
        self::assertSame('blocked_auth', $blocked->reason);
        self::assertSame(1, $upstream->calls());

        (new UpstreamBreaker($db))->reset();
        self::assertSame(52_291_000_000, $cache->rate('BRL')->rateScaled);
        self::assertSame(2, $upstream->calls());
        self::assertSame([
            ['miss', 'error', ErrorCodes::UPSTREAM_AUTH],
            ['blocked', 'error', ErrorCodes::UPSTREAM_AUTH],
            ['miss', 'ok', null],
        ], $this->results());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function authTypes(): iterable
    {
        yield 'invalid-key' => ['invalid-key'];
        yield 'inactive-account' => ['inactive-account'];
    }

    public function testAMissingKeyFailsWithoutACallOrATrip(): void
    {
        $upstream = new MockUpstream([MockUpstream::latest()]);

        $error = $this->failure(fn () => $this->cache($upstream, key: null)->rate('BRL'));

        self::assertSame(ErrorCodes::UPSTREAM_AUTH, $error->errorCode);
        self::assertSame('no_key', $error->reason);
        self::assertSame(0, $upstream->calls());
        self::assertNull((new UpstreamBreaker(Connection::wrap($this->pdo)))->state());
        self::assertSame('no_key', $this->events()[0]['meta']['reason']);
    }

    public function testACurrencyMissingFromAFreshTableIsUnsupported(): void
    {
        $upstream = new MockUpstream([MockUpstream::latest()]);
        $cache = $this->cache($upstream);

        $miss = $this->failure(fn () => $cache->rate('XAF'));
        $hit = $this->failure(fn () => $cache->rate('XAF'));

        self::assertSame(ErrorCodes::UPSTREAM_UNSUPPORTED_CODE, $miss->errorCode);
        self::assertSame('not_in_table', $hit->reason);
        self::assertSame(400, $hit->status());
        self::assertSame(1, $upstream->calls());
        self::assertSame([
            ['miss', 'error', ErrorCodes::UPSTREAM_UNSUPPORTED_CODE],
            ['hit', 'error', ErrorCodes::UPSTREAM_UNSUPPORTED_CODE],
        ], $this->results());
    }

    public function testACurrencyMissingFromAStaleTableGetsTheRefreshError(): void
    {
        $this->seed();
        $this->clock->now = MockUpstream::NEXT_UPDATE;

        $error = $this->failure(fn () => $this->cache(new MockUpstream([MockUpstream::json(500, '')]))->rate('XAF'));

        self::assertSame(ErrorCodes::UPSTREAM_MALFORMED, $error->errorCode);
    }

    public function testOneBadCurrencyIsDroppedLoggedAndTheRestStored(): void
    {
        $body = str_replace(
            '"conversion_rates":{',
            '"conversion_rates":{"XYZ":-1,"bad code":5,',
            MockUpstream::latestBody(),
        );
        $upstream = new MockUpstream([MockUpstream::json(200, $body)]);

        self::assertSame(52_291_000_000, $this->cache($upstream)->rate('BRL')->rateScaled);

        self::assertSame(count(MockUpstream::RATES), (int) $this->pdo->query('SELECT COUNT(*) FROM rate_cache')->fetchColumn());
        $meta = $this->events()[0]['meta'];
        self::assertSame(2, $meta['dropped_count']);
        self::assertSame([['currency' => 'XYZ', 'reason' => 'not_positive'], ['currency' => null, 'reason' => 'code']], $meta['dropped']);
        self::assertStringNotContainsString('bad code', (string) $this->pdo->query('SELECT meta FROM events')->fetchColumn());
    }

    public function testAFailedWriteStillServesTheFetchedRate(): void
    {
        $readOnly = Connection::wrap(TestEnvironment::connectReadOnly($this->path));
        $writable = Connection::wrap($this->pdo);
        $clock = Closure::fromCallable($this->clock);
        $upstream = new MockUpstream([MockUpstream::latest()]);
        $cache = new RateCache(
            new RateStore($readOnly),
            $upstream->client(),
            new UpstreamBreaker($writable, $clock),
            new Metrics($writable, $clock),
            48,
            $clock,
        );

        $result = $cache->rate('BRL');

        self::assertSame(52_291_000_000, $result->rateScaled);
        self::assertTrue($this->events()[0]['meta']['store_failed']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM rate_cache')->fetchColumn());
        $state = (new UpstreamBreaker($writable))->state();
        self::assertSame(BreakerState::BACKOFF, $state?->kind, 'a failed write backs off too');
        self::assertSame(ErrorCodes::UPSTREAM_MALFORMED, $state?->errorCode);
        unset($cache, $readOnly);
    }

    public function testASuccessfulRefreshClearsAnExpiredBackoff(): void
    {
        $breaker = new UpstreamBreaker(Connection::wrap($this->pdo), Closure::fromCallable($this->clock));
        $breaker->trip(new UpstreamError(ErrorCodes::UPSTREAM_TIMEOUT, 'timeout'));
        $this->clock->advance(UpstreamBreaker::BACKOFF_SECONDS);

        $this->cache(new MockUpstream([MockUpstream::latest()]))->rate('BRL');

        self::assertNull($breaker->state());
    }

    public function testEveryLookupWritesOneUpstreamCallEventWithAKnownResult(): void
    {
        $upstream = new MockUpstream([MockUpstream::latest(), MockUpstream::json(500, '')]);
        $cache = $this->cache($upstream);

        $cache->rate('BRL');
        $cache->rate('BRL');
        $this->clock->now = MockUpstream::NEXT_UPDATE;
        $cache->rate('BRL');
        $cache->rate('BRL');

        self::assertSame([
            ['miss', 'ok', null],
            ['hit', 'ok', null],
            ['stale', 'ok', null],
            ['blocked', 'ok', null],
        ], $this->results());
        $events = $this->events();
        foreach ($events as $event) {
            self::assertSame('upstream.call', $event['event_type']);
            self::assertSame('upstream', $event['source']);
            self::assertSame('BRL', $event['currency']);
            self::assertContains($event['meta']['result'], ['hit', 'miss', 'stale', 'blocked']);
            self::assertSame('latest', $event['meta']['endpoint']);
            self::assertIsBool($event['meta']['stale']);
            self::assertIsInt($event['latency_ms']);
        }
    }

    public function testTheCurrencyMustBeAnUppercaseCode(): void
    {
        $upstream = new MockUpstream([MockUpstream::latest()]);

        foreach (['brl', 'BR', 'BRLX', ' BRL', "BRL\n", '../'] as $currency) {
            try {
                $this->cache($upstream)->rate($currency);
                self::fail('accepted ' . var_export($currency, true));
            } catch (InvalidArgumentException) {
                // expected
            }
        }
        self::assertSame(0, $upstream->calls());
    }

    public function testANegativeStaleLimitIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cache(new MockUpstream(), -1);
    }

    private function cache(MockUpstream $upstream, int $maxStaleHours = 48, ?string $key = MockUpstream::FAKE_KEY): RateCache
    {
        $db = Connection::wrap($this->pdo);
        $clock = Closure::fromCallable($this->clock);
        $log = $this->log;

        return new RateCache(
            new RateStore($db),
            $upstream->client($key),
            new UpstreamBreaker($db, $clock),
            new Metrics($db, $clock, static function (string $line) use ($log): void {
                $log[] = $line;
            }),
            $maxStaleHours,
            $clock,
        );
    }

    /**
     * Stores the MockUpstream table as if fetched at its update time.
     */
    private function seed(?int $fetchedAt = null, ?int $nextUpdate = null): void
    {
        $insert = $this->pdo->prepare('INSERT INTO rate_cache VALUES (?, ?, ?, ?, ?)');
        $insert->execute(['BRL', 52_291_000_000, MockUpstream::LAST_UPDATE, $nextUpdate ?? MockUpstream::NEXT_UPDATE, $fetchedAt ?? MockUpstream::LAST_UPDATE]);
        $insert->execute(['EUR', 8_594_000_000, MockUpstream::LAST_UPDATE, $nextUpdate ?? MockUpstream::NEXT_UPDATE, $fetchedAt ?? MockUpstream::LAST_UPDATE]);
        $insert->execute(['USD', 10_000_000_000, MockUpstream::LAST_UPDATE, $nextUpdate ?? MockUpstream::NEXT_UPDATE, $fetchedAt ?? MockUpstream::LAST_UPDATE]);
    }

    private function scaled(string $currency): int
    {
        $select = $this->pdo->prepare('SELECT rate_scaled FROM rate_cache WHERE currency = ?');
        $select->execute([$currency]);

        return (int) $select->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>> events in order, meta decoded
     */
    private function events(): array
    {
        $rows = $this->pdo->query('SELECT * FROM events ORDER BY id')->fetchAll();

        return array_map(static function (array $row): array {
            $row['meta'] = json_decode((string) $row['meta'], true, 16, JSON_THROW_ON_ERROR);

            return $row;
        }, $rows);
    }

    /**
     * @return list<array{string, string, string|null}> [meta.result, status, error_code] per event
     */
    private function results(): array
    {
        return array_map(
            static fn (array $event): array => [$event['meta']['result'], $event['status'], $event['error_code']],
            $this->events(),
        );
    }

    /**
     * @param \Closure(): mixed $call
     */
    private function failure(\Closure $call): UpstreamError
    {
        try {
            $call();
        } catch (UpstreamError $error) {
            return $error;
        }
        self::fail('Expected an UpstreamError');
    }
}
