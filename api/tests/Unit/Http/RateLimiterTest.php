<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Http;

use Closure;
use FxInstallments\Db\Connection;
use FxInstallments\Http\JsonResponse;
use FxInstallments\Http\RateLimiter;
use FxInstallments\Http\Request;
use FxInstallments\Tests\Support\FakeClock;
use FxInstallments\Tests\Support\OverridesEnvironment;
use FxInstallments\Tests\Support\TestEnvironment;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    use OverridesEnvironment;

    private const MINUTE = 1_790_000_040; // a multiple of 60

    private PDO $pdo;
    private FakeClock $clock;

    protected function setUp(): void
    {
        $this->pdo = TestEnvironment::connect(TestEnvironment::freshDatabasePath());
        $this->clock = new FakeClock(self::MINUTE);
    }

    public function testTheLimitResetsInTheNextMinute(): void
    {
        $limiter = $this->limiter(2);

        self::assertSame(200, $this->hit($limiter)->status());
        self::assertSame(200, $this->hit($limiter)->status());
        $this->clock->advance(59);
        $limited = $this->hit($limiter);
        self::assertSame(429, $limited->status());
        self::assertSame('RATE_LIMITED', $limited->errorCode());
        self::assertSame('1', $limited->header('Retry-After'));

        $this->clock->advance(1);
        self::assertSame(200, $this->hit($limiter)->status());
    }

    public function testRetryAfterCountsDownToTheNextWindow(): void
    {
        $limiter = $this->limiter(1);
        $this->hit($limiter);
        $this->clock->advance(15);

        self::assertSame('45', $this->hit($limiter)->header('Retry-After'));
    }

    public function testCountsArePerIpAndStoredInRateLimits(): void
    {
        $limiter = $this->limiter(1);
        $this->hit($limiter, '198.51.100.1');
        $this->hit($limiter, '198.51.100.1');
        $this->hit($limiter, '198.51.100.2');

        $rows = $this->pdo->query('SELECT ip, window_start, count FROM rate_limits ORDER BY ip')->fetchAll();
        self::assertSame([
            ['ip' => '198.51.100.1', 'window_start' => self::MINUTE, 'count' => 2],
            ['ip' => '198.51.100.2', 'window_start' => self::MINUTE, 'count' => 1],
        ], $rows);
    }

    public function testAForgedForwardedForDoesNotBypassTheLimit(): void
    {
        $limiter = $this->limiter(1);

        self::assertSame(200, $this->hit($limiter, '198.51.100.1', '203.0.113.1')->status());
        self::assertSame(429, $this->hit($limiter, '198.51.100.1', '203.0.113.2')->status());
    }

    public function testBehindTheTrustedProxyTheForwardedClientIsCounted(): void
    {
        $limiter = $this->limiter(1, '10.0.0.2');

        self::assertSame(200, $this->hit($limiter, '10.0.0.2', '203.0.113.1')->status());
        self::assertSame(200, $this->hit($limiter, '10.0.0.2', '203.0.113.2')->status());
        self::assertSame(429, $this->hit($limiter, '10.0.0.2', '203.0.113.1')->status());
    }

    public function testTheHandlerIsNotCalledWhenLimited(): void
    {
        $limiter = $this->limiter(1);
        $calls = 0;
        $next = static function () use (&$calls): JsonResponse {
            $calls++;

            return JsonResponse::ok();
        };
        $limiter->process(new Request('GET', '/x'), $next);
        $limiter->process(new Request('GET', '/x'), $next);

        self::assertSame(1, $calls);
    }

    public function testTheLimitMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->limiter(0);
    }

    public function testAppWiresTheConfiguredLimitAndTrustedProxy(): void
    {
        $this->setEnvironment('TRUSTED_PROXY', '10.0.0.2');
        $app = TestEnvironment::app($this->clock, ['ratelimit.per_minute' => 1], $this->pdo);
        $limiter = $app->rateLimiter();

        self::assertSame(200, $this->hit($limiter, '10.0.0.2', '203.0.113.1')->status());
        self::assertSame(200, $this->hit($limiter, '10.0.0.2', '203.0.113.2')->status());
        self::assertSame(429, $this->hit($limiter, '10.0.0.2', '203.0.113.2')->status());
    }

    private function limiter(int $perMinute, ?string $trustedProxy = null): RateLimiter
    {
        return new RateLimiter(Connection::wrap($this->pdo), $perMinute, $trustedProxy, Closure::fromCallable($this->clock));
    }

    private function hit(RateLimiter $limiter, string $ip = '198.51.100.1', ?string $forwardedFor = null): JsonResponse
    {
        $headers = $forwardedFor === null ? [] : ['X-Forwarded-For' => $forwardedFor];

        return $limiter->process(
            new Request('GET', '/x', [], $headers, '', $ip),
            static fn (): JsonResponse => JsonResponse::ok(),
        );
    }
}
