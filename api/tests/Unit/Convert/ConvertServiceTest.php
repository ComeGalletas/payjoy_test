<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Convert;

use FxInstallments\Convert\ConvertService;
use FxInstallments\Metrics\ErrorCodes;
use FxInstallments\Rates\UpstreamError;
use FxInstallments\Tests\Support\FakeClock;
use FxInstallments\Tests\Support\MockUpstream;
use FxInstallments\Tests\Support\TestEnvironment;
use FxInstallments\Validation\UsdAmount;
use FxInstallments\Validation\ValidationFailed;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * ConvertService as the bot (P1-06) will call it: already-validated inputs into convert().
 */
final class ConvertServiceTest extends TestCase
{
    private string $path;

    private PDO $pdo;

    private FakeClock $clock;

    protected function setUp(): void
    {
        $this->path = TestEnvironment::freshDatabasePath();
        $this->pdo = TestEnvironment::connect($this->path);
        $this->clock = new FakeClock(MockUpstream::LAST_UPDATE + 3600);
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        self::assertTrue(TestEnvironment::removeDatabase($this->path), 'the test database was released and removed');
    }

    public function testConvertComputesLocallyAtFullPrecision(): void
    {
        $conversion = $this->service(new MockUpstream([MockUpstream::latest()]))
            ->convert(new UsdAmount(20_000, '200.00'), 'BRL', 3);

        self::assertSame('BRL', $conversion->currency);
        self::assertSame(5.2291, $conversion->rate);
        self::assertSame(200 * 5.2291, $conversion->converted);
        self::assertSame(200 * 5.2291 / 3, $conversion->installmentAmount);
        self::assertSame(MockUpstream::LAST_UPDATE, $conversion->rateAsOf);
        self::assertFalse($conversion->stale);
        self::assertSame('348.62', $conversion->display->lastInstallment);
        self::assertSame(20_000, $conversion->amount->cents);
    }

    /**
     * Pins the 2^53 check in ConvertService, before display(): display() refuses such a total
     * as a programming error (InvalidArgumentException), so without this check the request
     * would become a 500 instead of VALIDATION_AMOUNT.
     */
    public function testTheExactLimitIsCheckedBeforeDisplay(): void
    {
        // 1,000,000 USD in KWD (3 decimals): rate_scaled / 10 minor units for 10^8 cents.
        $service = $this->service(new MockUpstream([MockUpstream::latest(['USD' => 1, 'KWD' => '9007199.254740992', 'BHD' => '9007199.254740991'])]));
        $million = new UsdAmount(100_000_000, '1000000.00');

        try {
            $service->convert($million, 'KWD', 1);
            self::fail('a total of 2^53 minor units was accepted');
        } catch (ValidationFailed $e) {
            self::assertSame(ErrorCodes::VALIDATION_AMOUNT, $e->errorCode);
            self::assertSame(ValidationFailed::REASON_TOO_LARGE, $e->reason);
        }

        $below = $service->convert($million, 'BHD', 1);
        self::assertSame(2 ** 53 - 1, $below->display->totalMinor);
        self::assertSame('9,007,199,254,740.991', $below->display->converted);
    }

    public function testTheDisplayIsExactWhileTheRawNumbersAreFloats(): void
    {
        // 999,999.24 × 1,731,936.861971 = 1,731,935,545,698.98490204: the float product rounds
        // to …698.99, the display doesn't.
        $conversion = $this->service(new MockUpstream([MockUpstream::latest()]))
            ->convert(new UsdAmount(99_999_924, '999999.24'), 'IRR', 1);

        self::assertSame('1,731,935,545,698.98', $conversion->display->converted);
        self::assertSame(999_999.24 * 1_731_936.861971, $conversion->converted);
    }

    public function testInstallmentsAreRangeCheckedBeforeTheRateLookup(): void
    {
        $upstream = new MockUpstream([MockUpstream::latest()]);
        $service = $this->service($upstream);

        foreach ([0, 61, -3] as $n) {
            try {
                $service->convert(new UsdAmount(20_000, '200.00'), 'BRL', $n);
                self::fail("accepted {$n} installments");
            } catch (ValidationFailed $e) {
                self::assertSame(ErrorCodes::VALIDATION_INSTALLMENTS, $e->errorCode);
                self::assertSame(ValidationFailed::REASON_OUT_OF_RANGE, $e->reason);
            }
        }
        self::assertSame(0, $upstream->calls());
    }

    public function testAbsentParameters(): void
    {
        $service = $this->service(new MockUpstream());

        self::assertSame(1, $service->installments(null));
        foreach ([static fn () => $service->amount(null), static fn () => $service->currency(null)] as $call) {
            try {
                $call();
                self::fail('an absent parameter was accepted');
            } catch (ValidationFailed $e) {
                self::assertSame(ValidationFailed::REASON_FORMAT, $e->reason);
            }
        }
    }

    public function testUpstreamErrorsReachTheCaller(): void
    {
        $service = $this->service(new MockUpstream([MockUpstream::error('quota-reached', 429)]));

        $this->expectException(UpstreamError::class);
        $service->convert(new UsdAmount(100, '1.00'), 'BRL', 1);
    }

    private function service(MockUpstream $upstream): ConvertService
    {
        return TestEnvironment::app($this->clock, [], $this->pdo, null, $upstream)->convertService();
    }
}
