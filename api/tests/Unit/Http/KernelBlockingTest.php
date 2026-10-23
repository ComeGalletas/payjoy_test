<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Http;

use ArrayObject;
use FxInstallments\App;
use FxInstallments\Config\ConfigRepository;
use FxInstallments\Db\Connection;
use FxInstallments\Http\JsonResponse;
use FxInstallments\Http\Kernel;
use FxInstallments\Http\RateLimiter;
use FxInstallments\Http\Request;
use FxInstallments\Http\Router;
use FxInstallments\Tests\Support\FakeClock;
use FxInstallments\Tests\Support\OverridesEnvironment;
use FxInstallments\Tests\Support\TestEnvironment;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The Kernel's request-log and block-check steps (ADR 0016), through App::kernel().
 */
final class KernelBlockingTest extends TestCase
{
    use OverridesEnvironment;

    private const NOW = 1_790_000_000;
    private const IP = '203.0.113.7';

    private PDO $pdo;
    private FakeClock $clock;

    /** @var ArrayObject<int, string> */
    private ArrayObject $log;

    private int $handlerRuns = 0;

    protected function setUp(): void
    {
        $this->pdo = TestEnvironment::connect(TestEnvironment::freshDatabasePath());
        $this->clock = new FakeClock(self::NOW);
        $this->log = new ArrayObject();
    }

    public function testABlockedRequestIsAMinimal403WithConnectionClose(): void
    {
        $this->block(self::IP, null);

        $response = $this->kernel()->handle(new Request('GET', '/ok', remoteAddr: self::IP));

        self::assertSame(403, $response->status());
        self::assertSame(
            ['content-type' => 'application/json', 'x-content-type-options' => 'nosniff', 'connection' => 'close'],
            $response->headers(),
        );
        self::assertSame('{"ok":false,"error":{"code":"IP_BLOCKED","message_for_user":"Too many unusual requests came from your network. Please try again later."}}', $response->body());
        self::assertSame(0, $this->handlerRuns);
        self::assertSame(0, $this->rowCount('events'));
        self::assertSame([[self::IP, '/ok', 403, 1]], $this->requestRows());
    }

    public function testTheBlockEndsAtItsExpiryNotASecondLater(): void
    {
        $this->block(self::IP, self::NOW + 10);
        $kernel = $this->kernel();

        $this->clock->now = self::NOW + 9;
        self::assertSame(403, $kernel->handle(new Request('GET', '/ok', remoteAddr: self::IP))->status());
        $this->clock->now = self::NOW + 10;
        self::assertSame(200, $kernel->handle(new Request('GET', '/ok', remoteAddr: self::IP))->status());
    }

    public function testTheBlockAppliesToTheClientIpBehindTheTrustedProxy(): void
    {
        $this->setEnvironment('TRUSTED_PROXY', '10.0.0.5');
        $this->block(self::IP, null);
        $kernel = $this->kernel();

        $viaProxy = new Request('GET', '/ok', headers: ['X-Forwarded-For' => '198.51.100.1, ' . self::IP], remoteAddr: '10.0.0.5');
        self::assertSame(403, $kernel->handle($viaProxy)->status());

        // A forged first hop doesn't get the blocked client through, nor block anyone else.
        $forged = new Request('GET', '/ok', headers: ['X-Forwarded-For' => self::IP . ', 198.51.100.1'], remoteAddr: '10.0.0.5');
        self::assertSame(200, $kernel->handle($forged)->status());

        self::assertSame([[self::IP, '/ok', 403, 1], ['198.51.100.1', '/ok', 200, 0]], $this->requestRows());
    }

    public function testTheTrustedProxyItselfIsNeverBlockedAutomatically(): void
    {
        $this->setEnvironment('TRUSTED_PROXY', '10.0.0.5');
        $kernel = $this->kernel(['block.error_responses' => 1]);

        // No X-Forwarded-For: the proxy's own address is the client IP.
        $kernel->handle(new Request('GET', '/nope', remoteAddr: '10.0.0.5'));
        $kernel->handle(new Request('GET', '/nope', remoteAddr: '10.0.0.5'));

        self::assertSame(0, $this->rowCount('ip_blocks'));
        self::assertSame(200, $kernel->handle(new Request('GET', '/ok', remoteAddr: '10.0.0.5'))->status());
    }

    public function testLoopbackIsNeverBlockedAutomaticallyButCanBeBlockedByHand(): void
    {
        $kernel = $this->kernel(['block.error_responses' => 1]);
        $kernel->handle(new Request('GET', '/nope', remoteAddr: '127.0.0.1'));
        self::assertSame(0, $this->rowCount('ip_blocks'));
        self::assertSame(200, $kernel->handle(new Request('GET', '/ok', remoteAddr: '127.0.0.1'))->status());

        $this->block('127.0.0.1', null);
        self::assertSame(403, $kernel->handle(new Request('GET', '/ok', remoteAddr: '127.0.0.1'))->status());
    }

