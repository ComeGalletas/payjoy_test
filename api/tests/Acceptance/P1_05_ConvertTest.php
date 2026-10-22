<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Acceptance;

use ArrayObject;
use Closure;
use FxInstallments\App;
use FxInstallments\Config\ConfigRepository;
use FxInstallments\Db\Connection;
use FxInstallments\Http\JsonResponse;
use FxInstallments\Http\Request;
use FxInstallments\Metrics\ErrorCodes;
use FxInstallments\Tests\Support\FakeClock;
use FxInstallments\Tests\Support\MockUpstream;
use FxInstallments\Tests\Support\TestEnvironment;
use GuzzleHttp\Exception\ConnectTimeoutException;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * P1-05 acceptance (docs/PLAN.md §10), through App::kernel() and the production route table,
 * with a mocked upstream (ADR 0018):
 * - `amount=200&cy=BRL&installments=3` at mocked rate 5.2291 → display `1,045.82` / `348.60` /
 *   `348.62`, `even: false`;
 * - `amount=1&cy=USD&installments=60` passes and `amount=0.50&cy=USD&installments=60` returns
 *   VALIDATION_INSTALLMENTS;
 * - JPY shows no decimals; KWD shows three;
 * - `amount=1e3` → VALIDATION_AMOUNT;
 * - an injected rate that makes the minor-unit total reach 2^53 → VALIDATION_AMOUNT;
 * - while an `unsupported-code` backoff is open, without a usable stale table, a valid currency
 *   answers 503 (owner decision, ADR 0012 amendment item 9); a `not_in_table` lookup still
 *   answers 400 UPSTREAM_UNSUPPORTED_CODE.
 */
final class P1_05_ConvertTest extends TestCase
{
    private const CLIENT = '203.0.113.9';

    private FakeClock $clock;

    private string $path;

    private PDO $pdo;

    /** @var ArrayObject<int, string> */
    private ArrayObject $log;

    protected function setUp(): void
    {
        $this->clock = new FakeClock(MockUpstream::LAST_UPDATE + 3600);
        $this->path = TestEnvironment::freshDatabasePath();
        $this->pdo = TestEnvironment::connect($this->path);
        $this->log = new ArrayObject();
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        self::assertTrue(TestEnvironment::removeDatabase($this->path), 'the test database was released and removed');
    }

    public function testTwoHundredUsdInBrlInThreeInstallments(): void
    {
        $upstream = new MockUpstream([MockUpstream::latest()]);
        $response = $this->get($this->app($upstream), ['amount' => '200', 'cy' => 'BRL', 'installments' => '3']);

        self::assertSame(200, $response->status());
        $data = self::data($response);
        self::assertSame([
            'amount_usd' => '200.00',
            'rate' => '5.2291',
            'converted' => '1,045.82',
            'installment' => '348.60',
            'last_installment' => '348.62',
            'regular_count' => 2,
            'even' => false,
            'rate_as_of' => '2026-10-21 00:00 UTC',
        ], $data['display']);

        // The shape of docs/PLAN.md §1; raw numbers compared with a tolerance (§1).
        self::assertSame(['amount_usd', 'currency', 'rate', 'converted', 'installments', 'installment_amount', 'rate_as_of', 'stale', 'display'], array_keys($data));
        self::assertEqualsWithDelta(200, $data['amount_usd'], 1e-9);
        self::assertSame('BRL', $data['currency']);
        self::assertEqualsWithDelta(5.2291, $data['rate'], 1e-12);
        self::assertEqualsWithDelta(1045.82, $data['converted'], 1e-9);
        self::assertSame(3, $data['installments']);
        self::assertEqualsWithDelta(1045.82 / 3, $data['installment_amount'], 1e-9);
        self::assertSame('2026-10-21T00:00:01Z', $data['rate_as_of']);
        self::assertFalse($data['stale']);
        self::assertSame(1, $upstream->calls());

        // One api.request event with the validated fields, and no IP anywhere in events.
        self::assertSame([[
            'source' => 'api',
            'currency' => 'BRL',
            'amount_usd_cents' => 20_000,
            'installments' => 3,
            'status' => 'ok',
            'error_code' => null,
            // The stored rate used and its upstream time, so a report can recompute it (P1-08).
            'meta' => ['endpoint' => 'convert', 'stale' => false, 'rate_scaled' => 52_291_000_000, 'rate_as_of' => MockUpstream::LAST_UPDATE, 'http_status' => 200],
        ]], $this->apiRequestEvents());
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM events WHERE meta LIKE '%203.0.113%'")->fetchColumn());
    }

