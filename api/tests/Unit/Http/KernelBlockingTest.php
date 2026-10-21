<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Http;

use ArrayObject;
use FxInstallments\Http\JsonResponse;
use FxInstallments\Http\Kernel;
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
