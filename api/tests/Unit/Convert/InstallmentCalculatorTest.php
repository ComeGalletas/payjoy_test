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
 *
 * Since round 2 the total is exact (owner decision 2026-10-21): it is checked against a
 * schoolbook decimal-string oracle written here (exactOracle()), with no bcmath.
 */
final class InstallmentCalculatorTest extends TestCase
{
    /**
     * The sweeps seed mt_rand with fixed seeds; reseed it randomly afterwards, so later tests
     * don't inherit that state (L-04 cleanup B).
     */
    protected function tearDown(): void
    {
        mt_srand();
    }

    /**
     * The §3a examples and more: cents, rate (decimal text), n, currency → total, installment,
     * last, regular_count, even.
     *
     * @return iterable<string, array{int, string, int, string, string, string, string, int, bool}>
     */
    public static function splits(): iterable
    {
        yield '100 / 3 USD' => [10_000, '1', 3, 'USD', '100.00', '33.33', '33.34', 2, false];
        yield '200 / 3 USD' => [20_000, '1', 3, 'USD', '200.00', '66.66', '66.68', 2, false];
        yield '1.00 / 60 USD' => [100, '1', 60, 'USD', '1.00', '0.01', '0.41', 59, false];
        yield 'JPY 30,074 / 4' => [100, '30074', 4, 'JPY', '30,074', '7,518', '7,520', 3, false];
        yield 'BRL 1,045.82 / 3' => [20_000, '5.2291', 3, 'BRL', '1,045.82', '348.60', '348.62', 2, false];
        yield 'n = 1' => [20_000, '5.2291', 1, 'BRL', '1,045.82', '1,045.82', '1,045.82', 1, true];
        yield 'exact split' => [30_000, '1', 3, 'USD', '300.00', '100.00', '100.00', 3, true];
        yield 'exact split, JPY' => [100, '30000', 60, 'JPY', '30,000', '500', '500', 60, true];
        yield 'n = max, uneven' => [100_000, '1', 60, 'USD', '1,000.00', '16.66', '17.06', 59, false];
        yield 'n = max, one minor unit each' => [60, '1', 60, 'USD', '0.60', '0.01', '0.01', 60, true];
        yield 'KWD three decimals' => [20_000, '0.309652', 3, 'KWD', '61.930', '20.643', '20.644', 2, false];
        yield 'KWD one fils' => [1, '0.1', 1, 'KWD', '0.001', '0.001', '0.001', 1, true];
        yield 'CLF four decimals' => [20_000, '0.024825', 4, 'CLF', '4.9650', '1.2412', '1.2414', 3, false];
        yield 'CLF rounded at the fourth' => [100, '1.23456', 1, 'CLF', '1.2346', '1.2346', '1.2346', 1, true];
        yield 'JPY rounds to a whole yen' => [20_000, '151.468', 4, 'JPY', '30,294', '7,573', '7,575', 3, false];
        yield 'grouping in millions' => [100, '1234567.891', 2, 'USD', '1,234,567.89', '617,283.94', '617,283.95', 1, false];
        yield 'IRR, the largest real rate' => [100_000_000, '1731936.861971', 60, 'IRR', '1,731,936,861,971.00', '28,865,614,366.18', '28,865,614,366.38', 59, false];
        yield 'below one unit of the integer part' => [7, '1', 7, 'USD', '0.07', '0.01', '0.01', 7, true];
    }

    #[DataProvider('splits')]
    public function testSplits(int $cents, string $rate, int $n, string $currency, string $total, string $installment, string $last, int $regular, bool $even): void
    {
        $display = self::display($cents, $rate, $n, $currency);

        self::assertSame($total, $display->converted);
        self::assertSame($installment, $display->installment);
        self::assertSame($last, $display->lastInstallment);
        self::assertSame($regular, $display->regularCount);
        self::assertSame($even, $display->even);
        self::assertSumsToTotal($display, $n);
    }

