<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Convert;

use FxInstallments\Convert\Display;
use FxInstallments\Convert\InstallmentCalculator;
use FxInstallments\Convert\MinorUnits;
use FxInstallments\Convert\ScaledRate;
use FxInstallments\Metrics\ErrorCodes;
use FxInstallments\Tests\Support\MockUpstream;
use FxInstallments\Validation\ValidationFailed;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * docs/PLAN.md §3a "Required tests": n = 1; exact splits; remainders; n = max; `x.xx5` cases;
 * JPY, KWD, CLF; amounts near the cap; reject below one minor unit; displayed installments
 * always sum to the displayed total and are never zero or negative.
 */
final class InstallmentCalculatorTest extends TestCase
{
    private const RATE_ONE = ScaledRate::SCALE;

    /**
     * The §3a examples and more: converted, n, currency → total, installment, last,
     * regular_count, even.
     *
     * @return iterable<string, array{float, int, string, string, string, string, int, bool}>
     */
    public static function splits(): iterable
    {
        yield '100 / 3 USD' => [100.0, 3, 'USD', '100.00', '33.33', '33.34', 2, false];
        yield '200 / 3 USD' => [200.0, 3, 'USD', '200.00', '66.66', '66.68', 2, false];
        yield '1.00 / 60 USD' => [1.0, 60, 'USD', '1.00', '0.01', '0.41', 59, false];
        yield 'JPY 30,074 / 4' => [30074.0, 4, 'JPY', '30,074', '7,518', '7,520', 3, false];
        yield 'BRL 1,045.82 / 3' => [200 * 5.2291, 3, 'BRL', '1,045.82', '348.60', '348.62', 2, false];
        yield 'n = 1' => [1045.82, 1, 'BRL', '1,045.82', '1,045.82', '1,045.82', 1, true];
        yield 'exact split' => [300.0, 3, 'USD', '300.00', '100.00', '100.00', 3, true];
        yield 'exact split, JPY' => [30000.0, 60, 'JPY', '30,000', '500', '500', 60, true];
        yield 'n = max, uneven' => [1000.0, 60, 'USD', '1,000.00', '16.66', '17.06', 59, false];
        yield 'n = max, one minor unit each' => [0.6, 60, 'USD', '0.60', '0.01', '0.01', 60, true];
        yield 'KWD three decimals' => [200 * 0.309652, 3, 'KWD', '61.930', '20.643', '20.644', 2, false];
        yield 'KWD one fils' => [0.001, 1, 'KWD', '0.001', '0.001', '0.001', 1, true];
        yield 'CLF four decimals' => [200 * 0.024825, 4, 'CLF', '4.9650', '1.2412', '1.2414', 3, false];
        yield 'CLF rounded at the fourth' => [1.23456, 1, 'CLF', '1.2346', '1.2346', '1.2346', 1, true];
        yield 'JPY rounds to a whole yen' => [200 * 151.468, 4, 'JPY', '30,294', '7,573', '7,575', 3, false];
        yield 'grouping in millions' => [1_234_567.891, 2, 'USD', '1,234,567.89', '617,283.94', '617,283.95', 1, false];
        yield 'IRR, the largest real rate' => [1_000_000 * 1_731_936.861971, 60, 'IRR', '1,731,936,861,971.00', '28,865,614,366.18', '28,865,614,366.38', 59, false];
        yield 'below one unit of the integer part' => [0.07, 7, 'USD', '0.07', '0.01', '0.01', 7, true];
    }

    #[DataProvider('splits')]
    public function testSplits(float $converted, int $n, string $currency, string $total, string $installment, string $last, int $regular, bool $even): void
    {
        $display = self::display($converted, $n, $currency);

        self::assertSame($total, $display->converted);
        self::assertSame($installment, $display->installment);
        self::assertSame($last, $display->lastInstallment);
        self::assertSame($regular, $display->regularCount);
        self::assertSame($even, $display->even);
        self::assertSumsToTotal($display, $n);
    }

