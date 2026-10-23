<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Http;

use ArrayObject;
use FxInstallments\Config\ConfigRepository;
use FxInstallments\Db\Connection;
use FxInstallments\Db\Retention;
use FxInstallments\Http\AutoBlockOutcome;
use FxInstallments\Http\IpBlock;
use FxInstallments\Http\IpBlocking;
use FxInstallments\Http\IpBlocks;
use FxInstallments\Http\RateLimiter;
use FxInstallments\Metrics\Metrics;
use FxInstallments\Metrics\RequestLog;
use FxInstallments\Tests\Support\TestEnvironment;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Automatic blocking. Rate-limited minutes are `rate_limits` rows over the limit (as
 * RateLimiter writes them; RateLimiterWindowsTest checks that against the real limiter);
 * other client errors are `requests` rows.
 */
final class IpBlockingTest extends TestCase
{
    /** The first second of a UTC minute. */
    private const NOW = 1_790_000_040;
    private const IP = '203.0.113.7';
    private const WINDOW = 600;      // block.window_minutes = 10
    private const DURATION = 3600;   // block.duration_minutes = 60
    private const PER_MINUTE = 30;   // ratelimit.per_minute

    private PDO $pdo;
    private RequestLog $requests;
    private IpBlocks $blocks;
    private Metrics $metrics;
    private RateLimiter $rateLimiter;

    /** @var ArrayObject<int, string> */
    private ArrayObject $log;

    protected function setUp(): void
    {
        $this->pdo = TestEnvironment::connect(TestEnvironment::freshDatabasePath());
        $db = Connection::wrap($this->pdo);
        $this->log = new ArrayObject();
        $log = $this->log;
        $note = static function (string $line) use ($log): void {
            $log[] = $line;
        };
        $this->requests = new RequestLog($db, $note);
        $this->blocks = new IpBlocks($db);
        $this->metrics = new Metrics($db, static fn (): int => self::NOW, $note);
        $this->rateLimiter = new RateLimiter($db, self::PER_MINUTE);
    }

    public function testTheDefaultsComeFromConfig(): void
    {
        $blocking = $this->fromConfig(new ConfigRepository());

        self::assertSame([10, 5, 100, 60], [$blocking->windowMinutes, $blocking->rateLimitHits, $blocking->errorResponses, $blocking->durationMinutes]);
    }

    public function testManyRateLimitedResponsesInOneMinuteCountOnce(): void
    {
        $blocking = $this->blocking(rateLimitHits: 2);

        $this->rateLimited(self::NOW, 80);

        self::assertNull($blocking->afterResponse(self::IP, 429, self::NOW + 59, null), 'one minute, however many 429s');
        self::assertSame([], $this->blockRows());
    }

    public function testA35RequestBurstInOneMinuteNeverBlocksWithTheDefaults(): void
    {
        $blocking = $this->fromConfig(new ConfigRepository());

        // 30 pass, 35 are rate-limited, all inside one minute.
        $this->rateLimited(self::NOW, 35);

        self::assertNull($blocking->afterResponse(self::IP, 429, self::NOW + 30, null));
    }

    public function testRateLimitedMinutesReachingTheThresholdBlock(): void
    {
        $blocking = $this->blocking(rateLimitHits: 3);

        $this->rateLimited(self::NOW, 5);
        $this->rateLimited(self::NOW + 60);
        self::assertNull($blocking->afterResponse(self::IP, 429, self::NOW + 60, null), 'two minutes are under three');

        $this->rateLimited(self::NOW + 120);
        $outcome = $blocking->afterResponse(self::IP, 429, self::NOW + 120, null);
        self::assertSame(AutoBlockOutcome::STARTED, $outcome?->outcome);
        self::assertEquals(new IpBlock(self::IP, 'rate_limit_hits', 'auto', self::NOW + 120, self::NOW + 120 + self::DURATION), $outcome->block);
        self::assertEquals($outcome->block, $this->blocks->find(self::IP));
    }

    public function testAMinuteAtTheLimitButNotOverItIsNotRateLimited(): void
    {
        $blocking = $this->blocking(rateLimitHits: 1);
        $this->insertWindow(self::NOW, self::PER_MINUTE);

        self::assertNull($blocking->afterResponse(self::IP, 429, self::NOW, null));
        $this->insertWindow(self::NOW, self::PER_MINUTE + 1);
        self::assertNotNull($blocking->afterResponse(self::IP, 429, self::NOW, null));
    }