    /**
     * Exact ties and near-ties: a true `x.xx5` rounds up (half away from zero); anything below
     * it stays down, even where a float would round it up.
     *
     * @return iterable<string, array{int, string, string, string}>
     */
    public static function halfwayCases(): iterable
    {
        yield '1.005' => [100, '1.005', 'USD', '1.01'];
        yield '2.675' => [100, '2.675', 'USD', '2.68'];
        yield '0.285' => [100, '0.285', 'USD', '0.29'];
        yield '1.5 × 0.67 = 1.005' => [150, '0.67', 'USD', '1.01'];
        yield '0.15 × 6.7 = 1.005' => [15, '6.7', 'USD', '1.01'];
        yield '1,045.825' => [100, '1045.825', 'BRL', '1,045.83'];
        yield '1.0049999999 stays down' => [100, '1.0049999999', 'USD', '1.00'];
        yield 'KWD 0.0005' => [1, '0.05', 'KWD', '0.001'];
        yield 'KWD 0.00049999 stays down' => [1, '0.049999', 'KWD', '0.000'];
        yield 'CLF 0.00005' => [1, '0.005', 'CLF', '0.0001'];
        yield 'JPY 0.5' => [50, '1', 'JPY', '1'];
        yield 'JPY 2.5' => [250, '1', 'JPY', '3'];
        yield 'JPY 7,518.5' => [100, '7518.5', 'JPY', '7,519'];
        // The tester's false tie: 49,493.44 × 5,554.578811 = 274,915,213.10749984 exactly. The
        // float product prints as …213.1074999 and PHP's round() made it .108.
        yield 'false tie .10749984' => [4_949_344, '5554.578811', 'KWD', '274,915,213.107'];
    }

    #[DataProvider('halfwayCases')]
    public function testHalfwayValuesRoundAwayFromZeroAndNearTiesDont(int $cents, string $rate, string $currency, string $expected): void
    {
        $total = InstallmentCalculator::minorUnitTotal($cents, ScaledRate::fromDecimalString($rate), $currency);
        self::assertSame((int) str_replace([',', '.'], '', $expected), $total);
        if ($total > 0) {
            self::assertSame($expected, self::display($cents, $rate, 1, $currency)->converted);
        }
    }

    /**
     * The tester's round-1 pins, where the float pipeline was one minor unit off.
     */
    public function testTheRoundOneMismatchesAreExactNow(): void
    {
        // 999,999.24 × 1,731,936.861971 = 1,731,935,545,698.98490204
        self::assertSame('1,731,935,545,698.98', self::display(99_999_924, '1731936.861971', 1, 'IRR')->converted);
        // 224,818.44 × 974,327.9856 = 219,046,897,770.934464
        self::assertSame('219,046,897,770.93', self::display(22_481_844, '974327.9856', 1, 'BRL')->converted);
        self::assertSame('274,915,213.107', self::display(4_949_344, '5554.578811', 1, 'KWD')->converted);
    }

    public function testBelowOneMinorUnitPerInstallmentIsRejected(): void
    {
        foreach ([[50, '1', 60, 'USD'], [59, '1', 60, 'USD'], [5_940, '1', 60, 'JPY'], [2, '0.1', 3, 'KWD'], [1, '0.4', 1, 'USD'], [1, '0.004', 1, 'CLF']] as [$cents, $rate, $n, $currency]) {
            try {
                self::display($cents, $rate, $n, $currency);
                self::fail("accepted {$cents} cents at {$rate} {$currency} / {$n}");
            } catch (ValidationFailed $e) {
                self::assertSame(ErrorCodes::VALIDATION_INSTALLMENTS, $e->errorCode);
                self::assertSame(ValidationFailed::REASON_BELOW_MINOR_UNIT, $e->reason);
            }
        }

        // Exactly one minor unit each passes.
        self::assertTrue(self::display(5_950, '1', 60, 'JPY')->even);
        self::assertSame('0.01', self::display(1, '0.5', 1, 'USD')->converted);
    }

    /**
     * The exact 2^53 boundary. $1,000,000.00 is 10^8 cents; in CLF (4 decimals) the total is
     * cents × rate_scaled / 10^8, so rate_scaled = 2^53 gives exactly 2^53 minor units.
     */
    public function testTheExactLimitIsTwoToThe53rdMinorUnits(): void
    {
        self::assertSame(2 ** 53, InstallmentCalculator::EXACT_LIMIT);

        self::assertSame(2 ** 53 - 1, InstallmentCalculator::minorUnitTotal(100_000_000, 2 ** 53 - 1, 'CLF'));
        self::assertNull(InstallmentCalculator::minorUnitTotal(100_000_000, 2 ** 53, 'CLF'));
        $below = InstallmentCalculator::display(100_000_000, 2 ** 53 - 1, 1, 'CLF', 0);
        self::assertSame('900,719,925,474.0991', $below->converted);

        // KWD (3 decimals): total = rate_scaled / 10 for 10^8 cents.
        self::assertSame(2 ** 53 - 1, InstallmentCalculator::minorUnitTotal(100_000_000, (2 ** 53 - 1) * 10, 'KWD'));
        self::assertNull(InstallmentCalculator::minorUnitTotal(100_000_000, 2 ** 53 * 10, 'KWD'));
        // Rounding up into the limit counts: 2^53 − 0.5 rounds to 2^53.
        self::assertNull(InstallmentCalculator::minorUnitTotal(100_000_000, 2 ** 53 * 10 - 5, 'KWD'));
        self::assertSame(2 ** 53 - 1, InstallmentCalculator::minorUnitTotal(100_000_000, 2 ** 53 * 10 - 6, 'KWD'));

        // The largest inputs at every exponent: no overflow, just null when too large.
        foreach ([2, 3, 4] as $exponent) {
            self::assertNull(InstallmentCalculator::exactTotal(999_999_999, ScaledRate::MAX_SCALED - 1, $exponent));
        }
        // At e = 0 even those fit: 9,999,999.99 × 899,999,999.9999999999 ≈ 8.99999991 × 10^15.
        self::assertSame(
            self::exactOracle(999_999_999, ScaledRate::MAX_SCALED - 1, 0),
            (string) InstallmentCalculator::exactTotal(999_999_999, ScaledRate::MAX_SCALED - 1, 0),
        );
        // 1,000,000 USD at the largest storable rate in JPY: 9 × 10^14 yen, below the limit.
        self::assertSame(900_000_000_000_000, InstallmentCalculator::minorUnitTotal(100_000_000, ScaledRate::MAX_SCALED - 1, 'JPY'));
    }

