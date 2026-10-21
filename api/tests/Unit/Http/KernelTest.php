<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Http;

use ArrayObject;
use Closure;
use FxInstallments\Db\Connection;
use FxInstallments\Http\JsonResponse;
use FxInstallments\Http\Kernel;
use FxInstallments\Http\Request;
use FxInstallments\Http\Router;
use FxInstallments\Metrics\Metrics;
use FxInstallments\Tests\Support\FakeClock;
use FxInstallments\Tests\Support\OverridesEnvironment;
use FxInstallments\Tests\Support\TestEnvironment;
use FxInstallments\Tests\Support\TestRoutes;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class KernelTest extends TestCase
{
    use OverridesEnvironment;

    private const GENERIC_500 =
        '{"ok":false,"error":{"code":"INTERNAL","message_for_user":"Something went wrong on our side. Please try again later."}}';

    public function testAnUncaughtThrowableIsAGeneric500ThatLeaksNothing(): void
    {
        [$kernel, $pdo, $log] = $this->kernelWithTestRoutes();

        $response = $kernel->handle(new Request('GET', TestRoutes::BOOM));

        self::assertSame(500, $response->status());
        self::assertSame(self::GENERIC_500, $response->body());
        foreach (['secret detail', 'test-fake-key-not-real', 'C:\\secret', 'RuntimeException', '#0', 'TestRoutes'] as $leak) {
            self::assertStringNotContainsString($leak, $response->body());
        }

        // The event names the code and exception class, never the message.
        $event = $pdo->query('SELECT error_code, status, meta FROM events')->fetch();
        self::assertSame('INTERNAL', $event['error_code']);
        self::assertSame('error', $event['status']);
        $meta = json_decode((string) $event['meta'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['http_status' => 500, 'method' => 'GET', 'path' => '/test/boom', 'exception' => RuntimeException::class], $meta);
        self::assertStringNotContainsString('secret', (string) $event['meta']);

        // The error log gets class, file and line only.
        self::assertCount(1, $log);
        self::assertStringStartsWith('fx-installments INTERNAL: RuntimeException at ', $log[0]);
        self::assertStringNotContainsString('secret detail', $log[0]);
        self::assertStringNotContainsString('test-fake-key-not-real', $log[0]);
    }

    public function testAHandlerReturningTheWrongTypeIsA500(): void
    {
        $router = new Router();
        $router->get('/bad', static fn () => ['not' => 'a response']);
        $kernel = new Kernel($router, new Metrics(Connection::wrap(TestEnvironment::connect(TestEnvironment::freshDatabasePath()))), static function (): void {
        });

        self::assertSame(self::GENERIC_500, $kernel->handle(new Request('GET', '/bad'))->body());
    }

    public function testErrorResponsesAreRecordedWithoutTheQueryString(): void
    {
        [$kernel, $pdo] = $this->kernelWithTestRoutes();

        $kernel->handle(new Request('GET', '/nope', ['token' => 'secret-token']));

        $event = $pdo->query('SELECT ts, event_type, source, error_code, latency_ms, meta FROM events')->fetch();
        self::assertSame(1_790_000_000, (int) $event['ts']);
        self::assertSame('error', $event['event_type']);
        self::assertSame('api', $event['source']);
        self::assertSame('NOT_FOUND', $event['error_code']);
        self::assertGreaterThanOrEqual(0, (int) $event['latency_ms']);
        self::assertSame('{"http_status":404,"method":"GET","path":"/nope"}', $event['meta']);
    }

    public function testSuccessfulResponsesWriteNoErrorEvent(): void
    {
        [$kernel, $pdo] = $this->kernelWithTestRoutes();

        self::assertSame(200, $kernel->handle(new Request('POST', TestRoutes::CORS))->status());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn());
    }

    /**
     * INV-04: every response the kernel produces carries the envelope.
     */
    #[DataProvider('everyKindOfResponse')]
    public function testEveryResponseHasTheEnvelope(string $method, string $path, array $headers): void
    {
        $this->setEnvironment('ADMIN_TOKEN', 'admin-token');
        [$kernel] = $this->kernelWithTestRoutes(['cors.allowed_origins' => ['https://shop.example']]);

        $response = $kernel->handle(new Request($method, $path, [], $headers));

        self::assertSame('application/json', $response->header('Content-Type'));
        $body = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        if ($response->isOk()) {
            self::assertSame(['ok', 'data'], array_keys($body));
            self::assertTrue($body['ok']);
            self::assertStringStartsWith('{"ok":true,"data":{', $response->body());
        } else {
            self::assertSame(['ok', 'error'], array_keys($body));
            self::assertFalse($body['ok']);
            self::assertSame(['code', 'message_for_user'], array_keys($body['error']));
            self::assertIsString($body['error']['message_for_user']);
            self::assertNotSame('', $body['error']['message_for_user']);
        }
    }

    /**
     * @return iterable<string, array{string, string, array<string, string>}>
     */
    public static function everyKindOfResponse(): iterable
    {
        yield 'not found' => ['GET', '/missing', []];
        yield 'root' => ['GET', '/', []];
        yield 'admin refused' => ['GET', TestRoutes::ADMIN, []];
        yield 'admin accepted' => ['GET', TestRoutes::ADMIN, ['Authorization' => 'Bearer admin-token']];
        yield 'rate-limited route' => ['GET', TestRoutes::LIMITED, []];
        yield 'metrics route' => ['GET', TestRoutes::RECORD, []];
        yield 'internal error' => ['GET', TestRoutes::BOOM, []];
        yield 'cors request' => ['POST', TestRoutes::CORS, ['Origin' => 'https://shop.example']];
        yield 'cors preflight allowed' => ['OPTIONS', TestRoutes::CORS, ['Origin' => 'https://shop.example', 'Access-Control-Request-Method' => 'POST']];
        yield 'cors preflight refused' => ['OPTIONS', TestRoutes::CORS, ['Origin' => 'https://evil.example', 'Access-Control-Request-Method' => 'POST']];
        yield 'options without cors' => ['OPTIONS', TestRoutes::ADMIN, []];
        yield 'wrong method' => ['DELETE', TestRoutes::ADMIN, []];
    }

    /**
     * @param array<string, mixed> $config
     * @return array{Kernel, PDO, ArrayObject<int, string>}
     */
    private function kernelWithTestRoutes(array $config = []): array
    {
        $pdo = TestEnvironment::connect(TestEnvironment::freshDatabasePath());
        $app = TestEnvironment::app(new FakeClock(1_790_000_000), $config, $pdo);
        $log = new ArrayObject();
        $kernel = new Kernel(TestRoutes::router($app), $app->metrics, Closure::fromCallable(static function (string $line) use ($log): void {
            $log[] = $line;
        }));

        return [$kernel, $pdo, $log];
    }
}
