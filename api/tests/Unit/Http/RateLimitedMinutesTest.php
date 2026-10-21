<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Http;

use FxInstallments\Db\Connection;
use FxInstallments\Http\JsonResponse;
use FxInstallments\Http\RateLimiter;
use FxInstallments\Http\Request;
use FxInstallments\Http\Router;
use FxInstallments\Metrics\ErrorCodes;
use FxInstallments\Tests\Support\FakeClock;
use FxInstallments\Tests\Support\HookedPdo;
use FxInstallments\Tests\Support\OverridesEnvironment;
use FxInstallments\Tests\Support\TestEnvironment;
use PDO;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Rate-limited minutes are read from `rate_limits` (RateLimiter::rateLimitedWindows()). That
 * rests on three facts about RateLimiter, checked here, and buys a constant cost per 429.
 */
final class RateLimitedMinutesTest extends TestCase
{
    use OverridesEnvironment;

    private const MINUTE = 1_790_000_040;
    private const IP = '203.0.113.7';

    /**
     * (a) Every request RateLimiter sees is counted, the ones it refuses included, so a
     * window's count is over the limit exactly when that window had a 429.
     */
    public function testEveryRequestIsCountedSoOverTheLimitMeansA429(): void
    {
        $pdo = TestEnvironment::connect(TestEnvironment::freshDatabasePath());
        $clock = new FakeClock(self::MINUTE);
        $limiter = new RateLimiter(Connection::wrap($pdo), 2, null, \Closure::fromCallable($clock));
        $request = new Request('GET', '/x', remoteAddr: self::IP);
        $next = static fn (): JsonResponse => JsonResponse::ok(['ok' => true]);

        $statuses = [];
        for ($i = 1; $i <= 2; $i++) {
            $statuses[] = $limiter->process($request, $next)->status();
        }
        self::assertSame(0, $limiter->rateLimitedWindows(self::IP, self::MINUTE, self::MINUTE), 'at the limit: no 429 yet');
        for ($i = 1; $i <= 5; $i++) {
            $statuses[] = $limiter->process($request, $next)->status();
        }

        self::assertSame([200, 200, 429, 429, 429, 429, 429], $statuses);
        self::assertSame(7, (int) $pdo->query('SELECT count FROM rate_limits')->fetchColumn(), 'refused requests are counted too');
        self::assertSame(1, $limiter->rateLimitedWindows(self::IP, self::MINUTE, self::MINUTE));

        // The next minute is a new window; the range bounds are inclusive.
        $clock->advance(60);
        $limiter->process($request, $next);
        $limiter->process($request, $next);
        $limiter->process($request, $next);
        self::assertSame(2, $limiter->rateLimitedWindows(self::IP, self::MINUTE, self::MINUTE + 60));
        self::assertSame(1, $limiter->rateLimitedWindows(self::IP, self::MINUTE + 60, self::MINUTE + 60));
        self::assertSame(0, $limiter->rateLimitedWindows('198.51.100.1', 0, PHP_INT_MAX));
    }

