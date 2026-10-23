<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Acceptance;

use ArrayObject;
use Closure;
use FxInstallments\App;
use FxInstallments\Config\ConfigRepository;
use FxInstallments\Convert\MinorUnits;
use FxInstallments\Db\Connection;
use FxInstallments\Http\JsonResponse;
use FxInstallments\Http\Request;
use FxInstallments\Metrics\ErrorCodes;
use FxInstallments\Rates\FallbackCurrencies;
use FxInstallments\Rates\RateStore;
use FxInstallments\Rates\RateTableAllowList;
use FxInstallments\Tests\Support\FakeClock;
use FxInstallments\Tests\Support\MockUpstream;
use FxInstallments\Tests\Support\TestEnvironment;
use FxInstallments\Validation\CurrencyValidator;
use FxInstallments\Validation\ValidationFailed;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * P1-10 follow-ups (docs/PLAN.md §10, P1-10's "Also" paragraphs from P1-04 and P1-05), pinned to
 * the codes of one real `/latest/USD` answer captured on 2026-10-22, kept in
 * tests/Fixtures/upstream_latest_usd_codes.txt. Nothing here reaches the network (ADR 0018):
 * - every captured code is on the fallback currency list, so on a cold start no code upstream
 *   quotes answers VALIDATION_CURRENCY (ADR 0012 amendment, the cold-start known limit);
 * - every captured code has an explicit `MinorUnits` exponent, so none falls back to DEFAULT;
 * - `MinorUnits` lists exactly the fallback codes, and the fallback list holds exactly three
 *   codes upstream didn't quote, KPW, SVC and VED (kept: the builder's choice, which the owner
 *   accepted);
 * - the codes P1-10 put in `UPSTREAM_ONLY` (BGN included, moved there in round 2) convert
 *   through `/convert` on a cold start and with a stored table, with the mocked upstream;
 * - a kept code the stored table lacks answers `not_in_table` on the refreshing request, then
 *   `not_allowed`.
 */