    public function testOneDollarInSixtyPassesAndFiftyCentsIsRejected(): void
    {
        $app = $this->app(new MockUpstream([MockUpstream::latest()]));

        $one = $this->get($app, ['amount' => '1', 'cy' => 'USD', 'installments' => '60']);
        self::assertSame(200, $one->status());
        self::assertSame(
            ['amount_usd' => '1.00', 'rate' => '1', 'converted' => '1.00', 'installment' => '0.01', 'last_installment' => '0.41', 'regular_count' => 59, 'even' => false],
            array_slice(self::data($one)['display'], 0, 7),
        );

        $half = $this->get($app, ['amount' => '0.50', 'cy' => 'USD', 'installments' => '60']);
        self::assertSame(400, $half->status());
        self::assertSame(ErrorCodes::VALIDATION_INSTALLMENTS, $half->errorCode());
        self::assertSame(ErrorCodes::messageForUser(ErrorCodes::VALIDATION_INSTALLMENTS), self::error($half)['message_for_user']);
        self::assertSame('below_minor_unit', $this->apiRequestEvents()[1]['meta']['reason']);
    }

    public function testJpyShowsNoDecimals(): void
    {
        $response = $this->get($this->app(new MockUpstream([MockUpstream::latest()])), ['amount' => '200', 'cy' => 'JPY', 'installments' => '4']);

        $display = self::data($response)['display'];
        self::assertSame(['30,294', '7,573', '7,575', 3, false], [$display['converted'], $display['installment'], $display['last_installment'], $display['regular_count'], $display['even']]);
        foreach (['converted', 'installment', 'last_installment'] as $field) {
            self::assertStringNotContainsString('.', $display[$field]);
        }
    }

    public function testKwdShowsThreeDecimals(): void
    {
        $response = $this->get($this->app(new MockUpstream([MockUpstream::latest()])), ['amount' => '200', 'cy' => 'KWD', 'installments' => '3']);

        $display = self::data($response)['display'];
        self::assertSame(['61.930', '20.643', '20.644', '0.309652'], [$display['converted'], $display['installment'], $display['last_installment'], $display['rate']]);
    }

    public function testAnExponentAmountIsRejectedWithoutAnUpstreamCall(): void
    {
        $upstream = new MockUpstream([MockUpstream::latest()]);
        $app = $this->app($upstream);

        foreach (['1e3', 'NaN', 'INF', '-5', '0', '200.505', '1,000', ' 200', ''] as $amount) {
            $response = $this->get($app, ['amount' => $amount, 'cy' => 'BRL']);
            self::assertSame(400, $response->status(), $amount);
            self::assertSame(ErrorCodes::VALIDATION_AMOUNT, $response->errorCode(), $amount);
        }
        self::assertSame(0, $upstream->calls());
    }

    /**
     * 1,000,000 USD in a 3-decimal currency: the exact total is rate × 10^9 minor units, so a
     * rate of 9,007,199.254740992 reaches 2^53 exactly (rejected, in ConvertService before
     * display()) and 9,007,199.254740991 is 2^53 − 1 (accepted).
     */
    public function testAnInjectedRateThatReachesTwoToThe53rdMinorUnitsIsRejected(): void
    {
        $app = $this->app(new MockUpstream([MockUpstream::latest(['USD' => 1, 'KWD' => '9007199.254740992', 'BHD' => '9007199.254740991'])]));

        $over = $this->get($app, ['amount' => '1000000', 'cy' => 'KWD']);
        self::assertSame(400, $over->status());
        self::assertSame(ErrorCodes::VALIDATION_AMOUNT, $over->errorCode());
        self::assertSame('too_large', $this->apiRequestEvents()[0]['meta']['reason']);

        $under = $this->get($app, ['amount' => '1000000', 'cy' => 'BHD', 'installments' => '7']);
        self::assertSame(200, $under->status());
        $display = self::data($under)['display'];
        self::assertSame('9,007,199,254,740.991', $display['converted']);
        self::assertSame('1,000,000.00', $display['amount_usd']);
        $base = (int) str_replace([',', '.'], '', $display['installment']);
        $last = (int) str_replace([',', '.'], '', $display['last_installment']);
        self::assertSame(2 ** 53 - 1, $base * 6 + $last);
    }

