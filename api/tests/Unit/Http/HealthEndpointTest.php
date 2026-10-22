<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Http;

use ArrayObject;
use Closure;
use FxInstallments\App;
use FxInstallments\Config\ConfigRepository;
use FxInstallments\Db\Connection;
use FxInstallments\Db\Database;
use FxInstallments\Db\Migrations;
use FxInstallments\Db\Migrator;
use FxInstallments\Http\Request;
use FxInstallments\Http\Response;
use FxInstallments\Metrics\ErrorCodes;
use FxInstallments\Rates\QuotaCache;
use FxInstallments\Rates\RateStore;
use FxInstallments\Rates\RateTable;
use FxInstallments\Rates\UpstreamError;
use FxInstallments\Tests\Support\FakeClock;
use FxInstallments\Tests\Support\MockUpstream;
use FxInstallments\Tests\Support\OverridesEnvironment;
use FxInstallments\Tests\Support\TestEnvironment;
use FxInstallments\Tests\Support\TransactionSpyPdo;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * `GET /health` through the production route table and Kernel (P1-08).
 */
final class HealthEndpointTest extends TestCase
{
    use OverridesEnvironment;

    private const TOKEN = 'the-real-admin-token';

    private const CLIENT = '203.0.113.7';

    /** 2026-10-22 03:00:00 UTC. */
    private const NOW = 1792638000;

    private string $path;

    private PDO $pdo;

    private FakeClock $clock;

    /** @var ArrayObject<int, string> */
    private ArrayObject $log;

    protected function setUp(): void
    {
        $this->path = TestEnvironment::freshDatabasePath();
        $this->pdo = TestEnvironment::connect($this->path);
        $this->clock = new FakeClock(self::NOW);
        $this->log = new ArrayObject();
        $this->setEnvironment('ADMIN_TOKEN', self::TOKEN);
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        self::assertTrue(TestEnvironment::removeDatabase($this->path));
    }