final class P1_10_LiveCodesTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../Fixtures/upstream_latest_usd_codes.txt';

    private const CAPTURED_ON = '2026-10-22';

    private const CAPTURED_COUNT = 166;

    /** sha256 of the captured codes, each followed by "\n" (the header left out) */
    private const CAPTURED_CODES_SHA256 = '9df483d9ad14fea1118e0f1e803655572ed58c49846d038cfdcde8f430354429';

    /** Fallback codes upstream didn't quote on 2026-10-22, kept (builder's choice, accepted). */
    private const KEPT_UNQUOTED = ['KPW', 'SVC', 'VED'];

    private const WHEN_UPSTREAM_ADDS_A_CODE = 'When upstream starts quoting a new code: add it to'
        . ' Rates\FallbackCurrencies (ISO_4217 if it is an active ISO 4217 code, otherwise'
        . ' UPSTREAM_ONLY with a one-line reason) and to Convert\MinorUnits (its ISO 4217 exponent,'
        . ' or its parent currency\'s; where the exponent is a choice, record it in ADR 0011,'
        . ' amendment item 6), refresh tests/Fixtures/upstream_latest_usd_codes.txt from a new'
        . ' /latest/USD capture (codes only; new date and count in its header, new sha256 here),'
        . ' and ask the lead to update ADR 0012 and CONTEXT.md.';

    private const CLIENT = '203.0.113.10';

    private FakeClock $clock;

    private string $path;

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->clock = new FakeClock(MockUpstream::LAST_UPDATE + 3600);
        $this->path = TestEnvironment::freshDatabasePath();
        $this->pdo = TestEnvironment::connect($this->path);
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        self::assertTrue(TestEnvironment::removeDatabase($this->path), 'the test database was released and removed');
    }

    public function testTheFixtureIsTheDatedCapture(): void
    {
        $header = implode("\n", self::fixtureLines(header: true));
        self::assertStringContainsString('Captured ' . self::CAPTURED_ON, $header);
        self::assertStringContainsString(self::CAPTURED_COUNT . ' codes', $header);

        $codes = self::capturedCodes();
        self::assertCount(self::CAPTURED_COUNT, $codes);
        foreach ($codes as $code) {
            self::assertMatchesRegularExpression('/^[A-Z]{3}$/D', $code);
        }
        $sorted = array_values(array_unique($codes));
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $codes, 'sorted, each code once');
        self::assertContains('USD', $codes, 'the base currency quotes itself');
        self::assertContains('BGN', $codes, 'upstream still quoted BGN on 2026-10-22');
        // Pins the list itself, so removing a code and editing the count together still fails.
        self::assertSame(
            self::CAPTURED_CODES_SHA256,
            hash('sha256', implode('', array_map(static fn (string $code): string => $code . "\n", $codes))),
            'tests/Fixtures/upstream_latest_usd_codes.txt is not the 2026-10-22 capture. Don\'t edit'
            . ' it by hand: recapture the live codes from one real /latest/USD answer, a call only the'
            . ' owner makes or authorizes (ADR 0011), then update the header\'s date and count, and'
            . ' CAPTURED_ON, CAPTURED_COUNT and CAPTURED_CODES_SHA256 here.',
        );
    }

    public function testTheListsMatchEachOtherAndTheCapture(): void
    {
        // Both are sorted by code (SORT_STRING), so they are compared in order.
        self::assertSame(FallbackCurrencies::all(), array_keys(MinorUnits::all()), 'MinorUnits lists exactly the fallback codes');
        self::assertSame(self::KEPT_UNQUOTED, array_values(array_diff(FallbackCurrencies::all(), self::capturedCodes())), 'the only fallback codes upstream did not quote are the three kept ones');
    }

    public function testEveryCapturedCodeIsOnTheFallbackList(): void
    {
        $missing = array_values(array_filter(
            self::capturedCodes(),
            static fn (string $code): bool => !FallbackCurrencies::contains($code),
        ));

        self::assertSame([], $missing, 'Not in FallbackCurrencies: ' . implode(', ', $missing) . '. ' . self::WHEN_UPSTREAM_ADDS_A_CODE);
    }

    public function testEveryCapturedCodeHasAnExplicitMinorUnit(): void
    {
        $missing = array_values(array_filter(
            self::capturedCodes(),
            static fn (string $code): bool => !MinorUnits::isKnown($code),
        ));

        self::assertSame([], $missing, 'Not in MinorUnits (they would use DEFAULT and be flagged): ' . implode(', ', $missing) . '. ' . self::WHEN_UPSTREAM_ADDS_A_CODE);
    }

    public function testOnAColdStartEveryCapturedCodePassesValidation(): void
    {
        $allowList = new RateTableAllowList(new RateStore(Connection::wrap($this->pdo)));
        $validator = new CurrencyValidator($allowList);

        $rejected = [];
        foreach (self::capturedCodes() as $code) {
            try {
                self::assertSame($code, $validator->validate(strtolower($code)), $code);
            } catch (ValidationFailed $error) {
                $rejected[] = "{$code} ({$error->reason})";
            }
        }
        self::assertSame([], $rejected, 'Refused on a cold start: ' . implode(', ', $rejected) . '. ' . self::WHEN_UPSTREAM_ADDS_A_CODE);
        self::assertTrue($allowList->usesFallback(), 'no table stored: the fallback list decided');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function upstreamOnlyCodesFromP1_10(): iterable
    {
        foreach (['ANG', 'BGN', 'CNH', 'HRK', 'SLL', 'ZWL'] as $code) {
            yield $code => [$code];
        }
    }

    #[DataProvider('upstreamOnlyCodesFromP1_10')]
    public function testAnUpstreamOnlyCodeFromP1_10ConvertsOnAColdStart(string $code): void
    {
        self::assertSame(0, $this->storedRates(), 'cold start');
        $upstream = new MockUpstream([MockUpstream::latest(['USD' => 1, $code => 2.5])]);

        $response = $this->convert($this->app($upstream), $code);

        self::assertConverted($code, $response);
        self::assertSame(1, $upstream->calls());
        self::assertArrayNotHasKey('minor_units_default', $this->apiRequestMetas()[0], 'an explicit exponent, not DEFAULT');
    }

    #[DataProvider('upstreamOnlyCodesFromP1_10')]
    public function testAnUpstreamOnlyCodeFromP1_10ConvertsWithAStoredTable(string $code): void
    {
        $upstream = new MockUpstream([MockUpstream::latest(['USD' => 1, $code => 2.5])]);
        $app = $this->app($upstream);
        self::assertSame(200, $this->convert($app, 'USD')->status(), 'the first request stores the table');
        self::assertSame(2, $this->storedRates());
        self::assertFalse($app->currencyAllowList()->usesFallback(), 'the stored table is the allow-list');

        $response = $this->convert($app, $code);

        self::assertConverted($code, $response);
        self::assertSame(1, $upstream->calls(), 'served from the stored table');
        self::assertArrayNotHasKey('minor_units_default', $this->apiRequestMetas()[1]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function keptUnquotedCodes(): iterable
    {
        foreach (self::KEPT_UNQUOTED as $code) {
            yield $code => [$code];
        }
    }

    #[DataProvider('keptUnquotedCodes')]
    public function testAKeptCodeTheTableLacksIsRefusedAfterTheRefresh(string $code): void
    {
        // The FallbackCurrencies docblock: the refreshing request answers `not_in_table`; once the
        // table is stored, it is the allow-list, so the code is `not_allowed`.
        $upstream = new MockUpstream([MockUpstream::latest(['USD' => 1, 'EUR' => 0.8594])]);
        $app = $this->app($upstream);

        $first = $this->convert($app, $code);
        $second = $this->convert($app, $code);

        self::assertSame([400, ErrorCodes::UPSTREAM_UNSUPPORTED_CODE], [$first->status(), $first->errorCode()]);
        self::assertSame([400, ErrorCodes::VALIDATION_CURRENCY], [$second->status(), $second->errorCode()]);
        self::assertSame(['not_in_table', 'not_allowed'], array_column($this->apiRequestMetas(), 'reason'));
        self::assertSame(1, $upstream->calls());
    }

    private function convert(App $app, string $code): JsonResponse
    {
        return $app->kernel()->handle(
            new Request('GET', '/convert', ['amount' => '200', 'cy' => $code, 'installments' => '3'], remoteAddr: self::CLIENT),
        );
    }

    private static function assertConverted(string $code, JsonResponse $response): void
    {
        self::assertSame(200, $response->status(), $response->body());
        $data = json_decode($response->body(), true, 8, JSON_THROW_ON_ERROR)['data'];
        self::assertSame($code, $data['currency']);
        self::assertSame(['500.00', '166.66', '166.68'], [$data['display']['converted'], $data['display']['installment'], $data['display']['last_installment']]);
    }

    private function storedRates(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM rate_cache')->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>> the api.request events' meta, in order
     */
    private function apiRequestMetas(): array
    {
        $metas = [];
        foreach ($this->pdo->query("SELECT meta FROM events WHERE event_type = 'api.request' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) as $meta) {
            $metas[] = json_decode((string) $meta, true, 8, JSON_THROW_ON_ERROR);
        }

        return $metas;
    }

    private function app(MockUpstream $upstream): App
    {
        $log = new ArrayObject();

        return new App(
            new ConfigRepository([]),
            Connection::wrap($this->pdo),
            Closure::fromCallable($this->clock),
            static function (string $line) use ($log): void {
                $log[] = $line;
            },
            $upstream->client(),
        );
    }

    /**
     * @return list<string> the captured codes, in file order
     */
    private static function capturedCodes(): array
    {
        return self::fixtureLines(header: false);
    }

    /**
     * @return list<string> the header lines (`#`) or the code lines, blank lines left out
     */
    private static function fixtureLines(bool $header): array
    {
        $lines = file(self::FIXTURE, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines, 'the fixture is readable');

        $kept = [];
        foreach ($lines as $line) {
            $line = rtrim($line, "\r");
            if ($line !== '' && str_starts_with($line, '#') === $header) {
                $kept[] = $line;
            }
        }

        return $kept;
    }
}
