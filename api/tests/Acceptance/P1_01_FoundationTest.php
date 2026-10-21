<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Acceptance;

use ArrayObject;
use FxInstallments\Http\JsonResponse;
use FxInstallments\Http\Request;
use FxInstallments\Tests\Support\FakeClock;
use FxInstallments\Tests\Support\OverridesEnvironment;
use FxInstallments\Tests\Support\TestEnvironment;
use FxInstallments\Tests\Support\TestRoutes;
use PHPUnit\Framework\TestCase;

/**
 * P1-01 acceptance (docs/PLAN.md §10):
 * - unknown route → 404 envelope with NOT_FOUND;
 * - admin route with no or wrong token → 401, and with ADMIN_TOKEN empty → 401 even for an
 *   empty header;
 * - 31st request in a minute from one IP → 429 RATE_LIMITED;
 * - a metrics write against a read-only DB doesn't fail the request.
 *
 * Admin and rate-limited routes come from the test-only route table (TestRoutes), wired with
 * the production middleware from App.
 */
final class P1_01_FoundationTest extends TestCase
{
    use OverridesEnvironment;

    public function testUnknownRouteReturnsNotFoundEnvelope(): void
    {
        $databasePath = TestEnvironment::freshDatabasePath();
        $app = TestEnvironment::app(pdo: TestEnvironment::connect($databasePath));

        // The production route table, exactly as public/index.php uses it.
        $response = $app->kernel()->handle(new Request('GET', '/no/such/route'));

        self::assertSame(404, $response->status());
        self::assertSame('application/json', $response->header('Content-Type'));
        self::assertSame(
            ['ok' => false, 'error' => ['code' => 'NOT_FOUND', 'message_for_user' => 'Not found.']],
            json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR),
        );

        $events = TestEnvironment::connect($databasePath)
            ->query("SELECT event_type, status, error_code FROM events")
            ->fetchAll();
        self::assertSame([['event_type' => 'error', 'status' => 'error', 'error_code' => 'NOT_FOUND']], $events);
    }

    public function testAdminRouteWithoutTokenReturns401(): void
    {
        $this->setEnvironment('ADMIN_TOKEN', 'the-real-admin-token');

        $response = $this->adminRequest([]);

        $this->assertUnauthorized($response);
    }

    public function testAdminRouteWithWrongTokenReturns401(): void
    {
        $this->setEnvironment('ADMIN_TOKEN', 'the-real-admin-token');

        $this->assertUnauthorized($this->adminRequest(['Authorization' => 'Bearer the-wrong-token']));
        $this->assertUnauthorized($this->adminRequest(['Authorization' => 'Bearer the-real-admin-toke']));
        $this->assertUnauthorized($this->adminRequest(['Authorization' => 'Basic the-real-admin-token']));
        $this->assertUnauthorized($this->adminRequest([], ['token' => 'the-real-admin-token']));

        // The right token in the header is accepted, so the 401s above are about the token.
        $ok = $this->adminRequest(['Authorization' => 'Bearer the-real-admin-token']);
        self::assertSame(200, $ok->status());
        self::assertSame(['ok' => true, 'data' => ['admin' => true]], self::decode($ok));
    }

    public function testEmptyAdminTokenRefusesEvenAnEmptyBearerHeader(): void
    {
        foreach (['' , null] as $configured) {
            $this->setEnvironment('ADMIN_TOKEN', $configured);

            foreach (['Bearer ', 'Bearer', 'Bearer  ', ''] as $header) {
                $this->assertUnauthorized($this->adminRequest(['Authorization' => $header]));
            }
            $this->assertUnauthorized($this->adminRequest([]));
        }
    }

    public function testThe31stRequestInAMinuteFromOneIpIsRateLimited(): void
    {
        $clock = new FakeClock(1_790_000_040); // the first second of a UTC minute
        $app = TestEnvironment::app($clock);
        $kernel = $app->kernel(TestRoutes::router($app));
        $request = new Request('GET', TestRoutes::LIMITED, remoteAddr: '203.0.113.7');

        for ($i = 1; $i <= 30; $i++) {
            $response = $kernel->handle($request);
            self::assertSame(200, $response->status(), "request {$i} should pass");
            $clock->advance(1);
        }

        $limited = $kernel->handle($request);
        self::assertSame(429, $limited->status());
        self::assertSame('RATE_LIMITED', self::decode($limited)['error']['code']);
        self::assertFalse(self::decode($limited)['ok']);

        // Another IP in the same minute is not affected.
        self::assertSame(200, $kernel->handle(new Request('GET', TestRoutes::LIMITED, remoteAddr: '203.0.113.8'))->status());
    }

    public function testMetricsWriteAgainstAReadOnlyDatabaseDoesNotFailTheRequest(): void
    {
        $databasePath = TestEnvironment::freshDatabasePath();
        $log = new ArrayObject();
        $app = TestEnvironment::app(pdo: TestEnvironment::connectReadOnly($databasePath), log: $log);
        $kernel = $app->kernel(TestRoutes::router($app));

        // A handler that writes an event still answers normally...
        $response = $kernel->handle(new Request('GET', TestRoutes::RECORD));
        self::assertSame(200, $response->status());
        self::assertSame(['ok' => true, 'data' => ['recorded' => false]], self::decode($response));

        // ...and so does an error response, whose error event can't be written either.
        $notFound = $kernel->handle(new Request('GET', '/missing'));
        self::assertSame(404, $notFound->status());
        self::assertSame('NOT_FOUND', self::decode($notFound)['error']['code']);

        // The writes really failed: nothing reached the file, and each failure left a short note.
        $count = TestEnvironment::connect($databasePath)->query('SELECT COUNT(*) FROM events')->fetchColumn();
        self::assertSame(0, (int) $count);
        self::assertSame([
            'fx-installments metrics: event api.request not recorded (PDOException)',
            'fx-installments metrics: event error not recorded (PDOException)',
        ], $log->getArrayCopy());
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $query
     */
    private function adminRequest(array $headers, array $query = []): JsonResponse
    {
        $app = TestEnvironment::app();

        return $app->kernel(TestRoutes::router($app))->handle(new Request('GET', TestRoutes::ADMIN, $query, $headers));
    }

    private function assertUnauthorized(JsonResponse $response): void
    {
        self::assertSame(401, $response->status());
        self::assertSame(
            ['ok' => false, 'error' => ['code' => 'ADMIN_UNAUTHORIZED', 'message_for_user' => 'Admin authorization is required.']],
            self::decode($response),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(JsonResponse $response): array
    {
        $decoded = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