    /**
     * (b) RateLimiter keys rate_limits by the same client IP the Kernel logs, behind the
     * trusted proxy too.
     */
    public function testRateLimitsAreKeyedByTheLoggedClientIp(): void
    {
        $this->setEnvironment('TRUSTED_PROXY', '10.0.0.5');
        $pdo = TestEnvironment::connect(TestEnvironment::freshDatabasePath());
        $app = TestEnvironment::app(new FakeClock(self::MINUTE), ['ratelimit.per_minute' => 1], $pdo);
        $router = new Router();
        $router->get('/limited', static fn (): JsonResponse => JsonResponse::ok(['ok' => true]), [$app->rateLimiter()]);
        $kernel = $app->kernel($router);

        foreach ([
            new Request('GET', '/limited', headers: ['X-Forwarded-For' => '192.0.2.1, ' . self::IP], remoteAddr: '10.0.0.5'),
            new Request('GET', '/limited', headers: ['X-Forwarded-For' => self::IP], remoteAddr: '198.51.100.9'),
            new Request('GET', '/limited', remoteAddr: '2001:db8::5'),
        ] as $request) {
            $kernel->handle($request);
        }

        self::assertSame(
            $pdo->query('SELECT DISTINCT ip FROM requests ORDER BY ip')->fetchAll(PDO::FETCH_COLUMN),
            $pdo->query('SELECT ip FROM rate_limits ORDER BY ip')->fetchAll(PDO::FETCH_COLUMN),
        );
        self::assertSame(['198.51.100.9', '2001:db8::5', self::IP], $pdo->query('SELECT ip FROM rate_limits ORDER BY ip')->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * (c) Only RateLimiter answers 429: RATE_LIMITED is the only code with that status,
     * JsonResponse::ok() refuses non-2xx statuses, and no other class in src/ names the code.
     */
    public function testOnlyRateLimiterAnswers429(): void
    {
        $with429 = array_values(array_filter(ErrorCodes::all(), static fn (string $code): bool => ErrorCodes::httpStatus($code) === 429));
        self::assertSame([ErrorCodes::RATE_LIMITED], $with429);

        try {
            JsonResponse::ok([], 429);
            self::fail('ok() must refuse a 429');
        } catch (\InvalidArgumentException) {
            // expected
        }

        $src = dirname(__DIR__, 3) . '/src';
        $users = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php'
                && preg_match('/RATE_LIMITED|\b429\b/', (string) file_get_contents($file->getPathname())) === 1) {
                $users[] = str_replace('\\', '/', substr($file->getPathname(), strlen($src) + 1));
            }
        }
        sort($users);
        // ErrorCodes defines it, RateLimiter answers it; IpBlocking and RequestLog only read it.
        self::assertSame(['Http/IpBlocking.php', 'Http/RateLimiter.php', 'Metrics/ErrorCodes.php', 'Metrics/RequestLog.php'], $users);
        foreach (['Http/IpBlocking.php', 'Metrics/RequestLog.php'] as $reader) {
            self::assertStringNotContainsString('RATE_LIMITED', (string) file_get_contents($src . '/' . $reader));
        }
    }

    /**
     * A 429 costs the same statements whether the IP has 10 or 1,000 requests rows in the
     * window, and none of them reads `requests`; the rate_limits count is a primary-key range.
     */
    public function testA429CostsTheSameWhateverTheIpsRequestHistory(): void
    {
        $small = $this->statementsForOne429(10);
        $large = $this->statementsForOne429(1000);

        self::assertSame($small, $large);
        foreach ($small as $sql) {
            self::assertDoesNotMatchRegularExpression('/^SELECT .*FROM requests/', $sql, 'a 429 never scans the request log');
        }
        self::assertContains(
            'SELECT COUNT(*) FROM rate_limits WHERE ip = ? AND window_start >= ? AND window_start <= ? AND count > ?',
            $small,
        );

        $pdo = TestEnvironment::connect(TestEnvironment::freshDatabasePath());
        $plan = implode(' | ', $pdo->query(
            "EXPLAIN QUERY PLAN SELECT COUNT(*) FROM rate_limits WHERE ip = '1' AND window_start >= 0 AND window_start <= 60 AND count > 30",
        )->fetchAll(PDO::FETCH_COLUMN, 3));
        self::assertMatchesRegularExpression('/SEARCH rate_limits USING (INDEX sqlite_autoindex_rate_limits_1|PRIMARY KEY) \(ip=\? AND window_start>\? AND window_start<\?\)/', $plan);
    }

    /**
     * @return list<string> the SQL the kernel prepares for one 429, after $rows earlier
     *         requests from the IP in the window
     */
    private function statementsForOne429(int $rows): array
    {
        $path = TestEnvironment::freshDatabasePath();
        $seed = TestEnvironment::connect($path);
        $insert = $seed->prepare("INSERT INTO requests (ts, ip, method, path, status, latency_ms, blocked) VALUES (?, ?, 'GET', '/limited', ?, 1, 0)");
        $seed->beginTransaction();
        for ($i = 0; $i < $rows; $i++) {
            $insert->execute([self::MINUTE - 300 + intdiv($i * 300, $rows), self::IP, $i % 2 === 0 ? 429 : 200]);
        }
        $seed->commit();

        $hooked = HookedPdo::open($path);
        $app = TestEnvironment::app(new FakeClock(self::MINUTE), ['ratelimit.per_minute' => 1], $hooked);
        $router = new Router();
        $router->get('/limited', static fn (): JsonResponse => JsonResponse::ok(['ok' => true]), [$app->rateLimiter()]);
        $kernel = $app->kernel($router);
        self::assertSame(200, $kernel->handle(new Request('GET', '/limited', remoteAddr: self::IP))->status());

        $hooked->sql = [];
        self::assertSame(429, $kernel->handle(new Request('GET', '/limited', remoteAddr: self::IP))->status());

        return $hooked->sql;
    }
}
