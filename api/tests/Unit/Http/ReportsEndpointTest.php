<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Http;

use FxInstallments\App;
use FxInstallments\Http\ReportsEndpoint;
use FxInstallments\Http\Request;
use FxInstallments\Http\Response;
use FxInstallments\Http\TextResponse;
use FxInstallments\Metrics\ErrorCodes;
use FxInstallments\Tests\Support\FakeClock;
use FxInstallments\Tests\Support\OverridesEnvironment;
use FxInstallments\Tests\Support\TestEnvironment;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `GET /reports` query rules and answers, through the production route table (P1-08).
 */
final class ReportsEndpointTest extends TestCase
{
    use OverridesEnvironment;

    private const TOKEN = 'the-real-admin-token';

    /** 2026-10-22 03:00:00 UTC: still the 21st in Bogota. */
    private const NOW = 1792638000;

    private string $path;

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->path = TestEnvironment::freshDatabasePath();
        $this->pdo = TestEnvironment::connect($this->path);
        $this->setEnvironment('ADMIN_TOKEN', self::TOKEN);
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        self::assertTrue(TestEnvironment::removeDatabase($this->path));
    }

    public function testTheDefaultIsAJsonReportOfTheLastSevenDays(): void
    {
        $response = $this->get([]);

        self::assertSame(200, $response->status());
        self::assertSame('application/json', $response->header('Content-Type'));
        self::assertSame('no-store', $response->header('Cache-Control'));
        $data = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR)['data'];
        self::assertSame(['from' => '2026-10-15', 'to' => '2026-10-21', 'timezone' => 'America/Bogota', 'days' => 7, 'start' => '2026-10-15T05:00:00Z', 'end' => '2026-10-22T05:00:00Z'], $data['period']);
        self::assertSame('2026-10-22T03:00:00Z', $data['generated_at']);
    }

    public function testCsvIsATextAttachment(): void
    {
        $response = $this->get(['format' => 'csv', 'from' => '2026-10-01', 'to' => '2026-10-21']);

        self::assertInstanceOf(TextResponse::class, $response);
        self::assertSame('text/csv; charset=utf-8', $response->header('Content-Type'));
        self::assertSame('attachment; filename="fx-report-2026-10-01-to-2026-10-21.csv"', $response->header('Content-Disposition'));
        self::assertSame('no-store', $response->header('Cache-Control'));
        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
        self::assertStringStartsWith("section,key,subkey,metric,value\r\nperiod,,,from,2026-10-01\r\n", $response->body());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function badQueries(): iterable
    {
        yield 'unknown format' => [['format' => 'xml']];
        yield 'uppercase format' => [['format' => 'CSV']];
        yield 'table is CLI only' => [['format' => 'table']];
        yield 'empty format' => [['format' => '']];
        yield 'format as an array' => [['format' => ['csv']]];
        yield 'bad from' => [['from' => '2026-02-30']];
        yield 'empty to' => [['to' => '']];
        yield 'from as an array' => [['from' => ['2026-10-01']]];
        yield 'to as an array' => [['to' => ['x' => '2026-10-01']]];
        yield 'from after to' => [['from' => '2026-10-21', 'to' => '2026-10-20']];
        yield 'more than 366 days' => [['from' => '2025-10-20', 'to' => '2026-10-21']];
        yield 'a formula' => [['from' => '=CMD()']];
        // Round 2: was a 500 INTERNAL (the day after it isn't a four-digit year).
        yield 'year 9999' => [['from' => '9999-12-31', 'to' => '9999-12-31']];
        yield 'to in year 9999' => [['from' => '9998-12-31', 'to' => '9999-01-01']];
    }

    /**
     * @param array<string, mixed> $query
     */
    #[DataProvider('badQueries')]
    public function testABadQueryIsABadRequestEnvelope(array $query): void
    {
        $response = $this->get($query);

        self::assertSame(400, $response->status());
        self::assertSame(
            ['ok' => false, 'error' => ['code' => ErrorCodes::BAD_REQUEST, 'message_for_user' => ReportsEndpoint::BAD_QUERY_MESSAGE]],
            json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR),
        );
        self::assertSame([['event_type' => 'error', 'error_code' => 'BAD_REQUEST']], $this->pdo->query('SELECT event_type, error_code FROM events')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testOtherParametersAreIgnored(): void
    {
        self::assertSame(200, $this->get(['utm_source' => 'x', 'token' => self::TOKEN])->status());
    }

    public function testWithoutTheTokenNothingIsRead(): void
    {
        foreach ([null, 'Bearer wrong', 'Bearer ' . self::TOKEN . ' '] as $header) {
            $response = $this->get(['format' => 'csv'], $header);

            self::assertSame(401, $response->status());
            self::assertSame(ErrorCodes::ADMIN_UNAUTHORIZED, $response->errorCode());
        }

        $this->setEnvironment('ADMIN_TOKEN', '');
        self::assertSame(401, $this->get([])->status(), 'an empty ADMIN_TOKEN refuses everything');
        $this->setEnvironment('ADMIN_TOKEN', null);
        self::assertSame(401, $this->get([])->status(), 'so does an unset one');
    }

    public function testAnUnauthorizedQueryIsNotValidated(): void
    {
        self::assertSame(401, $this->get(['format' => 'xml'], null)->status());
    }

    /**
     * @param array<string, mixed> $query
     */
    private function get(array $query, ?string $authorization = 'Bearer ' . self::TOKEN): Response
    {
        $app = $this->app();

        return $app->kernel()->handle(new Request('GET', '/reports', $query, $authorization === null ? [] : ['Authorization' => $authorization], remoteAddr: '203.0.113.7'));
    }

    private function app(): App
    {
        return TestEnvironment::app(new FakeClock(self::NOW), [], $this->pdo);
    }
}
