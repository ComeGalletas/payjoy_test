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
use FxInstallments\Http\Router;
use FxInstallments\Metrics\ErrorCodes;
use FxInstallments\Rates\ExchangeRateClient;
use FxInstallments\Rates\UpstreamBreaker;
use FxInstallments\Rates\UpstreamError;
use FxInstallments\Tests\Support\FakeClock;
use FxInstallments\Tests\Support\MockUpstream;
use FxInstallments\Tests\Support\TestEnvironment;
use FxInstallments\Validation\CurrencyValidator;
use FxInstallments\Validation\ValidationFailed;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Response;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * P1-04 acceptance (docs/PLAN.md §10), with a mocked upstream handler (ADR 0018):
 * - two conversions in one refresh window make one upstream call;
 * - a refresh failure with a cached table serves `stale: true`;
 * - `quota-reached` blocks calls until next month;
 * - every error path's output contains no key (INV-03): responses, events, logs and
 *   exception text, with a distinctive fake key.
 *
 * `/convert` arrives in P1-05, so a test-only route stands in for it: validate `cy` against
 * the real allow-list, look the rate up in the rate cache, multiply locally.
 */
final class P1_04_RatesTest extends TestCase
{
    private const ROUTE = '/test/rate';

    /** The same lookup, but an UpstreamError escapes to the Kernel (500 INTERNAL, logged). */
    private const RAW_ROUTE = '/test/rate-raw';

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

    public function testTwoConversionsInOneRefreshWindowMakeOneUpstreamCall(): void
    {
        $upstream = new MockUpstream([MockUpstream::latest()]);
        $app = $this->app($upstream);

        $brl = $this->get($app, ['cy' => 'BRL', 'amount' => '200']);
        $this->clock->advance(6 * 3600);
        $cop = $this->get($app, ['cy' => 'cop', 'amount' => '200']);

        self::assertSame(1, $upstream->calls());
        self::assertSame(
            ['https://v6.exchangerate-api.com/v6/' . MockUpstream::CANARY_KEY . '/latest/USD'],
            $upstream->urls(),
            'the one call is the fixed /latest/USD URL',
        );
        self::assertSame(200, $brl->status());
        self::assertSame(['currency' => 'BRL', 'rate_scaled' => 52_291_000_000, 'converted' => 200 * 5.2291,
            'rate_as_of' => MockUpstream::LAST_UPDATE, 'stale' => false], self::data($brl));
        self::assertSame('COP', self::data($cop)['currency']);
        self::assertFalse(self::data($cop)['stale']);
        self::assertSame([['miss', 'BRL'], ['hit', 'COP']], $this->upstreamEvents());
    }

    public function testARefreshFailureWithACachedTableServesStale(): void
    {
        $upstream = new MockUpstream([
            MockUpstream::latest(),
            new ConnectTimeoutException('timed out', MockUpstream::request(MockUpstream::CANARY_KEY)),
        ]);
        $app = $this->app($upstream);
        $this->get($app, ['cy' => 'BRL']);

        // The next update is due; upstream times out.
        $this->clock->now = MockUpstream::NEXT_UPDATE;
        $response = $this->get($app, ['cy' => 'BRL']);

        self::assertSame(200, $response->status());
        self::assertTrue(self::data($response)['stale']);
        self::assertSame(MockUpstream::LAST_UPDATE, self::data($response)['rate_as_of']);
        self::assertSame(52_291_000_000, self::data($response)['rate_scaled']);
        self::assertSame(2, $upstream->calls());
        self::assertSame([['miss', 'BRL'], ['stale', 'BRL']], $this->upstreamEvents());
    }

    public function testQuotaReachedBlocksCallsUntilNextMonth(): void
    {
        $this->clock->now = gmmktime(9, 0, 0, 12, 20, 2026);
        $upstream = new MockUpstream([
            MockUpstream::error('quota-reached', 429),
            MockUpstream::latest(['USD' => 1, 'BRL' => 5.3], gmmktime(0, 0, 1, 1, 1, 2027), gmmktime(0, 0, 1, 1, 2, 2027)),
        ]);
        $app = $this->app($upstream);

        $first = $this->get($app, ['cy' => 'BRL']);
        self::assertSame(503, $first->status());
        self::assertSame(ErrorCodes::UPSTREAM_QUOTA, $first->errorCode());

        // Every lookup for the rest of December is blocked without an upstream call.
        foreach ([gmmktime(9, 0, 1, 12, 20, 2026), gmmktime(12, 0, 0, 12, 25, 2026), gmmktime(23, 59, 59, 12, 31, 2026)] as $now) {
            $this->clock->now = $now;
            $blocked = $this->get($app, ['cy' => 'BRL']);
            self::assertSame(ErrorCodes::UPSTREAM_QUOTA, $blocked->errorCode());
        }
        self::assertSame(1, $upstream->calls());

        // 00:00 UTC on 1 January: calls resume.
        $this->clock->now = gmmktime(0, 0, 0, 1, 1, 2027);
        $resumed = $this->get($app, ['cy' => 'BRL']);
        self::assertSame(200, $resumed->status());
        self::assertSame(53_000_000_000, self::data($resumed)['rate_scaled']);
        self::assertSame(2, $upstream->calls());
        self::assertSame(
            [['miss', 'BRL'], ['blocked', 'BRL'], ['blocked', 'BRL'], ['blocked', 'BRL'], ['miss', 'BRL']],
            $this->upstreamEvents(),
        );
    }