    /**
     * The display total is exact for the stored rate (owner decision 2026-10-21), even where
     * the float product would round the other way; the raw number stays the nearest float.
     */
    public function testTheDisplayIsExactForLargeTotals(): void
    {
        $app = $this->app(new MockUpstream([MockUpstream::latest()]));

        $data = self::data($this->get($app, ['amount' => '999999.24', 'cy' => 'IRR', 'installments' => '2']));

        // 999,999.24 × 1,731,936.861971 = 1,731,935,545,698.98490204
        self::assertSame('1,731,935,545,698.98', $data['display']['converted']);
        self::assertSame('865,967,772,849.49', $data['display']['installment']);
        self::assertSame(['865,967,772,849.49', true], [$data['display']['last_installment'], $data['display']['even']]);
        self::assertEqualsWithDelta(1_731_935_545_698.9849, $data['converted'], 0.001);
        self::assertSame('999,999.24', $data['display']['amount_usd']);
    }

    /**
     * When the breaker row can't be stored, no backoff exists, so an `unsupported-code`
     * refresh answers the plain 400 (ADR 0012 item 9, lead's reading), not 503; and every
     * lookup calls upstream again (ADR 0012 known limit).
     */
    public function testWithoutAStoredBackoffUnsupportedCodeStays400(): void
    {
        $this->pdo->exec("CREATE TRIGGER no_breaker_rows BEFORE INSERT ON app_state BEGIN SELECT RAISE(ABORT, 'no'); END");
        $upstream = new MockUpstream(array_map(static fn (): mixed => MockUpstream::error('unsupported-code', 404), range(1, 4)));
        $app = $this->app($upstream);

        $statuses = [];
        for ($i = 0; $i < 4; $i++) {
            $statuses[] = $this->get($app, ['amount' => '200', 'cy' => 'BRL'])->status();
        }

        self::assertSame([400, 400, 400, 400], $statuses);
        self::assertSame(4, $upstream->calls());
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM app_state WHERE key = 'upstream_breaker'")->fetchColumn());
    }