    /**
     * `x.xx5` float drift: 1.005 is stored as 1.00499999999999989…, yet it is the value the
     * user's numbers meant, so it rounds up (half away from zero).
     *
     * @return iterable<string, array{float, string, string}>
     */
    public static function halfwayCases(): iterable
    {
        yield '1.005' => [1.005, 'USD', '1.01'];
        yield '2.675' => [2.675, 'USD', '2.68'];
        yield '1.255' => [1.255, 'USD', '1.26'];
        yield '0.285' => [0.285, 'USD', '0.29'];
        yield '8.345' => [8.345, 'USD', '8.35'];
        yield '1,045.825' => [1045.825, 'BRL', '1,045.83'];
        yield '1.5 × 0.67 (product drift)' => [1.5 * 0.67, 'USD', '1.01'];
        yield '0.15 × 6.7 (product drift)' => [0.15 * 6.7, 'USD', '1.01'];
        yield '1.0045 stays down' => [1.0045, 'USD', '1.00'];
        yield '1.00499 stays down' => [1.00499, 'USD', '1.00'];
        yield 'KWD 0.0005' => [0.0005, 'KWD', '0.001'];
        yield 'KWD 1.2345' => [1.2345, 'KWD', '1.235'];
        yield 'CLF 0.00005' => [0.00005, 'CLF', '0.0001'];
        yield 'CLF 4.96505' => [4.96505, 'CLF', '4.9651'];
        yield 'JPY 0.5' => [0.5, 'JPY', '1'];
        yield 'JPY 2.5' => [2.5, 'JPY', '3'];
        yield 'JPY 7,518.5' => [7518.5, 'JPY', '7,519'];
    }

    #[DataProvider('halfwayCases')]
    public function testHalfwayValuesRoundAwayFromZero(float $converted, string $currency, string $expected): void
    {
        self::assertSame($expected, self::display($converted, 1, $currency)->converted);
    }

    /**
     * A sweep of every x.xx5 value from 0.005 to 10,000.005 (2 decimals): the inner round
     * always gives the half-up result.
     */
    public function testEveryHalfCentUpToTenThousandRoundsUp(): void
    {
        $wrong = [];
        for ($thousandths = 5; $thousandths <= 10_000_005; $thousandths += 10) {
            $value = $thousandths / 1000;
            $expected = intdiv($thousandths + 5, 10);
            if ((int) InstallmentCalculator::minorUnitTotal($value, 'USD') !== $expected && count($wrong) < 5) {
                $wrong[] = $value;
            }
        }

        self::assertSame([], $wrong);
    }

    public function testBelowOneMinorUnitPerInstallmentIsRejected(): void
    {
        foreach ([[0.5, 60, 'USD'], [0.59, 60, 'USD'], [59.4, 60, 'JPY'], [0.002, 3, 'KWD'], [0.0, 1, 'USD'], [0.004, 1, 'USD'], [0.00004, 1, 'CLF']] as [$converted, $n, $currency]) {
            try {
                self::display($converted, $n, $currency);
                self::fail("accepted {$converted} {$currency} / {$n}");
            } catch (ValidationFailed $e) {
                self::assertSame(ErrorCodes::VALIDATION_INSTALLMENTS, $e->errorCode);
                self::assertSame(ValidationFailed::REASON_BELOW_MINOR_UNIT, $e->reason);
            }
        }

        // Exactly one minor unit each passes.
        self::assertTrue(self::display(59.5, 60, 'JPY')->even);
        self::assertSame('0.01', self::display(0.005, 1, 'USD')->converted);
    }

    public function testTheExactLimitIsTwoToThe53rdMinorUnits(): void
    {
        self::assertSame(2 ** 53, InstallmentCalculator::EXACT_LIMIT);

        // JPY has no decimals, so the converted value is the minor-unit total itself.
        $below = self::display((float) (2 ** 53 - 1), 1, 'JPY');
        self::assertSame(2 ** 53 - 1, $below->totalMinor);
        self::assertSame('9,007,199,254,740,991', $below->converted);
        self::assertTrue(InstallmentCalculator::fitsExactly((float) (2 ** 53 - 1), 'JPY'));

        foreach ([[(float) 2 ** 53, 'JPY'], [9.1e15, 'JPY'], [90_071_992_547_409.92, 'USD'], [1e17, 'USD'], [9.1e11, 'CLF']] as [$converted, $currency]) {
            self::assertFalse(InstallmentCalculator::fitsExactly($converted, $currency));
            try {
                self::display($converted, 1, $currency);
                self::fail("accepted {$converted} {$currency}");
            } catch (ValidationFailed $e) {
                self::assertSame(ErrorCodes::VALIDATION_AMOUNT, $e->errorCode);
                self::assertSame(ValidationFailed::REASON_TOO_LARGE, $e->reason);
            }
        }
    }

    /**
     * Near the cap a float still has a fraction below 2^52, but PHP's round() leaves values of
     * 10^15 or more unchanged; the calculator still rounds half away from zero.
     */
    public function testLargeTotalsWithAFractionStillRoundHalfUp(): void
    {
        self::assertSame(1_234_567_890_123_457, self::display(1_234_567_890_123_456.75, 1, 'JPY')->totalMinor);
        self::assertSame(1_234_567_890_123_457, self::display(1_234_567_890_123_456.5, 1, 'JPY')->totalMinor);
        self::assertSame(1_234_567_890_123_456, self::display(1_234_567_890_123_456.25, 1, 'JPY')->totalMinor);
        self::assertSame(4_000_000_000_000_001, self::display(4_000_000_000_000_000.5, 7, 'JPY')->totalMinor);

        // Amounts near amount.max with the largest real rates.
        $display = self::display(1_000_000 * 1_731_936.861971, 1, 'IRR');
        self::assertSame(173_193_686_197_100, $display->totalMinor);
        self::assertSame('1,731,936,861,971.00', $display->converted);
        self::assertSame('1,000,000.00', self::display(1_000_000.0, 60, 'USD')->converted);
    }