    /**
     * Every upstream error path. Transport exceptions carry the URL with the key in their
     * message and request, as Guzzle's do; some bodies even echo the key back.
     *
     * @return iterable<string, array{list<mixed>, bool, string|null}>
     */
    public static function errorPaths(): iterable
    {
        $key = MockUpstream::CANARY_KEY;
        $url = ExchangeRateClient::BASE_URL . $key . '/latest/USD';
        $request = MockUpstream::request($key);

        foreach (['unsupported-code', 'quota-reached', 'invalid-key', 'inactive-account', 'malformed-request', 'no-such-type'] as $type) {
            yield "error-type {$type}" => [[MockUpstream::error($type, 403)], false, null];
            yield "error-type {$type}, then blocked or retried" => [[MockUpstream::error($type), MockUpstream::error($type)], false, null];
        }
        yield 'error body echoing the key' => [[MockUpstream::json(403, json_encode(['result' => 'error', 'error-type' => 'invalid-key', 'key' => $key, 'url' => $url], JSON_THROW_ON_ERROR))], false, null];
        yield '500 page echoing the URL' => [[MockUpstream::json(500, "<h1>Error</h1><p>{$url}</p>")], false, null];
        yield '302 to a page with the key' => [[new Response(302, ['Location' => $url . '?again'])], false, null];
        yield 'connect timeout' => [[new ConnectTimeoutException("cURL error 28: Connection timed out after 4001 ms for {$url}", $request)], false, null];
        yield 'response timeout' => [[new ResponseTimeoutException("cURL error 28: Operation timed out for {$url}", $request, new Response(200))], false, null];
        yield 'connect refused' => [[new ConnectException("cURL error 7: Failed to connect for {$url}", $request)], false, null];
        yield 'DNS failure' => [[new NetworkException("cURL error 6: Could not resolve host for {$url}", $request)], false, null];
        yield 'TLS failure' => [[new RequestException("cURL error 60: SSL certificate problem for {$url}", $request)], false, null];
        yield 'transfer failure' => [[new TransferException("failed {$url}", $request)], false, null];
        yield 'other throwable' => [[new RuntimeException("broken {$url}", 0, new RuntimeException($key))], false, null];
        yield 'not JSON, echoing the key' => [[MockUpstream::json(200, "oops {$key}")], false, null];
        yield 'success with a bad shape' => [[MockUpstream::json(200, json_encode(['result' => 'success', 'key' => $key], JSON_THROW_ON_ERROR))], false, null];
        yield 'oversized body' => [[new Response(200, ['Content-Length' => '999999999'], $key)], false, null];
        yield 'a currency code that is the key' => [[MockUpstream::json(200, str_replace('"conversion_rates":{', '"conversion_rates":{"' . $key . '":1,', MockUpstream::latestBody()))], false, null];
        yield 'timeout with a cached table (stale)' => [[new ConnectTimeoutException("timed out {$url}", $request), MockUpstream::latest()], true, null];
        yield 'quota with a cached table (stale, then blocked)' => [[MockUpstream::error('quota-reached'), MockUpstream::latest()], true, null];
        yield 'invalid key with a cached table' => [[MockUpstream::error('invalid-key'), MockUpstream::latest()], true, null];
        yield 'a malformed key containing the canary' => [[MockUpstream::latest()], false, $key . '/../codes'];
        yield 'unsupported currency in a fresh table' => [[MockUpstream::latest(['USD' => 1])], false, null];
    }

