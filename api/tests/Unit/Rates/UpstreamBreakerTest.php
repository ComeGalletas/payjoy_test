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
    public function testOtherFailuresBackOffForOneMinute(string $code, string $reason): void
    {
        $start = $this->clock->now;
        $state = $this->breaker->trip(new UpstreamError($code, $reason));

        self::assertNotNull($state);
        self::assertSame(BreakerState::BACKOFF, $state->kind);
        self::assertSame($start + 60, $state->until);
        self::assertSame($code, $this->breaker->blocking()?->errorCode);
        self::assertSame($code, $state->error()->errorCode);
        self::assertSame('blocked_backoff', $state->error()->reason);

        $this->clock->now = $start + 59;
        self::assertNotNull($this->breaker->blocking());
        $this->clock->now = $start + 60;
        self::assertNull($this->breaker->blocking());
        self::assertSame(BreakerState::BACKOFF, $this->breaker->state()?->kind, 'the expired trip stays visible');
    }

    public function testAMissingKeyDoesNotTrip(): void
    {
        // No call was made, so no quota was used.
        self::assertNull($this->breaker->trip(new UpstreamError(ErrorCodes::UPSTREAM_AUTH, 'no_key')));
        self::assertNull($this->breaker->state());
    }

    public function testUnsupportedCodeOnTheRefreshBacksOff(): void
    {
        // On /latest/USD it means a broken answer, not a user's currency.
        $state = $this->breaker->trip(new UpstreamError(ErrorCodes::UPSTREAM_UNSUPPORTED_CODE, 'unsupported-code'));

        self::assertSame(BreakerState::BACKOFF, $state?->kind);
        self::assertSame(ErrorCodes::UPSTREAM_UNSUPPORTED_CODE, $this->breaker->blocking()?->errorCode);
    }

    /**
     * P1-05 round 2: a failed INSERT with no row to overwrite throws, instead of reporting a
     * trip that was never stored.
     */
    public function testAFailedInsertThrowsAndStoresNothing(): void
    {
        $this->pdo->exec("CREATE TRIGGER no_breaker_rows BEFORE INSERT ON app_state BEGIN SELECT RAISE(ABORT, 'no'); END");

        try {
            $this->breaker->trip(new UpstreamError(ErrorCodes::UPSTREAM_UNSUPPORTED_CODE, 'unsupported-code'));
            self::fail('trip() reported a backoff that was not stored');
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        }
        self::assertNull($this->breaker->state());
        self::assertFalse($this->pdo->inTransaction());

        // An existing row is still overwritten by the UPDATE, so the trigger doesn't matter.
        $this->pdo->exec('DROP TRIGGER no_breaker_rows');
        $this->breaker->trip(new UpstreamError(ErrorCodes::UPSTREAM_TIMEOUT, 'timeout'));
        $this->pdo->exec("CREATE TRIGGER no_breaker_rows BEFORE INSERT ON app_state BEGIN SELECT RAISE(ABORT, 'no'); END");
        $this->clock->advance(UpstreamBreaker::BACKOFF_SECONDS + 1);
        self::assertSame(2, $this->breaker->trip(new UpstreamError(ErrorCodes::UPSTREAM_TIMEOUT, 'timeout'))?->failures);
        self::assertSame(2, $this->breaker->state()?->failures);
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
            ['kind' => 'quota', 'error_code' => 'UPSTREAM_QUOTA', 'since' => $this->clock->now,
                'until' => self::utc('2026-11-01 00:00:00'), 'failures' => 0],
            json_decode((string) $row['value'], true, 4, JSON_THROW_ON_ERROR),
        );
        self::assertSame($this->clock->now, (int) $row['updated_at']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function corruptValues(): iterable
    {
        // 2026-10-21 10:30:00 UTC; the next 1st is 1793491200.
        $since = gmmktime(10, 30, 0, 10, 21, 2026);
        $row = static fn (string $kind, string $code, mixed $until, mixed $failures, mixed $at = null): string => json_encode(
            ['kind' => $kind, 'error_code' => $code, 'since' => $at ?? $since, 'until' => $until, 'failures' => $failures],
            JSON_THROW_ON_ERROR,
        );
        yield 'not JSON' => ['quota'];
        yield 'a list' => ['[1]'];
        yield 'unknown kind' => [$row('forever', 'UPSTREAM_AUTH', null, 0)];
        yield 'unknown code' => [$row('auth', 'NOPE', null, 0)];
        yield 'non-upstream code' => [$row('auth', 'INTERNAL', null, 0)];
        yield 'quota with the auth code' => [$row('quota', 'UPSTREAM_AUTH', $since + 60, 0)];
        yield 'auth with the quota code' => [$row('auth', 'UPSTREAM_QUOTA', null, 0)];
        yield 'backoff with the auth code' => [$row('backoff', 'UPSTREAM_AUTH', $since + 300, 1)];
        yield 'backoff with the quota code' => [$row('backoff', 'UPSTREAM_QUOTA', $since + 300, 1)];
        yield 'quota without until' => [$row('quota', 'UPSTREAM_QUOTA', null, 0)];
        yield 'quota past the next 1st' => [$row('quota', 'UPSTREAM_QUOTA', gmmktime(0, 0, 1, 11, 1, 2026), 0)];
        yield 'quota for a year' => [$row('quota', 'UPSTREAM_QUOTA', $since + 365 * 86400, 0)];
        yield 'until before since' => [$row('quota', 'UPSTREAM_QUOTA', $since - 1, 0)];
        yield 'backoff over an hour' => [$row('backoff', 'UPSTREAM_TIMEOUT', $since + 3601, 5)];
        yield 'backoff without until' => [$row('backoff', 'UPSTREAM_TIMEOUT', null, 1)];
        yield 'backoff with no failures' => [$row('backoff', 'UPSTREAM_TIMEOUT', $since + 300, 0)];
        yield 'auth with an until' => [$row('auth', 'UPSTREAM_AUTH', $since + 300, 0)];
        yield 'auth with failures' => [$row('auth', 'UPSTREAM_AUTH', null, 3)];
        yield 'failures missing' => ['{"kind":"auth","error_code":"UPSTREAM_AUTH","since":1,"until":null}'];
        yield 'failures as text' => [$row('backoff', 'UPSTREAM_TIMEOUT', $since + 300, '1')];
        yield 'since as text' => [$row('auth', 'UPSTREAM_AUTH', null, 0, '1')];
    }

    public function testTheLongestValidStatesAreAccepted(): void
    {
        $since = $this->clock->now;
        self::assertNotNull(BreakerState::fromJson(json_encode(
            ['kind' => 'backoff', 'error_code' => 'UPSTREAM_TIMEOUT', 'since' => $since, 'until' => $since + 3600, 'failures' => 9],
            JSON_THROW_ON_ERROR,
        )));
        self::assertNotNull(BreakerState::fromJson(json_encode(
            ['kind' => 'quota', 'error_code' => 'UPSTREAM_QUOTA', 'since' => $since, 'until' => self::utc('2026-11-01 00:00:00'), 'failures' => 0],
            JSON_THROW_ON_ERROR,
        )));
        self::assertNotNull(BreakerState::fromJson(json_encode(
            ['kind' => 'backoff', 'error_code' => 'UPSTREAM_UNSUPPORTED_CODE', 'since' => $since, 'until' => $since + 300, 'failures' => 1],
            JSON_THROW_ON_ERROR,
        )));
    }

    public function testConsecutiveFailuresBackOff15102040Then60Minutes(): void
    {
        $waits = [];
        foreach (range(1, 8) as $failure) {
            $state = $this->breaker->trip(new UpstreamError(ErrorCodes::UPSTREAM_TIMEOUT, 'timeout'));
            self::assertNotNull($state);
            self::assertSame($failure, $state->failures);
            $waits[] = intdiv((int) $state->until - $state->since, 60);
            // The next refresh can only happen once this backoff has ended.
            $this->clock->now = (int) $state->until;
        }

        self::assertSame([1, 5, 10, 20, 40, 60, 60, 60], $waits);
        self::assertSame([60, 300, 600, 1200, 2400, 3600, 3600], array_map(UpstreamBreaker::backoffSeconds(...), range(1, 7)));
        self::assertSame(60, UpstreamBreaker::backoffSeconds(0), 'fewer than one failure counts as the first');
        self::assertSame(3600, UpstreamBreaker::backoffSeconds(1000));
    }

    public function testASuccessfulRefreshResetsTheBackoffToOneMinute(): void
    {
        foreach (range(1, 3) as $ignored) {
            $state = $this->breaker->trip(new UpstreamError(ErrorCodes::UPSTREAM_MALFORMED, 'json'));
            $this->clock->now = (int) $state?->until;
        }
        self::assertSame(3, $this->breaker->state()?->failures);

        $this->breaker->succeeded();
        self::assertNull($this->breaker->state());

        $state = $this->breaker->trip(new UpstreamError(ErrorCodes::UPSTREAM_TIMEOUT, 'timeout'));
        self::assertSame(1, $state?->failures);
        self::assertSame(60, (int) $state?->until - $state->since);
    }

    public function testASuccessLeavesAnOpenQuotaOrAuthBlockButClearsAnExpiredOne(): void
    {
        $this->breaker->trip(new UpstreamError(ErrorCodes::UPSTREAM_AUTH, 'invalid-key'));
        $this->breaker->succeeded();
        self::assertSame(BreakerState::AUTH, $this->breaker->state()?->kind);
        $this->breaker->reset();

        $this->breaker->trip(new UpstreamError(ErrorCodes::UPSTREAM_QUOTA, 'quota-reached'));
        $this->breaker->succeeded();
        self::assertSame(BreakerState::QUOTA, $this->breaker->state()?->kind);

        $this->clock->now = self::utc('2026-11-01 00:00:00');
        $this->breaker->succeeded();
        self::assertNull($this->breaker->state());

        // With nothing stored, a success writes nothing.
        $this->breaker->succeeded();
        self::assertSame(['schema_version'], $this->pdo->query('SELECT key FROM app_state')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testAWeakerTripNeverShortensAStrongerOne(): void
    {
        $quota = $this->breaker->trip(new UpstreamError(ErrorCodes::UPSTREAM_QUOTA, 'quota-reached'));
        foreach ([60, 3600, 86400] as $later) {
            $this->clock->now = (int) $quota?->since + $later;
            self::assertEquals($quota, $this->breaker->trip(new UpstreamError(ErrorCodes::UPSTREAM_TIMEOUT, 'timeout')));
            self::assertEquals($quota, $this->breaker->trip(new UpstreamError(ErrorCodes::UPSTREAM_UNSUPPORTED_CODE, 'unsupported-code')));
        }
        self::assertSame(self::utc('2026-11-01 00:00:00'), $this->breaker->state()?->until);
    }

    public function testAnOpenBackoffIsNeitherExtendedNorCountedTwice(): void
    {
        $first = $this->breaker->trip(new UpstreamError(ErrorCodes::UPSTREAM_TIMEOUT, 'timeout'));
        $this->clock->advance(10);

        // A second process failing during the same outage.
        $second = $this->breaker->trip(new UpstreamError(ErrorCodes::UPSTREAM_MALFORMED, 'json'));

        self::assertEquals($first, $second);
        self::assertSame(1, $this->breaker->state()?->failures);
    }

    public function testAnExpiredQuotaBlockStartsTheBackoffAtOneMinute(): void
    {
        $this->breaker->trip(new UpstreamError(ErrorCodes::UPSTREAM_QUOTA, 'quota-reached'));
        $this->clock->now = self::utc('2026-11-01 00:00:00');

        $state = $this->breaker->trip(new UpstreamError(ErrorCodes::UPSTREAM_TIMEOUT, 'timeout'));

        self::assertSame(1, $state?->failures);
        self::assertSame(60, (int) $state?->until - $state->since);
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