    /**
     * The owner decision (ADR 0012 amendment item 9): `unsupported-code` from `/latest/USD`
     * opens a backoff; until it ends, a lookup without a usable stale table answers 503 with
     * the generic upstream text, for any valid currency, from the refresh that opened it on.
     * 503s never count toward automatic blocking.
     */
    public function testAnUnsupportedCodeBackoffWithoutAStaleTableAnswers503(): void
    {
        $upstream = new MockUpstream([MockUpstream::error('unsupported-code', 404)]);
        $app = $this->app($upstream, ['block.error_responses' => 2]);

        $responses = [
            $this->get($app, ['amount' => '200', 'cy' => 'BRL']),
            $this->get($app, ['amount' => '200', 'cy' => 'EUR', 'installments' => '3']),
            $this->get($app, ['amount' => '50', 'cy' => 'USD']),
        ];

        foreach ($responses as $response) {
            self::assertSame(503, $response->status());
            self::assertSame(ErrorCodes::UPSTREAM_UNSUPPORTED_CODE, $response->errorCode());
            self::assertSame('Exchange rates are temporarily unavailable. Please try again later.', self::error($response)['message_for_user']);
        }
        self::assertSame(1, $upstream->calls(), 'the backoff stops further calls');
        self::assertSame(
            [['unsupported-code', 503], ['blocked_backoff', 503], ['blocked_backoff', 503]],
            array_map(static fn (array $event): array => [$event['meta']['reason'], $event['meta']['http_status']], $this->apiRequestEvents()),
        );
        self::assertSame(
            [503, 503, 503],
            array_map(
                static fn (string $meta): int => json_decode($meta, true, 8, JSON_THROW_ON_ERROR)['http_status'],
                $this->pdo->query("SELECT meta FROM events WHERE event_type = 'error' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN),
            ),
        );

        // Three error responses over block.error_responses = 2, yet no block: 5xx never count.
        self::assertSame(400, $this->get($app, ['amount' => 'x', 'cy' => 'BRL'])->status());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM ip_blocks')->fetchColumn());
    }

    public function testAnUnsupportedCodeBackoffWithAStaleTableServesIt(): void
    {
        $upstream = new MockUpstream([MockUpstream::latest(), MockUpstream::error('unsupported-code', 404)]);
        $app = $this->app($upstream);
        self::assertSame(200, $this->get($app, ['amount' => '200', 'cy' => 'BRL'])->status());

        $this->clock->now = MockUpstream::NEXT_UPDATE;
        $stale = $this->get($app, ['amount' => '200', 'cy' => 'BRL', 'installments' => '3']);
        $this->clock->advance(30);
        $blocked = $this->get($app, ['amount' => '200', 'cy' => 'BRL', 'installments' => '3']);

        foreach ([$stale, $blocked] as $response) {
            self::assertSame(200, $response->status());
            self::assertTrue(self::data($response)['stale']);
            self::assertSame('2026-10-21T00:00:01Z', self::data($response)['rate_as_of']);
            self::assertSame('348.62', self::data($response)['display']['last_installment']);
        }
        self::assertSame(2, $upstream->calls());
    }

    public function testACurrencyMissingFromAUsableTableStays400(): void
    {
        // Cold start: BRL passes the fallback allow-list, but the fresh table doesn't have it.
        $upstream = new MockUpstream([MockUpstream::latest(['USD' => 1, 'EUR' => 0.8594])]);
        $app = $this->app($upstream);

        $response = $this->get($app, ['amount' => '200', 'cy' => 'BRL']);

        self::assertSame(400, $response->status());
        self::assertSame(ErrorCodes::UPSTREAM_UNSUPPORTED_CODE, $response->errorCode());
        self::assertSame('That currency is not supported right now.', self::error($response)['message_for_user']);
        self::assertSame('not_in_table', $this->apiRequestEvents()[0]['meta']['reason']);
        // EUR from the same table still converts.
        self::assertSame(200, $this->get($app, ['amount' => '200', 'cy' => 'EUR'])->status());
        self::assertSame(1, $upstream->calls());
    }

    public function testAFailedRefreshWithACachedTableServesStale(): void
    {
        $upstream = new MockUpstream([
            MockUpstream::latest(),
            new ConnectTimeoutException('timed out', MockUpstream::request(MockUpstream::FAKE_KEY)),
        ]);
        $app = $this->app($upstream);
        $fresh = $this->get($app, ['amount' => '200', 'cy' => 'BRL', 'installments' => '3']);

        $this->clock->now = MockUpstream::NEXT_UPDATE + 60;
        $stale = $this->get($app, ['amount' => '200', 'cy' => 'BRL', 'installments' => '3']);

        self::assertFalse(self::data($fresh)['stale']);
        self::assertTrue(self::data($stale)['stale']);
        self::assertSame(self::data($fresh)['display'], self::data($stale)['display'], 'a stale answer differs only in `stale`');
        self::assertSame([false, true], array_map(static fn (array $event): bool => $event['meta']['stale'], $this->apiRequestEvents()));
    }

    public function testConvertIsRateLimitedByTheGlobalLimit(): void
    {
        $upstream = new MockUpstream([MockUpstream::latest()]);
        $app = $this->app($upstream, ['ratelimit.per_minute' => 3]);

        $statuses = [];
        for ($i = 0; $i < 4; $i++) {
            $statuses[] = $this->get($app, ['amount' => '10', 'cy' => 'EUR'])->status();
        }

        self::assertSame([200, 200, 200, 429], $statuses);
        self::assertCount(3, $this->apiRequestEvents(), 'a rate-limited request never reaches the handler');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function app(MockUpstream $upstream, array $config = []): App
    {
        $log = $this->log;

        return new App(
            new ConfigRepository($config),
            Connection::wrap($this->pdo),
            Closure::fromCallable($this->clock),
            static function (string $line) use ($log): void {
                $log[] = $line;
            },
            $upstream->client(),
        );
    }

    /**
     * @param array<string, mixed> $query
     */
    private function get(App $app, array $query): JsonResponse
    {
        return $app->kernel()->handle(new Request('GET', '/convert', $query, remoteAddr: self::CLIENT));
    }

    /**
     * @return array<string, mixed>
     */
    private static function data(JsonResponse $response): array
    {
        $body = json_decode($response->body(), true, 8, JSON_THROW_ON_ERROR);
        self::assertTrue($body['ok'], $response->body());

        return $body['data'];
    }

    /**
     * @return array{code: string, message_for_user: string}
     */
    private static function error(JsonResponse $response): array
    {
        $body = json_decode($response->body(), true, 8, JSON_THROW_ON_ERROR);
        self::assertFalse($body['ok'], $response->body());
        self::assertSame(['code', 'message_for_user'], array_keys($body['error']));

        return $body['error'];
    }

    /**
     * @return list<array<string, mixed>> api.request events in order, meta decoded
     */
    private function apiRequestEvents(): array
    {
        $rows = $this->pdo->query("SELECT source, currency, amount_usd_cents, installments, status, error_code, meta FROM events WHERE event_type = 'api.request' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static function (array $row): array {
            foreach (['amount_usd_cents', 'installments'] as $column) {
                $row[$column] = $row[$column] === null ? null : (int) $row[$column];
            }
            $row['meta'] = json_decode((string) $row['meta'], true, 8, JSON_THROW_ON_ERROR);

            return $row;
        }, $rows);
    }
}