    public function testDisplayRefusesATooLargeTotal(): void
    {
        // ConvertService checks first; display() treats it as a programming error.
        $this->expectException(InvalidArgumentException::class);
        InstallmentCalculator::display(100_000_000, 2 ** 53, 1, 'CLF', 0);
    }

    public function testOtherDisplayFields(): void
    {
        $display = InstallmentCalculator::display(20_000, 52_291_000_000, 3, 'BRL', MockUpstream::LAST_UPDATE);

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

        // display.amount_usd is grouped like the other strings (owner decision, round 2).
        self::assertSame('1,000,000.00', InstallmentCalculator::display(100_000_000, ScaledRate::SCALE, 1, 'USD', 0)->amountUsd);
        self::assertSame('0.50', InstallmentCalculator::display(50, ScaledRate::SCALE, 1, 'USD', 0)->amountUsd);
        self::assertSame('1970-01-01 00:00 UTC', InstallmentCalculator::display(50, ScaledRate::SCALE, 1, 'USD', 59)->rateAsOf);
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

    public function testTheExponentRangeIsTheOneMinorUnitsUses(): void
    {
        $exponents = array_values(array_unique(MinorUnits::all()));
        sort($exponents);

        self::assertSame([0, 2, 3, InstallmentCalculator::MAX_EXPONENT], $exponents);
        self::assertSame(4, InstallmentCalculator::MAX_EXPONENT);
        self::assertSame(10_000, InstallmentCalculator::exactTotal(100, ScaledRate::SCALE, 4), '1.0000 at 4 decimals');
    }

    public function testInvalidArgumentsAreRejected(): void
    {
        $calls = [
            static fn () => self::display(100, '1', 0, 'USD'),
            static fn () => InstallmentCalculator::exactTotal(-1, ScaledRate::SCALE, 2),
            static fn () => InstallmentCalculator::exactTotal(1_000_000_000, ScaledRate::SCALE, 2),
            static fn () => InstallmentCalculator::exactTotal(100, 0, 2),
            static fn () => InstallmentCalculator::exactTotal(100, ScaledRate::MAX_SCALED, 2),
            static fn () => InstallmentCalculator::exactTotal(100, ScaledRate::SCALE, -1),
            // L-04 cleanup B: only the exponents in use (0..4), no longer up to 12.
            static fn () => InstallmentCalculator::exactTotal(100, ScaledRate::SCALE, 5),
            static fn () => InstallmentCalculator::exactTotal(100, ScaledRate::SCALE, 12),
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
     * The exact total against the schoolbook oracle: real-shaped rates (up to 7 integer digits
     * and 6 decimals), any stored rate (10 decimals, the whole range), and amounts from one cent
     * to amount.max, at every exponent in use (0, 2, 3, 4). Zero mismatches.
     */
    public function testTheTotalMatchesTheDecimalOracle(): void
    {
        mt_srand(20261021);
        $checked = 0;
        for ($i = 0; $i < 25_000; $i++) {
            $cents = match ($i % 4) {
                0 => mt_rand(1, 100),
                1 => mt_rand(1, 100_000),
                2 => mt_rand(1, 100_000_000),
                default => mt_rand(99_000_000, 100_000_000),
            };
            $rateScaled = match (intdiv($i, 4) % 3) {
                0 => mt_rand(1, 9_999_999_999_999) * 10_000, // up to 9,999,999.999999 (6 decimals)
                1 => mt_rand(1, 20_000_000_000_000_000), // up to 2,000,000, 10 decimals
                default => mt_rand(1, ScaledRate::MAX_SCALED - 1), // anything storable
            };
            foreach ([0, 2, 3, 4] as $exponent) {
                $expected = self::exactOracle($cents, $rateScaled, $exponent);
                $actual = InstallmentCalculator::exactTotal($cents, $rateScaled, $exponent);
                if (strlen($expected) > 16 || (strlen($expected) === 16 && strcmp($expected, (string) InstallmentCalculator::EXACT_LIMIT) >= 0)) {
                    self::assertNull($actual, "{$cents} × {$rateScaled} at e = {$exponent}");
                } else {
                    self::assertSame($expected, (string) $actual, "{$cents} × {$rateScaled} at e = {$exponent}");
                }
                $checked++;
            }
        }

        self::assertSame(100_000, $checked);
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
        mt_srand(53);
        $currencies = ['JPY', 'USD', 'BRL', 'KWD', 'CLF', 'COP', 'IRR'];
        $checked = 0;
        $rejected = 0;
        for ($i = 0; $i < 4000; $i++) {
            $cents = [mt_rand(1, 100), mt_rand(1, 100_000), mt_rand(1, 100_000_000)][$i % 3];
            $rateScaled = [mt_rand(1, 10_000_000_000), mt_rand(1, 10_000_000_000_000), mt_rand(1, 20_000_000_000_000_000)][intdiv($i, 3) % 3];
            $currency = $currencies[$i % count($currencies)];
            $total = InstallmentCalculator::minorUnitTotal($cents, $rateScaled, $currency);
            if ($total === null) {
                continue;
            }
            foreach ([1, 2, 3, mt_rand(4, 59), 60] as $n) {
                try {
                    $display = InstallmentCalculator::display($cents, $rateScaled, $n, $currency, 0);
                } catch (ValidationFailed $e) {
                    self::assertSame(ValidationFailed::REASON_BELOW_MINOR_UNIT, $e->reason);
                    self::assertLessThan($n, $total);
                    $rejected++;
                    continue;
                }
                self::assertSame($total, $display->totalMinor);
                self::assertSumsToTotal($display, $n);
                self::assertSame(MinorUnits::of($currency), $display->exponent);
                $checked++;
            }
        }

        self::assertGreaterThan(10_000, $checked);
        self::assertGreaterThan(100, $rejected);
    }

    public function testTheOracleItself(): void
    {
        self::assertSame('104582', self::exactOracle(20_000, 52_291_000_000, 2));
        self::assertSame('101', self::exactOracle(100, 10_050_000_000, 2));
        self::assertSame('100', self::exactOracle(100, 10_049_999_999, 2));
        self::assertSame('0', self::exactOracle(1, 1, 0));
        self::assertSame('173193554569898', self::exactOracle(99_999_924, 17_319_368_619_710_000, 2));
    }

    /**
     * round_half_up(cents × rate_scaled / 10^(12 − e)) by schoolbook multiplication of decimal
     * digit strings, then dropping 12 − e digits and rounding on the first dropped one. Written
     * independently of the limb arithmetic under test, with no bcmath and no float.
     */
    private static function exactOracle(int $cents, int $rateScaled, int $exponent): string
    {
        $a = array_map('intval', str_split(strrev((string) $cents)));
        $b = array_map('intval', str_split(strrev((string) $rateScaled)));
        $product = array_fill(0, count($a) + count($b), 0);
        foreach ($a as $i => $x) {
            $carry = 0;
            foreach ($b as $j => $y) {
                $sum = $product[$i + $j] + $x * $y + $carry;
                $product[$i + $j] = $sum % 10;
                $carry = intdiv($sum, 10);
            }
            for ($k = $i + count($b); $carry > 0; $k++) {
                $sum = $product[$k] + $carry;
                $product[$k] = $sum % 10;
                $carry = intdiv($sum, 10);
            }
        }
        $digits = ltrim(strrev(implode('', $product)), '0');
        $drop = 12 - $exponent;
        if (strlen($digits) <= $drop) {
            $digits = str_pad($digits, $drop + 1, '0', STR_PAD_LEFT);
        }
        $kept = substr($digits, 0, strlen($digits) - $drop);
        $roundUp = $digits[strlen($digits) - $drop] >= '5';
        if ($roundUp) {
            // Add one to the decimal string.
            $i = strlen($kept) - 1;
            while ($i >= 0 && $kept[$i] === '9') {
                $kept[$i] = '0';
                $i--;
            }
            $kept = $i < 0 ? '1' . $kept : substr_replace($kept, (string) ((int) $kept[$i] + 1), $i, 1);
        }
        $kept = ltrim($kept, '0');

        return $kept === '' ? '0' : $kept;
    }

    private static function display(int $cents, string $rate, int $n, string $currency): Display
    {
        return InstallmentCalculator::display($cents, ScaledRate::fromDecimalString($rate), $n, $currency, 0);
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