    public function testRequestsWhileBlockedNeitherCountNorExtendTheBlock(): void
    {
        $kernel = $this->kernel(['block.error_responses' => 2, 'block.duration_minutes' => 1]);
        $kernel->handle(new Request('GET', '/nope', remoteAddr: self::IP));
        $kernel->handle(new Request('GET', '/nope', remoteAddr: self::IP));
        self::assertSame(self::NOW + 60, (int) $this->pdo->query('SELECT expires_at FROM ip_blocks')->fetchColumn());

        for ($i = 0; $i < 30; $i++) {
            $this->clock->advance(1);
            self::assertSame(403, $kernel->handle(new Request('GET', '/nope', remoteAddr: self::IP))->status());
        }
        self::assertSame(self::NOW + 60, (int) $this->pdo->query('SELECT expires_at FROM ip_blocks')->fetchColumn());

        // After the expiry the earlier 404s and the blocked 403s don't count: one 404 is not two.
        $this->clock->now = self::NOW + 60;
        self::assertSame(404, $kernel->handle(new Request('GET', '/nope', remoteAddr: self::IP))->status());
        self::assertSame(200, $kernel->handle(new Request('GET', '/ok', remoteAddr: self::IP))->status());
        self::assertSame(404, $kernel->handle(new Request('GET', '/nope', remoteAddr: self::IP))->status());
        self::assertSame(403, $kernel->handle(new Request('GET', '/ok', remoteAddr: self::IP))->status());
    }

    public function testABlockCheckThatCantReadTheDatabaseLetsTheRequestThrough(): void
    {
        $this->pdo->exec('DROP TABLE ip_blocks');
        $kernel = $this->kernel(['block.error_responses' => 1]);

        $response = $kernel->handle(new Request('GET', '/ok', remoteAddr: self::IP));
        $notFound = $kernel->handle(new Request('GET', '/nope', remoteAddr: self::IP));

        self::assertSame(200, $response->status());
        self::assertSame(1, $this->handlerRuns);
        self::assertSame(404, $notFound->status());
        // Both requests are still logged; the failed check and the failed block are noted.
        self::assertSame([[self::IP, '/ok', 200, 0], [self::IP, '/nope', 404, 0]], $this->requestRows());
        self::assertSame([
            'fx-installments block check failed, request let through (PDOException)',
            'fx-installments block check failed, request let through (PDOException)',
            'fx-installments automatic block not applied (PDOException)',
        ], $this->log->getArrayCopy());
    }

    public function testAFailedRequestLogWriteDoesNotFailTheRequest(): void
    {
        $this->pdo->exec('DROP TABLE requests');
        $kernel = $this->kernel();

        self::assertSame(200, $kernel->handle(new Request('GET', '/ok', remoteAddr: self::IP))->status());
        $notFound = $kernel->handle(new Request('GET', '/nope', remoteAddr: self::IP));
        self::assertSame(404, $notFound->status());
        self::assertSame('NOT_FOUND', json_decode($notFound->body(), true, flags: JSON_THROW_ON_ERROR)['error']['code']);
        $this->block(self::IP, null);
        self::assertSame(403, $kernel->handle(new Request('GET', '/ok', remoteAddr: self::IP))->status());

        self::assertSame(1, $this->rowCount('events'), 'the 404 error event is still written');
        self::assertSame([
            'fx-installments request log: request not recorded (PDOException)',
            'fx-installments request log: request not recorded (PDOException)',
            // The 404's block evaluation reads the missing table too.
            'fx-installments automatic block not applied (PDOException)',
            'fx-installments request log: request not recorded (PDOException)',
        ], $this->log->getArrayCopy());
    }

    public function testAFailingErrorLogNeverBreaksTheResponse(): void
    {
        $this->pdo->exec('DROP TABLE ip_blocks');
        $app = TestEnvironment::app($this->clock, [], $this->pdo);
        $kernel = new Kernel($this->router(), $app->metrics, static function (): void {
            throw new \RuntimeException('log down');
        }, $app->requestLog(), $app->ipBlocking(), fn (): int => $this->clock->now);

        self::assertSame(200, $kernel->handle(new Request('GET', '/ok', remoteAddr: self::IP))->status());
    }