    public function testOtherDisplayFields(): void
    {
        $display = InstallmentCalculator::display(200 * 5.2291, 3, 'BRL', 52_291_000_000, 20_000, MockUpstream::LAST_UPDATE);

        self::assertSame([
            'amount_usd' => '200.00',
            'rate' => '5.2291',
            'converted' => '1,045.82',
            'installment' => '348.60',
            'last_installment' => '348.62',
            'regular_count' => 2,
            'even' => false,
            'rate_as_of' => '2026-10-21 00:00 UTC',
        ], $display->toArray());
        self::assertSame(2, $display->exponent);
        self::assertSame([104_582, 34_860, 34_862], [$display->totalMinor, $display->installmentMinor, $display->lastInstallmentMinor]);

        // The canonical USD string: two decimals, no grouping.
        self::assertSame('1000000.00', InstallmentCalculator::display(1.0, 1, 'USD', self::RATE_ONE, 100_000_000, 0)->amountUsd);
        self::assertSame('0.50', InstallmentCalculator::display(1.0, 1, 'USD', self::RATE_ONE, 50, 0)->amountUsd);
        self::assertSame('1970-01-01 00:00 UTC', InstallmentCalculator::display(1.0, 1, 'USD', self::RATE_ONE, 50, 59)->rateAsOf);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function rates(): iterable
    {
        yield 'BRL' => [52_291_000_000, '5.2291'];
        yield 'one' => [ScaledRate::SCALE, '1'];
        yield 'trailing zeros trimmed' => [15_000_000_000, '1.5'];
        yield 'six decimals kept' => [31_537_735_990_000, '3,153.773599'];
        yield 'IRR, grouped' => [17_319_368_619_710_000, '1,731,936.861971'];
        yield 'seventh decimal 5 rounds up' => [12_345_675_000, '1.234568'];
        yield 'seventh decimal 4 rounds down' => [12_345_674_999, '1.234567'];
        yield 'carry into the integer part' => [19_999_999_500, '2'];
        yield 'smallest that shows' => [5_000, '0.000001'];
        yield 'below half a millionth' => [4_999, '0'];
        yield 'largest stored' => [ScaledRate::MAX_SCALED - 1, '900,000,000'];
        yield 'KWD' => [3_096_520_000, '0.309652'];
    }

    #[DataProvider('rates')]
    public function testTheRateHasUpToSixDecimals(int $scaled, string $expected): void
    {
        self::assertSame($expected, InstallmentCalculator::formatRate($scaled));
    }

    public function testFormatMinor(): void
    {
        self::assertSame('0', InstallmentCalculator::formatMinor(0, 0));
        self::assertSame('999', InstallmentCalculator::formatMinor(999, 0));
        self::assertSame('1,000', InstallmentCalculator::formatMinor(1000, 0));
        self::assertSame('0.05', InstallmentCalculator::formatMinor(5, 2));
        self::assertSame('0.00', InstallmentCalculator::formatMinor(0, 2));
        self::assertSame('12,345.67', InstallmentCalculator::formatMinor(1_234_567, 2));
        self::assertSame('0.001', InstallmentCalculator::formatMinor(1, 3));
        self::assertSame('1.0000', InstallmentCalculator::formatMinor(10_000, 4));
        self::assertSame('9,007,199,254,740.991', InstallmentCalculator::formatMinor(2 ** 53 - 1, 3));
    }

    public function testInvalidArgumentsAreRejected(): void
    {
        $calls = [
            static fn () => self::display(1.0, 0, 'USD'),
            static fn () => self::display(-0.01, 1, 'USD'),
            static fn () => self::display(NAN, 1, 'USD'),
            static fn () => self::display(INF, 1, 'USD'),
            static fn () => InstallmentCalculator::formatMinor(-1, 2),
            static fn () => InstallmentCalculator::formatMinor(1, -1),
            static fn () => InstallmentCalculator::formatRate(0),
        ];
        foreach ($calls as $i => $call) {
            try {
                $call();
                self::fail("call {$i} was accepted");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Property sweep: for random amounts, rates, currencies (0, 2, 3 and 4 decimals) and every
     * installment count, the displayed installments sum exactly to the displayed total, none is
     * zero or negative, the final payment is the largest by less than n minor units, and the
     * strings read back as those integers. A rejection happens exactly when the total has fewer
     * minor units than installments.
     */
    public function testDisplayedInstallmentsAlwaysSumToTheDisplayedTotal(): void
    {
        mt_srand(20261021);
        $currencies = ['JPY', 'USD', 'BRL', 'KWD', 'CLF', 'COP', 'IRR'];
        $checked = 0;
        $rejected = 0;
        for ($i = 0; $i < 4000; $i++) {
            $cents = [mt_rand(1, 100), mt_rand(1, 100_000), mt_rand(1, 100_000_000)][$i % 3];
            $rateScaled = [mt_rand(1, 10_000_000_000), mt_rand(1, 10_000_000_000_000), mt_rand(1, 20_000_000_000_000_000)][intdiv($i, 3) % 3];
            $currency = $currencies[$i % count($currencies)];
            $converted = $cents / 100 * ScaledRate::toRate($rateScaled);
            if (!InstallmentCalculator::fitsExactly($converted, $currency)) {
                continue;
            }
            foreach ([1, 2, 3, mt_rand(4, 59), 60] as $n) {
                try {
                    $display = InstallmentCalculator::display($converted, $n, $currency, $rateScaled, $cents, 0);
                } catch (ValidationFailed $e) {
                    self::assertSame(ValidationFailed::REASON_BELOW_MINOR_UNIT, $e->reason);
                    self::assertLessThan($n, (int) InstallmentCalculator::minorUnitTotal($converted, $currency));
                    $rejected++;
                    continue;
                }
                self::assertSumsToTotal($display, $n);
                self::assertSame(MinorUnits::of($currency), $display->exponent);
                $checked++;
            }
        }

        self::assertGreaterThan(15_000, $checked);
        self::assertGreaterThan(100, $rejected);
    }

    /**
     * Against an exact integer oracle (amount in cents × rate with 6 decimals, rounded half up
     * at the currency's decimals) for totals below 10^9 minor units, the float pipeline never
     * drifts. Larger totals can differ by one minor unit (float precision, ADR 0011; a known
     * limit).
     */
    public function testTheFloatPipelineMatchesExactRoundingForTotalsBelowABillionMinorUnits(): void
    {
        mt_srand(53);
        $checked = 0;
        for ($i = 0; $i < 60_000; $i++) {
            $cents = mt_rand(1, $i % 2 === 0 ? 100_000 : 100_000_000);
            $micro = mt_rand(1, $i % 3 === 0 ? 1_000_000 : 90_000_000_000); // rate × 10^6, below 90,000
            foreach (['JPY' => 0, 'USD' => 2, 'KWD' => 3, 'CLF' => 4] as $currency => $exponent) {
                $product = $cents * $micro; // exact, at 10^8
                $divisor = 10 ** (8 - $exponent);
                $exact = intdiv($product, $divisor) + ($product % $divisor * 2 >= $divisor ? 1 : 0);
                if ($exact >= 1_000_000_000) {
                    continue;
                }
                $converted = $cents / 100 * ScaledRate::toRate($micro * 10_000);
                self::assertSame($exact, (int) InstallmentCalculator::minorUnitTotal($converted, $currency), "{$cents} cents × {$micro} µ in {$currency}");
                $checked++;
            }
        }

        self::assertGreaterThan(50_000, $checked);
    }

    private static function display(float $converted, int $n, string $currency): Display
    {
        return InstallmentCalculator::display($converted, $n, $currency, self::RATE_ONE, 100, 0);
    }

    private static function assertSumsToTotal(Display $display, int $n): void
    {
        $base = self::readMinor($display->installment, $display->exponent);
        $last = self::readMinor($display->lastInstallment, $display->exponent);
        $total = self::readMinor($display->converted, $display->exponent);

        self::assertSame([$display->installmentMinor, $display->lastInstallmentMinor, $display->totalMinor], [$base, $last, $total]);
        self::assertSame($total, $base * ($n - 1) + $last, 'the installments sum to the total');
        self::assertSame($display->even ? $n : $n - 1, $display->regularCount);
        self::assertSame($display->even, $base === $last);
        self::assertGreaterThanOrEqual(1, $base, 'no installment is zero or negative');
        self::assertGreaterThanOrEqual($base, $last);
        self::assertLessThan($n, $last - $base, 'the remainder is under n minor units');
    }

    /**
     * A display string read back into minor units, checking its format on the way.
     */
    private static function readMinor(string $text, int $exponent): int
    {
        $pattern = $exponent === 0 ? '/^\d{1,3}(,\d{3})*$/D' : '/^\d{1,3}(,\d{3})*\.\d{' . $exponent . '}$/D';
        self::assertMatchesRegularExpression($pattern, $text);

        return (int) str_replace([',', '.'], '', $text);
    }
}