    public function testThePublicViewIsOnlyTheStatus(): void
    {
        $response = $this->health($this->app());

        self::assertSame(200, $response->status());
        self::assertSame('{"ok":true,"data":{"status":"ok"}}', $response->body());
        self::assertSame('no-store', $response->header('Cache-Control'));
        self::assertSame([['path' => '/health', 'status' => 200]], $this->pdo->query('SELECT path, status FROM requests')->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM events')->fetchColumn(), 'the public view never asks upstream');
    }

    public function testThePublicViewNeedsNoAdminToken(): void
    {
        $this->setEnvironment('ADMIN_TOKEN', null);

        self::assertSame('{"ok":true,"data":{"status":"ok"}}', $this->health($this->app())->body());
        self::assertSame(401, $this->health($this->app(), 'Bearer ' . self::TOKEN)->status(), 'an unset token refuses every admin request');
    }

    public function testAWrongOrMalformedBearerTokenIs401NotThePublicView(): void
    {
        foreach (['Bearer wrong', 'Bearer ' . self::TOKEN . "\n", 'bearer', 'BEARER  ', "Bearer\t" . self::TOKEN, ' Bearer ' . self::TOKEN] as $header) {
            $response = $this->health($this->app(), $header);

            self::assertSame(401, $response->status(), json_encode($header, JSON_THROW_ON_ERROR));
            self::assertSame(ErrorCodes::ADMIN_UNAUTHORIZED, $response->errorCode());
        }
    }

    /**
     * Round 2: only the Bearer scheme asks for the admin view; a proxy's Basic credentials or
     * another scheme get the public one, not a 401.
     */
    public function testAnotherSchemeGetsThePublicView(): void
    {
        foreach (['Basic ' . base64_encode('user:pass'), 'Digest username="x"', 'Bearertoken', '', 'Token ' . self::TOKEN] as $header) {
            $response = $this->health($this->app(), $header);

            self::assertSame(200, $response->status(), json_encode($header, JSON_THROW_ON_ERROR));
            self::assertSame('{"ok":true,"data":{"status":"ok"}}', $response->body());
        }
    }

    /**
     * Round 2: the write probe always rolls back and never commits.
     */
    public function testTheWriteProbeRollsBackAndNeverCommits(): void
    {
        $spy = TransactionSpyPdo::open($this->path);
        $app = TestEnvironment::app($this->clock, [], $spy, $this->log, new MockUpstream());

        self::assertSame(200, $this->health($app)->status());

        self::assertSame(['begin' => 1, 'commit' => 0, 'rollBack' => 1], $spy->calls);
        unset($app, $spy);
    }

    /**
     * Round 2: while the database can't be written, the admin view never asks upstream for the
     * quota (the answer couldn't be cached); it shows the last stored answer, or none.
     */
    public function testAReadOnlyDatabaseNeverAsksUpstreamForTheQuota(): void
    {
        $this->pdo->prepare('INSERT INTO app_state (key, value, updated_at) VALUES (?, ?, ?)')->execute([
            QuotaCache::STATE_KEY,
            json_encode(['checked_at' => self::NOW - 7200, 'plan_quota' => 1500, 'requests_remaining' => 1400, 'refresh_day_of_month' => 21], JSON_THROW_ON_ERROR),
            self::NOW - 7200,
        ]);
        $upstream = new MockUpstream([self::quota(1500, 1, 21), self::quota(1500, 1, 21)]);
        $readOnly = TestEnvironment::connectReadOnly($this->path);
        $app = TestEnvironment::app($this->clock, [], $readOnly, $this->log, $upstream);

        foreach ([1, 2, 3] as $view) {
            $quota = $this->data($this->health($app, 'Bearer ' . self::TOKEN))['quota'];
            self::assertSame(['blocked', '2026-10-22T01:00:00Z', 7200, 1400, null], [$quota['source'], $quota['checked_at'], $quota['age_seconds'], $quota['requests_remaining'], $quota['error_code']], "view {$view}");
        }
        self::assertSame(0, $upstream->calls());
        unset($app, $readOnly);

        // With nothing stored: blocked, no numbers.
        $this->pdo->exec("DELETE FROM app_state WHERE key = '" . QuotaCache::STATE_KEY . "'");
        $readOnly = TestEnvironment::connectReadOnly($this->path);
        $empty = $this->data($this->health(TestEnvironment::app($this->clock, [], $readOnly, $this->log, $upstream), 'Bearer ' . self::TOKEN))['quota'];
        self::assertSame(['blocked', null, null], [$empty['source'], $empty['checked_at'], $empty['plan_quota']]);
        self::assertSame(0, $upstream->calls());
        unset($readOnly);
    }

    public function testAReadOnlyDatabaseIsUnhealthy(): void
    {
        $readOnly = TestEnvironment::connectReadOnly($this->path);
        $app = TestEnvironment::app($this->clock, [], $readOnly, $this->log, new MockUpstream());

        $public = $this->health($app);
        self::assertSame(503, $public->status());
        self::assertSame(['ok' => false, 'error' => ['code' => 'UNHEALTHY', 'message_for_user' => ErrorCodes::messageForUser(ErrorCodes::UNHEALTHY)]], json_decode($public->body(), true, flags: JSON_THROW_ON_ERROR));

        $admin = $this->data($this->health($app, 'Bearer ' . self::TOKEN));
        self::assertSame('unhealthy', $admin['status']);
        self::assertSame(['open' => true, 'schema_version' => Migrations::latestVersion(), 'schema' => 'current', 'latest_version' => Migrations::latestVersion(), 'writable' => false], $admin['database']);
        self::assertIsArray($admin['rates'], 'a readable database still shows its details');
        unset($app, $readOnly);
    }

    public function testTheWriteProbeChangesNothing(): void
    {
        $before = $this->pdo->query("SELECT value, updated_at FROM app_state WHERE key = 'schema_version'")->fetch(PDO::FETCH_ASSOC);

        self::assertSame(200, $this->health($this->app())->status());

        self::assertSame($before, $this->pdo->query("SELECT value, updated_at FROM app_state WHERE key = 'schema_version'")->fetch(PDO::FETCH_ASSOC));
    }

    public function testADatabaseBehindTheCodeIsUnhealthyAndNeverWritten(): void
    {
        $behind = TestEnvironment::tempDir('health-behind') . '/behind.db';
        $app = $this->appOn(static function () use ($behind): PDO {
            $pdo = Database::connect('sqlite:' . $behind);
            (new Migrator($pdo))->assertCurrent();

            return $pdo;
        });

        $public = $this->health($app);
        self::assertSame(503, $public->status());
        self::assertSame(ErrorCodes::UNHEALTHY, $public->errorCode());

        $admin = $this->data($this->health($app, 'Bearer ' . self::TOKEN));
        self::assertSame([
            'status' => 'unhealthy',
            'checked_at' => '2026-10-22T03:00:00Z',
            'database' => ['open' => true, 'schema' => 'behind', 'schema_version' => 0, 'latest_version' => Migrations::latestVersion(), 'writable' => null],
            'rates' => null,
            'breaker' => null,
            'quota' => null,
        ], $admin);

        // Nothing was written: no table exists in the file.
        self::assertSame(0, (int) Database::connect('sqlite:' . $behind)->query('SELECT COUNT(*) FROM sqlite_master')->fetchColumn());
        self::assertContains('fx-installments health: FxInstallments\Db\SchemaOutOfDate', $this->log->getArrayCopy());
    }

    public function testADatabaseThatCannotBeOpenedIsUnhealthy(): void
    {
        $app = $this->appOn(static function (): PDO {
            throw new RuntimeException('disk gone at C:\\secret\\path');
        });

        $public = $this->health($app);
        self::assertSame(503, $public->status());
        self::assertStringNotContainsString('secret', $public->body());

        $admin = $this->data($this->health($app, 'Bearer ' . self::TOKEN));
        self::assertSame(['open' => false, 'schema' => 'unknown', 'schema_version' => null, 'latest_version' => Migrations::latestVersion(), 'writable' => null], $admin['database']);
        self::assertStringNotContainsString('secret', implode("\n", $this->log->getArrayCopy()));
    }

    public function testACorruptSchemaVersionIsUnhealthy(): void
    {
        $this->pdo->exec("DELETE FROM app_state WHERE key = 'schema_version'");

        self::assertSame(503, $this->health($this->app())->status());
        self::assertSame('corrupt', $this->data($this->health($this->app(), 'Bearer ' . self::TOKEN))['database']['schema']);
    }

    public function testTheAdminViewShowsRatesBreakerAndQuota(): void
    {
        $fetched = self::NOW - 600;
        (new RateStore(Connection::wrap($this->pdo)))->replace(new RateTable(['BRL' => 52_291_000_000, 'USD' => 10_000_000_000], MockUpstream::LAST_UPDATE, self::NOW + 3600, $fetched));
        $upstream = new MockUpstream([self::quota(1500, 1497, 21)]);
        $app = $this->app($upstream);
        $breaker = $app->upstreamBreaker();
        $this->clock->now = self::NOW - 4000;
        $breaker->trip(new UpstreamError(ErrorCodes::UPSTREAM_TIMEOUT, 'timeout'));
        $this->clock->now = self::NOW - 3000;
        $breaker->trip(new UpstreamError(ErrorCodes::UPSTREAM_TIMEOUT, 'timeout'));
        $this->clock->now = self::NOW;

        $data = $this->data($this->health($app, 'Bearer ' . self::TOKEN));

        self::assertSame('ok', $data['status']);
        self::assertSame(['open' => true, 'schema_version' => Migrations::latestVersion(), 'schema' => 'current', 'latest_version' => Migrations::latestVersion(), 'writable' => true], $data['database']);
        self::assertSame([
            'cached' => true,
            'currencies' => 2,
            'rate_as_of' => '2026-10-21T00:00:01Z',
            'rate_age_seconds' => self::NOW - MockUpstream::LAST_UPDATE,
            'fetched_at' => '2026-10-22T02:50:00Z',
            'cache_age_seconds' => 600,
            'next_update' => '2026-10-22T04:00:00Z',
            'fresh' => true,
            'usable_when_stale' => true,
        ], $data['rates']);
        // Two consecutive failures: the second backoff, 5 minutes, has ended.
        self::assertSame(['state' => 'expired', 'kind' => 'backoff', 'error_code' => 'UPSTREAM_TIMEOUT', 'since' => '2026-10-22T02:10:00Z', 'until' => '2026-10-22T02:15:00Z', 'failures' => 2], $data['breaker']);
        self::assertSame([
            'source' => 'upstream',
            'checked_at' => '2026-10-22T03:00:00Z',
            'age_seconds' => 0,
            'plan_quota' => 1500,
            'requests_remaining' => 1497,
            'refresh_day_of_month' => 21,
            'error_code' => null,
            'resets' => ['upstream_refresh_date' => '2026-11-21', 'breaker_quota_block_until' => null],
        ], $data['quota']);
        self::assertSame(['https://v6.exchangerate-api.com/v6/' . MockUpstream::FAKE_KEY . '/quota'], $upstream->urls());

        // Cached: a second view doesn't ask upstream again.
        $this->clock->advance(60);
        self::assertSame('cache', $this->data($this->health($app, 'Bearer ' . self::TOKEN))['quota']['source']);
        self::assertSame(1, $upstream->calls());

        $body = $this->health($app, 'Bearer ' . self::TOKEN)->body();
        self::assertStringNotContainsString(MockUpstream::FAKE_KEY, $body);
        self::assertStringNotContainsString(self::CLIENT, $body);
    }

    public function testAQuotaBlockShowsBothResetDates(): void
    {
        $upstream = new MockUpstream([self::quota(1500, 0, 21)]);
        $app = $this->app($upstream);
        $app->upstreamBreaker()->trip(new UpstreamError(ErrorCodes::UPSTREAM_QUOTA, 'quota-reached'));

        $data = $this->data($this->health($app, 'Bearer ' . self::TOKEN));

        self::assertSame(['state' => 'open', 'kind' => 'quota', 'error_code' => 'UPSTREAM_QUOTA', 'since' => '2026-10-22T03:00:00Z', 'until' => '2026-11-01T00:00:00Z', 'failures' => 0], $data['breaker']);
        self::assertSame(['upstream_refresh_date' => '2026-11-21', 'breaker_quota_block_until' => '2026-11-01T00:00:00Z'], $data['quota']['resets']);
        self::assertSame('ok', $data['status'], 'an upstream block is not unhealthy');
        self::assertSame(['cached' => false, 'currencies' => 0], $data['rates']);
    }

    public function testAFailedQuotaCallIsShownWithItsCode(): void
    {
        $data = $this->data($this->health($this->app(new MockUpstream([MockUpstream::error('invalid-key')])), 'Bearer ' . self::TOKEN));

        self::assertSame(['upstream', 'UPSTREAM_AUTH', null, ['upstream_refresh_date' => null, 'breaker_quota_block_until' => null]], [$data['quota']['source'], $data['quota']['error_code'], $data['quota']['plan_quota'], $data['quota']['resets']]);
        self::assertSame(['state' => 'closed', 'kind' => null, 'error_code' => null, 'since' => null, 'until' => null, 'failures' => 0], $data['breaker'], 'a /quota failure never trips the breaker');
    }

    private function app(?MockUpstream $upstream = null): App
    {
        return TestEnvironment::app($this->clock, [], $this->pdo, $this->log, $upstream ?? new MockUpstream());
    }

    /**
     * @param Closure(): PDO $factory
     */
    private function appOn(Closure $factory): App
    {
        return new App(new ConfigRepository(), new Connection($factory), Closure::fromCallable($this->clock), function (string $line): void {
            $this->log[] = $line;
        }, (new MockUpstream())->client());
    }

    private function health(App $app, ?string $authorization = null): Response
    {
        return $app->kernel()->handle(new Request('GET', '/health', [], $authorization === null ? [] : ['Authorization' => $authorization], remoteAddr: self::CLIENT));
    }

    /**
     * @return array<string, mixed>
     */
    private function data(Response $response): array
    {
        self::assertSame(200, $response->status(), $response->body());
        $envelope = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($envelope['ok']);

        return $envelope['data'];
    }

    private static function quota(int $plan, int $remaining, int $day): \GuzzleHttp\Psr7\Response
    {
        return MockUpstream::json(200, json_encode(['result' => 'success', 'plan_quota' => $plan, 'requests_remaining' => $remaining, 'refresh_day_of_month' => $day], JSON_THROW_ON_ERROR));
    }
}