    /**
     * The window for rate-limited minutes is the current minute and the window_minutes - 1
     * before it, wherever "now" falls inside the current minute.
     */
    public function testTheWindowEdgesForRateLimitedMinutes(): void
    {
        $blocking = $this->blocking(rateLimitHits: 2);
        $this->rateLimited(self::NOW);                          // the current minute
        $this->rateLimited(self::NOW - self::WINDOW);           // 10 minutes back: out

        foreach ([self::NOW, self::NOW + 59] as $now) {
            self::assertNull($blocking->afterResponse(self::IP, 429, $now, null));
        }

        $this->rateLimited(self::NOW - self::WINDOW + 60);      // 9 minutes back: in
        self::assertNotNull($blocking->afterResponse(self::IP, 429, self::NOW + 59, null));
    }

    public function testTheErrorResponseThresholdCountsEvery4xxExcept429(): void
    {
        $blocking = $this->blocking(rateLimitHits: 100, errorResponses: 5);

        foreach ([400, 401, 404, 499] as $status) {
            $this->log(self::NOW, $status);
        }
        // 429s never count here, in any number of minutes.
        for ($minute = 0; $minute < 5; $minute++) {
            $this->log(self::NOW - $minute * 60, 429, 10);
        }
        self::assertNull($blocking->afterResponse(self::IP, 404, self::NOW, null));

        $this->log(self::NOW, 404);
        self::assertSame('error_responses', $blocking->afterResponse(self::IP, 404, self::NOW, null)?->block->reason);
    }

    /**
     * A 429 can only move the rate-limited minutes and another 4xx only the error count, so
     * each response checks its own threshold.
     */
    public function testEachResponseChecksOnlyTheThresholdItCanMove(): void
    {
        $blocking = $this->blocking(rateLimitHits: 2, errorResponses: 2);
        $this->rateLimited(self::NOW - 60);
        $this->rateLimited(self::NOW);
        $this->log(self::NOW, 404, 2);

        self::assertSame('rate_limit_hits', $blocking->afterResponse(self::IP, 429, self::NOW, null)?->block->reason);
        $this->blocks->unblock(self::IP, self::NOW);
        self::assertSame('error_responses', $blocking->afterResponse(self::IP, 404, self::NOW, null)?->block->reason);
    }