    public function testTheErrorEventUsesTheRequestLogPathAndMethodRules(): void
    {
        $path = '/caf' . "\u{e9}" . '/' . str_repeat('x', 300) . '#frag?q=1';
        $this->kernel()->handle(new Request("P\u{d3}ST", $path, remoteAddr: self::IP));

        $meta = json_decode((string) $this->pdo->query('SELECT meta FROM events')->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
        $row = $this->pdo->query('SELECT method, path FROM requests')->fetch();
        self::assertSame(['method' => $row['method'], 'path' => $row['path']], ['method' => $meta['method'], 'path' => $meta['path']]);
        self::assertSame('P%C3%93ST', $meta['method']);
        self::assertSame('/caf%C3%A9/' . str_repeat('x', 189), $meta['path']);
    }

    public function testEveryRequestWritesExactlyOneRow(): void
    {
        $kernel = $this->kernel();
        $requests = [
            new Request('GET', '/ok', remoteAddr: self::IP),
            new Request('GET', '/nope', remoteAddr: self::IP),
            new Request('OPTIONS', '/ok', remoteAddr: self::IP),
            new Request('PUT', '/ok', remoteAddr: self::IP),
        ];
        foreach ($requests as $i => $request) {
            $kernel->handle($request);
            self::assertSame($i + 1, $this->rowCount('requests'));
        }
    }

    /**
     * L-04: the Kernel reads the clock once per request and hands that reading to RateLimiter,
     * so a 429 always lands in the minute the block check reads, even when the clock crosses a
     * minute boundary while the request runs. The clock here moves one second on every read.
     */
    public function testTheRateLimiterCountsInTheKernelsMinute(): void
    {
        $minute = 1_790_000_040; // a multiple of 60
        $reads = 0;
        $clock = static function () use ($minute, &$reads): int {
            return $minute - 1 + $reads++;
        };
        $config = ['ratelimit.per_minute' => 1, 'block.rate_limit_hits' => 1];
        $app = new App(new ConfigRepository($config), Connection::wrap($this->pdo), $clock, function (string $line): void {
            $this->log[] = $line;
        });
        $router = new Router();
        $router->get('/limited', static fn (): JsonResponse => JsonResponse::ok(['ok' => true]), [$app->rateLimiter()]);
        $kernel = $app->kernel($router);

        self::assertSame(200, $kernel->handle(new Request('GET', '/limited', remoteAddr: self::IP))->status());
        $reads = 0; // the second request starts at the same second, one before the boundary
        self::assertSame(429, $kernel->handle(new Request('GET', '/limited', remoteAddr: self::IP))->status());

        // Both requests were counted in the minute before the boundary, the Kernel's minute...
        self::assertSame(
            [['window_start' => $minute - 60, 'count' => 2]],
            array_map(static fn (array $row): array => array_map('intval', $row), $this->pdo->query('SELECT window_start, count FROM rate_limits')->fetchAll()),
        );
        self::assertSame([$minute - 1, $minute - 1], array_map('intval', $this->pdo->query('SELECT ts FROM requests ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)));
        // ...so the 429 blocked the IP at once (block.rate_limit_hits = 1).
        self::assertSame(1, $this->rowCount('ip_blocks'));
        self::assertSame(403, $kernel->handle(new Request('GET', '/limited', remoteAddr: self::IP))->status());
        self::assertSame([], $this->log->getArrayCopy());
    }

    /**
     * A request that doesn't come through the Kernel (RateLimiter used alone) still reads the
     * limiter's own clock; a stamped one is counted in its stamp's minute (L-04 cleanup B: the
     * process() calls the name promises).
     */
    public function testARequestWithoutAKernelReadingUsesTheLimitersClock(): void
    {
        $request = new Request('GET', '/limited', remoteAddr: self::IP);
        self::assertNull($request->receivedAt());

        $stamped = $request->withReceivedAt(self::NOW);
        self::assertSame(self::NOW, $stamped->receivedAt());
        self::assertNull($request->receivedAt(), 'the original request is unchanged');
        self::assertSame([self::IP, '/limited', 'GET'], [$stamped->remoteAddr(), $stamped->path(), $stamped->method()]);

        // The limiter's clock is ten minutes after the stamp, so the two land in different rows.
        $limiterNow = self::NOW + 600;
        $limiter = new RateLimiter(Connection::wrap($this->pdo), 5, null, static fn (): int => $limiterNow);
        $next = static fn (Request $request): JsonResponse => JsonResponse::ok(['ok' => true]);
        self::assertSame(200, $limiter->process($request, $next)->status());
        self::assertSame(200, $limiter->process($stamped, $next)->status());

        self::assertSame(
            [
                ['window_start' => RateLimiter::windowStart(self::NOW), 'count' => 1],
                ['window_start' => RateLimiter::windowStart($limiterNow), 'count' => 1],
            ],
            array_map(static fn (array $row): array => array_map('intval', $row), $this->pdo->query('SELECT window_start, count FROM rate_limits ORDER BY window_start')->fetchAll()),
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function kernel(array $config = []): Kernel
    {
        $app = TestEnvironment::app($this->clock, $config, $this->pdo, $this->log);

        return $app->kernel($this->router());
    }

    private function router(): Router
    {
        $router = new Router();
        $router->get('/ok', function (): JsonResponse {
            $this->handlerRuns++;

            return JsonResponse::ok(['ok' => true]);
        });

        return $router;
    }

    private function block(string $ip, ?int $expiresAt): void
    {
        $this->pdo->prepare("INSERT INTO ip_blocks (ip, reason, source, blocked_at, expires_at) VALUES (?, 'test', 'manual', ?, ?)")
            ->execute([$ip, self::NOW, $expiresAt]);
    }

    private function rowCount(string $table): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    /**
     * @return list<array{string, string, int, int}>
     */
    private function requestRows(): array
    {
        return array_map(
            static fn (array $row): array => [$row['ip'], $row['path'], (int) $row['status'], (int) $row['blocked']],
            $this->pdo->query('SELECT ip, path, status, blocked FROM requests ORDER BY id')->fetchAll(),
        );
    }
}
