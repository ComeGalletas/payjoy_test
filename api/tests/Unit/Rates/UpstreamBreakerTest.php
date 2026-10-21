<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Rates;

use Closure;
use FxInstallments\Db\Connection;
use FxInstallments\Metrics\ErrorCodes;
use FxInstallments\Rates\BreakerState;
use FxInstallments\Rates\UpstreamBreaker;
use FxInstallments\Rates\UpstreamError;
use FxInstallments\Tests\Support\FakeClock;
use FxInstallments\Tests\Support\TestEnvironment;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UpstreamBreakerTest extends TestCase
{
    private PDO $pdo;

    private FakeClock $clock;

    private UpstreamBreaker $breaker;

    protected function setUp(): void
    {
        $this->pdo = TestEnvironment::connect(TestEnvironment::freshDatabasePath());
        $this->clock = new FakeClock(gmmktime(10, 30, 0, 10, 21, 2026));
        $this->breaker = new UpstreamBreaker(Connection::wrap($this->pdo), Closure::fromCallable($this->clock));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function monthRollovers(): iterable
    {
        yield 'mid-month' => ['2026-10-21 10:30:00', '2026-11-01 00:00:00'];
        yield 'first second of a month' => ['2026-10-01 00:00:00', '2026-11-01 00:00:00'];
        yield 'last second of a month' => ['2026-10-31 23:59:59', '2026-11-01 00:00:00'];
        yield 'December to January' => ['2026-12-15 12:00:00', '2027-01-01 00:00:00'];
        yield 'last second of the year' => ['2026-12-31 23:59:59', '2027-01-01 00:00:00'];
        yield 'January 31 to February' => ['2027-01-31 08:00:00', '2027-02-01 00:00:00'];
        yield 'leap day' => ['2028-02-29 18:00:00', '2028-03-01 00:00:00'];
        yield 'February of a leap year' => ['2028-02-01 00:00:00', '2028-03-01 00:00:00'];
        yield 'February of a common year' => ['2027-02-28 23:59:59', '2027-03-01 00:00:00'];
        yield 'century year 2100 is not leap' => ['2100-02-28 12:00:00', '2100-03-01 00:00:00'];
    }

    #[DataProvider('monthRollovers')]
    public function testQuotaBlocksUntilTheFirstOfNextMonthUtc(string $at, string $until): void
    {
        $now = self::utc($at);
        $this->clock->now = $now;

        $state = $this->breaker->trip(new UpstreamError(ErrorCodes::UPSTREAM_QUOTA, 'quota-reached'));

        self::assertNotNull($state);
        self::assertSame(BreakerState::QUOTA, $state->kind);
        self::assertSame(self::utc($until), $state->until);
        self::assertSame(self::utc($until), UpstreamBreaker::nextMonthStart($now));

        $this->clock->now = self::utc($until) - 1;
        self::assertNotNull($this->breaker->blocking(), 'still blocked one second before');
        $this->clock->now = self::utc($until);
        self::assertNull($this->breaker->blocking(), 'open again at 00:00 UTC on the first');
    }

    public function testNextMonthStartIgnoresTheDefaultTimezone(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('America/Bogota');
        try {
            // 2026-10-31 22:00 in Bogota is already November in UTC.
            self::assertSame(self::utc('2026-12-01 00:00:00'), UpstreamBreaker::nextMonthStart(self::utc('2026-11-01 03:00:00')));
        } finally {
            date_default_timezone_set($previous);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function authReasons(): iterable
    {
        yield 'invalid-key' => ['invalid-key'];
        yield 'inactive-account' => ['inactive-account'];
    }

    #[DataProvider('authReasons')]
    public function testAuthBlocksUntilReset(string $reason): void
    {
        $state = $this->breaker->trip(new UpstreamError(ErrorCodes::UPSTREAM_AUTH, $reason));

        self::assertNotNull($state);
        self::assertSame(BreakerState::AUTH, $state->kind);
        self::assertNull($state->until);

        $this->clock->advance(10 * 365 * 86400);
        self::assertSame(BreakerState::AUTH, $this->breaker->blocking()?->kind, 'still blocked years later');

        $cleared = $this->breaker->reset();
        self::assertSame(BreakerState::AUTH, $cleared?->kind);
        self::assertNull($this->breaker->blocking());
        self::assertNull($this->breaker->state());
        self::assertNull($this->breaker->reset(), 'a second reset clears nothing');
    }

    public function testResetAlsoClearsAQuotaBlock(): void
    {
        $this->breaker->trip(new UpstreamError(ErrorCodes::UPSTREAM_QUOTA, 'quota-reached'));

        self::assertSame(BreakerState::QUOTA, $this->breaker->reset()?->kind);
        self::assertNull($this->breaker->blocking());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function transientErrors(): iterable
    {
        yield 'timeout' => [ErrorCodes::UPSTREAM_TIMEOUT, 'timeout'];
        yield 'connect' => [ErrorCodes::UPSTREAM_TIMEOUT, 'connect'];
        yield 'malformed' => [ErrorCodes::UPSTREAM_MALFORMED, 'json'];
        yield 'http status' => [ErrorCodes::UPSTREAM_MALFORMED, 'http_status'];
    }

    #[DataProvider('transientErrors')]
    public function testOtherFailuresBackOffForFiveMinutes(string $code, string $reason): void
    {
        $start = $this->clock->now;
        $state = $this->breaker->trip(new UpstreamError($code, $reason));

        self::assertNotNull($state);
        self::assertSame(BreakerState::BACKOFF, $state->kind);
        self::assertSame($start + 300, $state->until);
        self::assertSame($code, $this->breaker->blocking()?->errorCode);
        self::assertSame($code, $state->error()->errorCode);
        self::assertSame('blocked_backoff', $state->error()->reason);

        $this->clock->now = $start + 299;
        self::assertNotNull($this->breaker->blocking());
        $this->clock->now = $start + 300;
        self::assertNull($this->breaker->blocking());
        self::assertSame(BreakerState::BACKOFF, $this->breaker->state()?->kind, 'the expired trip stays visible');
    }

    public function testUnsupportedCodeAndAMissingKeyDoNotTrip(): void
    {
        self::assertNull($this->breaker->trip(new UpstreamError(ErrorCodes::UPSTREAM_UNSUPPORTED_CODE, 'unsupported-code')));
        self::assertNull($this->breaker->trip(new UpstreamError(ErrorCodes::UPSTREAM_AUTH, 'no_key')));
        self::assertNull($this->breaker->state());
    }

    public function testAWeakerTripNeverReplacesAStrongerOpenOne(): void
    {
        $this->breaker->trip(new UpstreamError(ErrorCodes::UPSTREAM_AUTH, 'invalid-key'));

        $kept = $this->breaker->trip(new UpstreamError(ErrorCodes::UPSTREAM_TIMEOUT, 'timeout'));
        self::assertSame(BreakerState::AUTH, $kept?->kind);
        $kept = $this->breaker->trip(new UpstreamError(ErrorCodes::UPSTREAM_QUOTA, 'quota-reached'));
        self::assertSame(BreakerState::AUTH, $kept?->kind);
        self::assertSame(BreakerState::AUTH, $this->breaker->state()?->kind);
    }

    public function testAStrongerTripReplacesAWeakerOne(): void
    {
        $this->breaker->trip(new UpstreamError(ErrorCodes::UPSTREAM_TIMEOUT, 'timeout'));
        $this->breaker->trip(new UpstreamError(ErrorCodes::UPSTREAM_QUOTA, 'quota-reached'));

        self::assertSame(BreakerState::QUOTA, $this->breaker->state()?->kind);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM app_state WHERE key = 'upstream_breaker'")->fetchColumn());
    }

    public function testAnExpiredTripIsReplacedByAnyNewOne(): void
    {
        $this->breaker->trip(new UpstreamError(ErrorCodes::UPSTREAM_QUOTA, 'quota-reached'));
        $this->clock->now = self::utc('2026-11-01 00:00:05');

        $state = $this->breaker->trip(new UpstreamError(ErrorCodes::UPSTREAM_TIMEOUT, 'timeout'));

        self::assertSame(BreakerState::BACKOFF, $state?->kind);
    }

    public function testTheStateIsStoredInAppState(): void
    {
        $this->breaker->trip(new UpstreamError(ErrorCodes::UPSTREAM_QUOTA, 'quota-reached'));

        $row = $this->pdo->query("SELECT value, updated_at FROM app_state WHERE key = 'upstream_breaker'")->fetch();
        self::assertSame(
            ['kind' => 'quota', 'error_code' => 'UPSTREAM_QUOTA', 'since' => $this->clock->now, 'until' => self::utc('2026-11-01 00:00:00')],
            json_decode((string) $row['value'], true, 4, JSON_THROW_ON_ERROR),
        );
        self::assertSame($this->clock->now, (int) $row['updated_at']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function corruptValues(): iterable
    {
        yield 'not JSON' => ['quota'];
        yield 'a list' => ['[1]'];
        yield 'unknown kind' => ['{"kind":"forever","error_code":"UPSTREAM_AUTH","since":1,"until":null}'];
        yield 'unknown code' => ['{"kind":"auth","error_code":"NOPE","since":1,"until":null}'];
        yield 'non-upstream code' => ['{"kind":"auth","error_code":"INTERNAL","since":1,"until":null}'];
        yield 'quota without until' => ['{"kind":"quota","error_code":"UPSTREAM_QUOTA","since":1,"until":null}'];
        yield 'until before since' => ['{"kind":"quota","error_code":"UPSTREAM_QUOTA","since":5,"until":4}'];
        yield 'since as text' => ['{"kind":"auth","error_code":"UPSTREAM_AUTH","since":"1","until":null}'];
    }

    #[DataProvider('corruptValues')]
    public function testACorruptStoredStateCountsAsClosed(string $value): void
    {
        $this->pdo->prepare('INSERT INTO app_state (key, value, updated_at) VALUES (?, ?, 0)')
            ->execute([UpstreamBreaker::STATE_KEY, $value]);

        self::assertNull($this->breaker->state());
        self::assertNull($this->breaker->blocking());
        // A new trip overwrites the bad row.
        self::assertSame(BreakerState::AUTH, $this->breaker->trip(new UpstreamError(ErrorCodes::UPSTREAM_AUTH, 'invalid-key'))?->kind);
        self::assertSame(BreakerState::AUTH, $this->breaker->state()?->kind);
    }

    public function testResetLeavesOtherAppStateRowsAlone(): void
    {
        $this->breaker->trip(new UpstreamError(ErrorCodes::UPSTREAM_AUTH, 'invalid-key'));
        $this->breaker->reset();

        self::assertSame(
            ['schema_version'],
            $this->pdo->query('SELECT key FROM app_state ORDER BY key')->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    private static function utc(string $dateTime): int
    {
        return (new \DateTimeImmutable($dateTime, new \DateTimeZone('UTC')))->getTimestamp();
    }
}