    public function testServerErrorsSuccessesAndBlockedRequestsNeverCount(): void
    {
        $blocking = $this->blocking(rateLimitHits: 1, errorResponses: 1);
        $this->log(self::NOW, 500, 10);
        $this->log(self::NOW, 503, 10);
        $this->log(self::NOW, 200, 10);
        $this->log(self::NOW, 403, 10, blocked: true);
        $this->log(self::NOW, 404, 10, blocked: true);

        self::assertNull($blocking->afterResponse(self::IP, 404, self::NOW, null), 'nothing counted, the current 404 was not logged');
        self::assertSame([], $this->blockRows());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function statusesThatNeverTriggerACount(): iterable
    {
        foreach ([200, 204, 302, 399, 500, 503] as $status) {
            yield (string) $status => [$status];
        }
    }

    #[DataProvider('statusesThatNeverTriggerACount')]
    public function testOnlyA4xxResponseIsChecked(int $status): void
    {
        $blocking = $this->blocking(rateLimitHits: 1, errorResponses: 1);
        $this->rateLimited(self::NOW);
        $this->log(self::NOW, 404, 5);

        self::assertNull($blocking->afterResponse(self::IP, $status, self::NOW, null));
        self::assertSame([], $this->blockRows());
    }

    public function testTheWindowEdgesForErrorResponses(): void
    {
        $blocking = $this->blocking(errorResponses: 2);

        $this->log(self::NOW - self::WINDOW, 404);
        $this->log(self::NOW, 404);
        self::assertNull($blocking->afterResponse(self::IP, 404, self::NOW, null));

        $this->log(self::NOW - self::WINDOW + 1, 404);
        self::assertNotNull($blocking->afterResponse(self::IP, 404, self::NOW, null));
    }

    public function testOnlyThisIpsRequestsCount(): void
    {
        $blocking = $this->blocking(rateLimitHits: 2, errorResponses: 2);
        $this->rateLimited(self::NOW - 60, 1, '198.51.100.1');
        $this->log(self::NOW, 404, 5, ip: '198.51.100.1');
        $this->rateLimited(self::NOW);
        $this->log(self::NOW, 404);

        self::assertNull($blocking->afterResponse(self::IP, 429, self::NOW, null));
        self::assertNull($blocking->afterResponse(self::IP, 404, self::NOW, null));
    }

    public function testRequestsBeforeThePreviousBlockExpiredNeverCountAgain(): void
    {
        $blocking = $this->blocking(errorResponses: 3);
        $expiredAt = self::NOW - 60;
        $previous = new IpBlock(self::IP, 'error_responses', 'auto', $expiredAt - self::DURATION, $expiredAt);
        $this->log($expiredAt - 1, 404, 2);   // just before the block ended
        $this->log($expiredAt, 404);           // the block's last instant is already unblocked
        $this->log(self::NOW, 404);

        self::assertNull($blocking->afterResponse(self::IP, 404, self::NOW, $previous), 'only two 404s since the expiry');
        self::assertNotNull($blocking->afterResponse(self::IP, 404, self::NOW, null), 'four without the cutoff');
    }

    /**
     * rate_limits only knows minutes: the minute holding the previous block's end may hold
     * 429s from before a block that started and ended in it, so it counts only when the end is
     * its first second.
     */
    public function testRateLimitedMinutesBeforeThePreviousBlockEndedNeverCountAgain(): void
    {
        $blocking = $this->blocking(rateLimitHits: 2);
        $this->rateLimited(self::NOW - 120);
        $this->rateLimited(self::NOW - 60);   // the minute holding the block's end
        $this->rateLimited(self::NOW);

        // Ended 30 s into minute NOW - 60: that minute and the ones before it don't count.
        $endedMidMinute = new IpBlock(self::IP, 'r', 'manual', self::NOW - 50, self::NOW - 30);
        self::assertNull($blocking->afterResponse(self::IP, 429, self::NOW, $endedMidMinute));

        // Ended on the minute's first second: that minute holds only later traffic and counts.
        $endedOnTheMinute = new IpBlock(self::IP, 'r', 'manual', self::NOW - 600, self::NOW - 60);
        self::assertNotNull($blocking->afterResponse(self::IP, 429, self::NOW, $endedOnTheMinute));
    }

    public function testAPermanentPreviousBlockDoesNotMoveTheWindow(): void
    {
        $blocking = $this->blocking(errorResponses: 2, rateLimitHits: 2);
        $this->log(self::NOW - 10, 404, 2);
        $this->rateLimited(self::NOW - 60);
        $this->rateLimited(self::NOW);
        $permanent = new IpBlock(self::IP, 'r', 'manual', 0, null);

        self::assertNotNull($blocking->afterResponse(self::IP, 404, self::NOW, $permanent));
        $this->blocks->unblock(self::IP, self::NOW);
        self::assertNotNull($blocking->afterResponse(self::IP, 429, self::NOW, $permanent));
    }

    public function testAStartedBlockWritesAnIpBlockEventWithoutTheIp(): void
    {
        $blocking = $this->blocking(errorResponses: 1);
        $this->log(self::NOW, 404);

        $blocking->afterResponse(self::IP, 404, self::NOW, null);

        self::assertSame([[
            'ts' => self::NOW, 'source' => 'api', 'event_type' => 'ip.block', 'status' => 'ok', 'error_code' => null,
            'meta' => '{"block_source":"auto","reason":"error_responses","duration_minutes":60,"extended":false}',
        ]], $this->blockEvents());
    }

    public function testAnExtendedBlockWritesAnEventAndAnUnchangedOneWritesNone(): void
    {
        $blocking = $this->blocking(errorResponses: 1);
        $this->log(self::NOW, 404);

        // An active automatic block ending sooner: extended to now + the duration.
        $this->blocks->blockAutomatically(self::IP, 'rate_limit_hits', self::NOW - 600, self::NOW + 3000);
        self::assertSame(AutoBlockOutcome::EXTENDED, $blocking->afterResponse(self::IP, 404, self::NOW, null)?->outcome);
        self::assertSame(self::NOW + self::DURATION, $this->blocks->find(self::IP)?->expiresAt);

        // Already ending at least as late: unchanged, no event.
        self::assertSame(AutoBlockOutcome::UNCHANGED, $blocking->afterResponse(self::IP, 404, self::NOW, null)?->outcome);

        // An active manual block: unchanged, no event.
        $this->blocks->blockManually(self::IP, 'operator', self::NOW, null);
        self::assertSame(AutoBlockOutcome::UNCHANGED, $blocking->afterResponse(self::IP, 404, self::NOW, null)?->outcome);

        self::assertSame(
            ['{"block_source":"auto","reason":"error_responses","duration_minutes":60,"extended":true}'],
            array_column($this->blockEvents(), 'meta'),
        );
    }

    public function testAFailedEventWriteStillBlocks(): void
    {
        $this->pdo->exec('DROP TABLE events');
        $blocking = $this->blocking(errorResponses: 1);
        $this->log(self::NOW, 404);

        self::assertSame(AutoBlockOutcome::STARTED, $blocking->afterResponse(self::IP, 404, self::NOW, null)?->outcome);
        self::assertNotNull($this->blocks->find(self::IP));
        self::assertSame(['fx-installments metrics: event ip.block not recorded (PDOException)'], $this->log->getArrayCopy());
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function exemptAddresses(): iterable
    {
        yield 'IPv4 loopback' => ['127.0.0.1', null];
        yield 'other 127/8 address' => ['127.8.9.10', null];
        yield 'IPv6 loopback' => ['::1', null];
        yield 'IPv4-mapped loopback' => ['::ffff:127.0.0.1', null];
        yield 'the trusted proxy' => ['10.0.0.5', '10.0.0.5'];
        // L-04: compared as parsed addresses, like ClientIp.
        yield 'the trusted proxy, spelled another way' => ['2001:db8::5', '2001:DB8:0:0:0:0:0:5'];
        // L-04 cleanup B: an IPv4-mapped address is its IPv4 address (ADR 0016 fifth amendment).
        yield 'the trusted proxy, IPv4-mapped' => ['::ffff:10.0.0.5', '10.0.0.5'];
        yield 'the IPv4-mapped trusted proxy, as IPv4' => ['10.0.0.5', '::ffff:10.0.0.5'];
        yield 'empty' => ['', null];
        yield 'not an IP' => ['unknown', null];
    }

    #[DataProvider('exemptAddresses')]
    public function testLoopbackTheTrustedProxyAndNonIpsAreNeverBlockedAutomatically(string $ip, ?string $trustedProxy): void
    {
        $blocking = $this->blocking(rateLimitHits: 1, errorResponses: 1, trustedProxy: $trustedProxy);
        $this->log(self::NOW, 404, 3, ip: $ip);
        $this->rateLimited(self::NOW, 1, $ip);

        self::assertTrue($blocking->isExempt($ip));
        self::assertNull($blocking->afterResponse($ip, 404, self::NOW, null));
        self::assertNull($blocking->afterResponse($ip, 429, self::NOW, null));
        self::assertSame([], $this->blockRows());
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function blockableAddresses(): iterable
    {
        yield 'public IPv4' => ['203.0.113.7', null];
        yield 'private IPv4' => ['10.0.0.6', '10.0.0.5'];
        yield 'public IPv6' => ['2001:db8::1', null];
        yield 'IPv4-mapped public' => ['::ffff:203.0.113.7', null];
        yield 'IPv4-mapped, next to the trusted proxy' => ['::ffff:10.0.0.6', '10.0.0.5'];
        yield '128.0.0.1 is not loopback' => ['128.0.0.1', null];
        yield '::2 is not loopback' => ['::2', null];
    }

    #[DataProvider('blockableAddresses')]
    public function testOtherAddressesCanBeBlocked(string $ip, ?string $trustedProxy): void
    {
        $blocking = $this->blocking(rateLimitHits: 1, trustedProxy: $trustedProxy);
        $this->rateLimited(self::NOW, 1, $ip);

        self::assertFalse($blocking->isExempt($ip));
        self::assertNotNull($blocking->afterResponse($ip, 429, self::NOW, null));
    }

    /**
     * @return iterable<string, array{array<string, int>}>
     */
    public static function invalidSettings(): iterable
    {
        yield 'window 0' => [['block.window_minutes' => 0]];
        yield 'window over a day' => [['block.window_minutes' => IpBlocking::MAX_WINDOW_MINUTES + 1]];
        yield 'duration 0' => [['block.duration_minutes' => 0]];
        yield 'duration negative' => [['block.duration_minutes' => -60]];
        yield 'duration over a year' => [['block.duration_minutes' => IpBlocking::MAX_MINUTES + 1]];
        yield 'rate limit hits 0' => [['block.rate_limit_hits' => 0]];
        yield 'error responses 0' => [['block.error_responses' => 0]];
    }

    /**
     * @param array<string, int> $settings
     */
    #[DataProvider('invalidSettings')]
    public function testOutOfRangeSettingsFailLoudly(array $settings): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->fromConfig(new ConfigRepository($settings));
    }

    public function testTheLimitsThemselvesAreAccepted(): void
    {
        $blocking = $this->fromConfig(new ConfigRepository([
            'block.window_minutes' => IpBlocking::MAX_WINDOW_MINUTES,
            'block.duration_minutes' => IpBlocking::MAX_MINUTES,
            'block.rate_limit_hits' => 1,
            'block.error_responses' => 1,
        ]));

        self::assertSame(1440, $blocking->windowMinutes);
        self::assertSame(525_600, $blocking->durationMinutes);
    }

    /**
     * rate_limits rows live a day (Retention), so the longest window still has all its rows.
     */
    public function testTheLongestWindowFitsTheRateLimitsRetention(): void
    {
        $now = self::NOW + 59;
        $oldestCounted = RateLimiter::windowStart($now) - (IpBlocking::MAX_WINDOW_MINUTES - 1) * 60;
        self::assertGreaterThanOrEqual($now - Retention::RATE_LIMIT_DAYS * Retention::DAY_SECONDS, $oldestCounted);
    }

    public function testANonIntegerSettingFailsLoudly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->fromConfig(new ConfigRepository(['block.window_minutes' => '10']));
    }

    private function fromConfig(ConfigRepository $config): IpBlocking
    {
        return IpBlocking::fromConfig($this->blocks, $this->requests, $this->rateLimiter, $this->metrics, $config, null);
    }

    private function blocking(int $rateLimitHits = 5, int $errorResponses = 100, ?string $trustedProxy = null): IpBlocking
    {
        return new IpBlocking(
            $this->blocks,
            $this->requests,
            $this->rateLimiter,
            $this->metrics,
            self::WINDOW / 60,
            $rateLimitHits,
            $errorResponses,
            self::DURATION / 60,
            $trustedProxy,
        );
    }

    /**
     * The rate_limits row RateLimiter leaves for a minute in which $ip got $hits 429s.
     */
    private function rateLimited(int $ts, int $hits = 1, string $ip = self::IP): void
    {
        $this->insertWindow($ts, self::PER_MINUTE + $hits, $ip);
    }

    private function insertWindow(int $ts, int $count, string $ip = self::IP): void
    {
        $this->pdo->prepare('DELETE FROM rate_limits WHERE ip = ? AND window_start = ?')->execute([$ip, RateLimiter::windowStart($ts)]);
        $this->pdo->prepare('INSERT INTO rate_limits (ip, window_start, count) VALUES (?, ?, ?)')
            ->execute([$ip, RateLimiter::windowStart($ts), $count]);
    }

    private function log(int $ts, int $status, int $times = 1, bool $blocked = false, string $ip = self::IP): void
    {
        for ($i = 0; $i < $times; $i++) {
            self::assertTrue($this->requests->record($ts, $ip, 'GET', '/x', $status, 1, $blocked));
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function blockRows(): array
    {
        return $this->pdo->query('SELECT * FROM ip_blocks')->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function blockEvents(): array
    {
        return array_map(
            static fn (array $row): array => ['ts' => (int) $row['ts']] + $row,
            $this->pdo->query("SELECT ts, source, event_type, status, error_code, meta FROM events WHERE event_type LIKE 'ip.%' ORDER BY id")->fetchAll(),
        );
    }
}
