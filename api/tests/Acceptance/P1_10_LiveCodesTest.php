<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Acceptance;

use ArrayObject;
use Closure;
use FxInstallments\App;
use FxInstallments\Config\ConfigRepository;
use FxInstallments\Convert\MinorUnits;
use FxInstallments\Db\Connection;
use FxInstallments\Http\Request;
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
 * - the codes P1-10 added convert through `/convert` on a cold start, with the mocked upstream.
 */
final class P1_10_LiveCodesTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../Fixtures/upstream_latest_usd_codes.txt';

    private const CAPTURED_ON = '2026-10-22';

    private const CAPTURED_COUNT = 166;

    private const WHEN_UPSTREAM_ADDS_A_CODE = 'When upstream starts quoting a new code: add it to'
        . ' Rates\FallbackCurrencies (ISO_4217 if it is an active ISO 4217 code, otherwise'
        . ' UPSTREAM_ONLY with a one-line reason) and to Convert\MinorUnits (its ISO 4217 exponent,'
        . ' or its parent currency\'s), refresh tests/Fixtures/upstream_latest_usd_codes.txt from a'
        . ' new /latest/USD capture (codes only; new date and count in its header), and ask the lead'
        . ' to update ADR 0012 and CONTEXT.md.';

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
    public static function codesAddedInP1_10(): iterable
    {
        foreach (['ANG', 'CNH', 'HRK', 'SLL', 'ZWL'] as $code) {
            yield $code => [$code];
        }
    }

    #[DataProvider('codesAddedInP1_10')]
    public function testACodeAddedInP1_10ConvertsOnAColdStart(string $code): void
    {
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM rate_cache')->fetchColumn(), 'cold start');
        $upstream = new MockUpstream([MockUpstream::latest(['USD' => 1, $code => 2.5])]);

        $response = $this->app($upstream)->kernel()->handle(
            new Request('GET', '/convert', ['amount' => '200', 'cy' => $code, 'installments' => '3'], remoteAddr: self::CLIENT),
        );

        self::assertSame(200, $response->status(), $response->body());
        $data = json_decode($response->body(), true, 8, JSON_THROW_ON_ERROR)['data'];
        self::assertSame($code, $data['currency']);
        self::assertSame(['500.00', '166.66', '166.68'], [$data['display']['converted'], $data['display']['installment'], $data['display']['last_installment']]);
        self::assertSame(1, $upstream->calls());

        $meta = json_decode((string) $this->pdo->query("SELECT meta FROM events WHERE event_type = 'api.request'")->fetchColumn(), true, 8, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('minor_units_default', $meta, 'an explicit exponent, not DEFAULT');
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