    /**
     * @param list<mixed> $queue
     */
    #[DataProvider('errorPaths')]
    public function testNoErrorPathOutputContainsTheKey(array $queue, bool $cached, ?string $key): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $this->assertNoLeak($queue, $cached, $key ?? MockUpstream::CANARY_KEY);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }
    }

    /**
     * @param list<mixed> $queue
     */
    private function assertNoLeak(array $queue, bool $cached, string $key): void
    {
        if ($cached) {
            $this->pdo->prepare('INSERT INTO rate_cache VALUES (?, ?, ?, ?, ?)')
                ->execute(['BRL', 52_291_000_000, MockUpstream::LAST_UPDATE, MockUpstream::LAST_UPDATE + 60, MockUpstream::LAST_UPDATE]);
        }
        $upstream = new MockUpstream($queue);
        $app = $this->app($upstream, $key);
        $output = [];

        // Through the Kernel: the envelope, its headers, and 500 INTERNAL for an escaped error.
        foreach ([self::ROUTE, self::RAW_ROUTE, self::ROUTE] as $path) {
            $response = $app->kernel($this->router($app))->handle(new Request('GET', $path, ['cy' => 'BRL']));
            $output[] = $response->status() . ' ' . json_encode($response->headers(), JSON_THROW_ON_ERROR) . ' ' . $response->body();
            self::assertContains($response->status(), [200, 400, 500, 503]);
        }

        // Directly, after any backoff: the exception's message, string form with trace, and the
        // arguments of every app frame.
        $this->clock->advance(UpstreamBreaker::BACKOFF_SECONDS + 1);
        try {
            $app->rates()->rate('BRL');
        } catch (UpstreamError $error) {
            self::assertNull($error->getPrevious());
            $output[] = $error->getMessage() . "\n" . $error;
            foreach ($error->getTrace() as $frame) {
                if (str_starts_with($frame['class'] ?? '', 'FxInstallments\\') && !str_starts_with($frame['class'] ?? '', 'FxInstallments\\Tests\\')) {
                    $output[] = print_r($frame['args'] ?? [], true);
                }
            }
        }

        // Every stored event, every row of app_state and rate_cache, every log line.
        foreach (['events', 'app_state', 'rate_cache'] as $table) {
            $output[] = json_encode($this->pdo->query("SELECT * FROM {$table}")->fetchAll(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
        $output[] = implode("\n", $this->log->getArrayCopy());

        // Generic dumps of a client holding the key (on a handler with no request history, so
        // only the client's own state is dumped): var_export, (array), print_r, serialize.
        $client = new ExchangeRateClient(new \GuzzleHttp\Client(['handler' => static fn () => throw new RuntimeException('unused')]), $key);
        $output[] = var_export($client, true) . print_r((array) $client, true) . print_r($client, true);
        try {
            $output[] = serialize($client);
            self::fail('the client was serialized');
        } catch (\LogicException $e) {
            $output[] = $e->getMessage();
        }

        $all = implode("\n", $output);
        self::assertGreaterThan(0, (int) $this->pdo->query("SELECT COUNT(*) FROM events WHERE event_type = 'upstream.call'")->fetchColumn());
        self::assertStringNotContainsString($key, $all);
        self::assertStringNotContainsString(MockUpstream::CANARY_KEY, $all);
        self::assertStringNotContainsString('exchangerate-api.com', $all);
        self::assertStringNotContainsString('cURL error', $all);
    }

    private function app(MockUpstream $upstream, string $key = MockUpstream::CANARY_KEY): App
    {
        $log = $this->log;

        return new App(
            new ConfigRepository(),
            Connection::wrap($this->pdo),
            Closure::fromCallable($this->clock),
            static function (string $line) use ($log): void {
                $log[] = $line;
            },
            $upstream->client($key),
        );
    }

    private function router(App $app): Router
    {
        $lookup = static function (Request $request) use ($app): JsonResponse {
            $currency = (new CurrencyValidator($app->currencyAllowList()))->validate((string) $request->queryParam('cy'));
            $rate = $app->rates()->rate($currency);
            $amount = (float) ($request->queryParam('amount') ?? '1');

            return JsonResponse::ok([
                'currency' => $rate->currency,
                'rate_scaled' => $rate->rateScaled,
                'converted' => $amount * $rate->rate(),
                'rate_as_of' => $rate->rateAsOf,
                'stale' => $rate->stale,
            ]);
        };
        $router = new Router();
        $router->get(self::ROUTE, static function (Request $request) use ($lookup): JsonResponse {
            try {
                return $lookup($request);
            } catch (UpstreamError $error) {
                return JsonResponse::error($error->errorCode);
            } catch (ValidationFailed $error) {
                return JsonResponse::error($error->errorCode);
            }
        });
        $router->get(self::RAW_ROUTE, $lookup);

        return $router;
    }

    /**
     * @param array<string, string> $query
     */
    private function get(App $app, array $query): JsonResponse
    {
        return $app->kernel($this->router($app))->handle(new Request('GET', self::ROUTE, $query));
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
     * @return list<array{string, string}> [meta.result, currency] per upstream.call event
     */
    private function upstreamEvents(): array
    {
        $rows = $this->pdo->query("SELECT currency, meta FROM events WHERE event_type = 'upstream.call' ORDER BY id")->fetchAll();

        return array_map(static function (array $row): array {
            $meta = json_decode((string) $row['meta'], true, 8, JSON_THROW_ON_ERROR);

            return [$meta['result'], $row['currency']];
        }, $rows);
    }
}
