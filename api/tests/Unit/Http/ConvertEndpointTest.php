<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Http;

use FxInstallments\App;
use FxInstallments\Http\JsonResponse;
use FxInstallments\Http\Request;
use FxInstallments\Metrics\ErrorCodes;
use FxInstallments\Tests\Support\FakeClock;
use FxInstallments\Tests\Support\MockUpstream;
use FxInstallments\Tests\Support\TestEnvironment;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `GET /convert` query parameters and its `api.request` event (ConvertEndpoint), through the
 * production route table.
 */
final class ConvertEndpointTest extends TestCase
{
    private string $path;

    private PDO $pdo;

    private FakeClock $clock;

    private MockUpstream $upstream;

    protected function setUp(): void
    {
        $this->path = TestEnvironment::freshDatabasePath();
        $this->pdo = TestEnvironment::connect($this->path);
        $this->clock = new FakeClock(MockUpstream::LAST_UPDATE + 3600);
        $this->upstream = new MockUpstream([MockUpstream::latest(MockUpstream::RATES + ['ZWD' => 361.9])]);
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        self::assertTrue(TestEnvironment::removeDatabase($this->path), 'the test database was released and removed');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function invalidQueries(): iterable
    {
        yield 'no parameters' => [[], ErrorCodes::VALIDATION_AMOUNT, 'format'];
        yield 'amount missing' => [['cy' => 'BRL'], ErrorCodes::VALIDATION_AMOUNT, 'format'];
        yield 'amount as an array' => [['amount' => ['200'], 'cy' => 'BRL'], ErrorCodes::VALIDATION_AMOUNT, 'format'];
        yield 'amount over amount.max' => [['amount' => '1000000.01', 'cy' => 'BRL'], ErrorCodes::VALIDATION_AMOUNT, 'over_max'];
        yield 'amount zero' => [['amount' => '0.00', 'cy' => 'BRL'], ErrorCodes::VALIDATION_AMOUNT, 'not_positive'];
        yield 'amount checked before currency' => [['amount' => 'x', 'cy' => '??'], ErrorCodes::VALIDATION_AMOUNT, 'format'];
        yield 'cy missing' => [['amount' => '200'], ErrorCodes::VALIDATION_CURRENCY, 'format'];
        yield 'cy as an array' => [['amount' => '200', 'cy' => ['BRL']], ErrorCodes::VALIDATION_CURRENCY, 'format'];
        yield 'cy not a code' => [['amount' => '200', 'cy' => 'reais'], ErrorCodes::VALIDATION_CURRENCY, 'format'];
        yield 'cy not on the allow-list' => [['amount' => '200', 'cy' => 'XYZ'], ErrorCodes::VALIDATION_CURRENCY, 'not_allowed'];
        yield 'currency checked before installments' => [['amount' => '200', 'cy' => 'XYZ', 'installments' => '0'], ErrorCodes::VALIDATION_CURRENCY, 'not_allowed'];
        yield 'installments empty' => [['amount' => '200', 'cy' => 'BRL', 'installments' => ''], ErrorCodes::VALIDATION_INSTALLMENTS, 'format'];
        yield 'installments as an array' => [['amount' => '200', 'cy' => 'BRL', 'installments' => ['3']], ErrorCodes::VALIDATION_INSTALLMENTS, 'format'];
        yield 'installments with a leading zero' => [['amount' => '200', 'cy' => 'BRL', 'installments' => '03'], ErrorCodes::VALIDATION_INSTALLMENTS, 'format'];
        yield 'installments with a space' => [['amount' => '200', 'cy' => 'BRL', 'installments' => ' 3'], ErrorCodes::VALIDATION_INSTALLMENTS, 'format'];
        yield 'installments 3.0' => [['amount' => '200', 'cy' => 'BRL', 'installments' => '3.0'], ErrorCodes::VALIDATION_INSTALLMENTS, 'format'];
        yield 'installments 0' => [['amount' => '200', 'cy' => 'BRL', 'installments' => '0'], ErrorCodes::VALIDATION_INSTALLMENTS, 'out_of_range'];
        yield 'installments 61' => [['amount' => '200', 'cy' => 'BRL', 'installments' => '61'], ErrorCodes::VALIDATION_INSTALLMENTS, 'out_of_range'];
    }

    /**
     * @param array<string, mixed> $query
     */
    #[DataProvider('invalidQueries')]
    public function testInvalidQueriesFailWithoutAnUpstreamCall(array $query, string $code, string $reason): void
    {
        $response = $this->get($this->app(), $query);

        self::assertSame(400, $response->status());
        self::assertSame($code, $response->errorCode());
        self::assertSame(
            ['ok' => false, 'error' => ['code' => $code, 'message_for_user' => ErrorCodes::messageForUser($code)]],
            json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR),
        );
        self::assertSame(0, $this->upstream->calls());
        $event = $this->events()[0];
        self::assertSame($code, $event['error_code']);
        self::assertSame('error', $event['status']);
        self::assertSame(['endpoint' => 'convert', 'reason' => $reason, 'http_status' => 400], $event['meta']);
    }

    public function testTheEventHoldsTheFieldsValidatedSoFar(): void
    {
        $app = $this->app();
        $this->get($app, ['amount' => '12.30', 'cy' => 'XYZ']);
        $this->get($app, ['amount' => '12.30', 'cy' => 'brl', 'installments' => '99']);

        self::assertSame(
            [[1230, null, null], [1230, 'BRL', null]],
            array_map(static fn (array $event): array => [$event['amount_usd_cents'], $event['currency'], $event['installments']], $this->events()),
        );
        // Raw input never reaches an event.
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM events WHERE meta LIKE '%XYZ%' OR currency = 'XYZ' OR meta LIKE '%brl%'")->fetchColumn());
    }

    public function testInstallmentsDefaultsToTheConfiguredValue(): void
    {
        $one = self::data($this->get($this->app(), ['amount' => '200', 'cy' => 'BRL']));
        self::assertSame(1, $one['installments']);
        self::assertTrue($one['display']['even']);
        self::assertSame(1, $one['display']['regular_count']);

        $three = self::data($this->get($this->app(['installments.default' => 3]), ['amount' => '200', 'cy' => 'BRL']));
        self::assertSame(3, $three['installments']);
        self::assertSame('348.62', $three['display']['last_installment']);
    }

    public function testTheCurrencyIsTrimmedAndUppercasedAndExtraParametersAreIgnored(): void
    {
        $data = self::data($this->get($this->app(), ['amount' => '200', 'cy' => " brl\t", 'installments' => '3', 'utm_source' => 'x', 'rate' => '99']));

        self::assertSame('BRL', $data['currency']);
        self::assertSame('5.2291', $data['display']['rate']);
    }

    public function testAnUnlistedCurrencyUsesTwoDecimalsAndIsFlagged(): void
    {
        $app = $this->app();
        // On a cold start the fallback list is the allow-list, and it doesn't have ZWD; once a
        // refresh stored the table, its codes are the allow-list.
        self::assertSame(ErrorCodes::VALIDATION_CURRENCY, $this->get($app, ['amount' => '200', 'cy' => 'ZWD'])->errorCode());
        self::assertSame(200, $this->get($app, ['amount' => '200', 'cy' => 'BRL'])->status());
        $data = self::data($this->get($app, ['amount' => '200', 'cy' => 'ZWD', 'installments' => '3']));

        self::assertSame(['72,380.00', '24,126.66', '24,126.68'], [$data['display']['converted'], $data['display']['installment'], $data['display']['last_installment']]);
        self::assertSame(['endpoint' => 'convert', 'minor_units_default' => true, 'stale' => false, 'http_status' => 200], $this->events()[2]['meta']);
        self::assertArrayNotHasKey('minor_units_default', $this->events()[1]['meta']);
    }

    public function testFloatsAreTheFullPrecisionNumbers(): void
    {
        $response = $this->get($this->app(), ['amount' => '1000000', 'cy' => 'IRR', 'installments' => '7']);
        $data = self::data($response);

        // The rate is upstream's decimal exactly (the nearest float), not rounded to 6 places.
        self::assertSame(1_731_936.861971, $data['rate']);
        // A whole float encodes without a fraction (`1731936861971`); JSON numbers have no type.
        self::assertSame(1_000_000 * 1_731_936.861971, (float) $data['converted']);
        self::assertSame(1_000_000 * 1_731_936.861971 / 7, $data['installment_amount']);
        self::assertSame(1_000_000, $data['amount_usd']);
        self::assertSame('1000000.00', $data['display']['amount_usd']);
        self::assertSame('1,731,936.861971', $data['display']['rate']);
        self::assertStringContainsString('"amount_usd":1000000,', $response->body());

        $cents = self::data($this->get($this->app(), ['amount' => '0.29', 'cy' => 'USD']));
        self::assertSame(0.29, $cents['amount_usd']);
        self::assertSame('0.29', $cents['display']['converted']);
    }

    public function testOnlyGetIsServed(): void
    {
        $app = $this->app();
        foreach (['POST', 'PUT', 'DELETE'] as $method) {
            $response = $app->kernel()->handle(new Request($method, '/convert', ['amount' => '200', 'cy' => 'BRL']));
            self::assertSame(404, $response->status(), $method);
        }
        self::assertSame(404, $app->kernel()->handle(new Request('GET', '/convert/', ['amount' => '200', 'cy' => 'BRL']))->status());
        self::assertSame([], $this->events('api.request'));
    }

    public function testRoutingNeverBuildsTheUpstreamClient(): void
    {
        // No mocked client: building the real one would trip the no-network guard.
        $app = TestEnvironment::app($this->clock, [], $this->pdo);

        self::assertSame(404, $app->kernel()->handle(new Request('GET', '/no/such/route'))->status());
    }

    /**
     * @param array<string, mixed> $config
     */
    private function app(array $config = []): App
    {
        return TestEnvironment::app($this->clock, $config, $this->pdo, null, $this->upstream);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function get(App $app, array $query): JsonResponse
    {
        return $app->kernel()->handle(new Request('GET', '/convert', $query, remoteAddr: '198.51.100.4'));
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
     * @return list<array<string, mixed>>
     */
    private function events(string $type = 'api.request'): array
    {
        $select = $this->pdo->prepare('SELECT currency, amount_usd_cents, installments, status, error_code, meta FROM events WHERE event_type = ? ORDER BY id');
        $select->execute([$type]);

        return array_map(static function (array $row): array {
            foreach (['amount_usd_cents', 'installments'] as $column) {
                $row[$column] = $row[$column] === null ? null : (int) $row[$column];
            }
            $row['meta'] = json_decode((string) $row['meta'], true, 8, JSON_THROW_ON_ERROR);

            return $row;
        }, $select->fetchAll(PDO::FETCH_ASSOC));
    }
}
