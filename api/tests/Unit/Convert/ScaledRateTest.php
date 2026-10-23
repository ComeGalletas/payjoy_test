<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Convert;

use FxInstallments\Convert\ScaledRate;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScaledRateTest extends TestCase
{
    /**
     * The sweeps seed mt_rand with fixed seeds; reseed it randomly afterwards, so later tests
     * don't inherit that state (L-04 cleanup B).
     */
    protected function tearDown(): void
    {
        mt_srand();
    }

    /** Random rates per decimal count (0..10) in the sweep. */
    private const SWEEP_PER_DECIMAL_COUNT = 20_000;

    /**
     * 0..10 decimals cover the stored precision; 11..16 make the float's shortest text longer
     * than ten decimals, so the eleventh-digit rounding is swept too.
     */
    private const SWEEP_MAX_DECIMALS = 16;

    public function testTheScaleAndLimits(): void
    {
        self::assertSame(10 ** 10, ScaledRate::SCALE);
        self::assertSame(10, ScaledRate::DECIMALS);
        self::assertSame(900_000_000, ScaledRate::MAX_RATE);
        self::assertSame(ScaledRate::MAX_RATE * ScaledRate::SCALE, ScaledRate::MAX_SCALED);
        self::assertLessThan(PHP_INT_MAX, ScaledRate::MAX_SCALED);
    }

    /**
     * @return iterable<string, array{float, int}>
     */
    public static function rates(): iterable
    {
        // The float route gave 4395376133999999 (439537.6134 × 1e10 = 4395376133999999.5).
        yield 'large rate that float rounding truncated' => [439_537.6134, 4_395_376_134_000_000];
        yield 'another large rate' => [322_366.68398, 3_223_666_839_800_000];
        yield 'LBP about 89,500' => [89_500.0, 895_000_000_000_000];
        yield 'LBP with decimals' => [89_507.123456, 895_071_234_560_000];
        yield 'BRL' => [5.2291, 52_291_000_000];
        yield 'USD' => [1.0, 10_000_000_000];
        yield 'a small rate' => [0.0001234, 1_234_000];
        yield 'KWD' => [0.307, 3_070_000_000];
        yield 'ten decimals' => [0.1234567891, 1_234_567_891];
        yield 'printed as 1.234E-5' => [1.234e-5, 123_400];
        yield 'printed as 1.0E-10, the smallest unit' => [1e-10, 1];
        yield 'printed as 5.0E-11, rounds up to one unit' => [5e-11, 1];
        // Live ExchangeRate-API USD table, measured by the lead: at most 7 integer digits and 6
        // decimals. IRR is above the old 2^53 limit (about 900,719), which rejected it.
        yield 'IRR, the largest live rate' => [1_731_936.861971, 17_319_368_619_710_000];
        yield 'VND' => [25_846.196303, 258_461_963_030_000];
        yield 'COP' => [3_153.773599, 31_537_735_990_000];
        yield 'KWD live' => [0.309652, 3_096_520_000];
        yield 'CLF' => [0.024825, 248_250_000];
        yield 'just below the limit' => [899_999_999.0, 8_999_999_990_000_000_000];
        yield 'below the limit with decimals' => [899_999_999.5, 8_999_999_995_000_000_000];
    }

    #[DataProvider('rates')]
    public function testFloatRatesScaleExactly(float $rate, int $scaled): void
    {
        self::assertSame($scaled, ScaledRate::fromRate($rate));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function decimalStrings(): iterable
    {
        yield 'plain' => ['439537.6134', 4_395_376_134_000_000];
        yield 'IRR as upstream sends it' => ['1731936.861971', 17_319_368_619_710_000];
        yield 'integer' => ['89500', 895_000_000_000_000];
        yield 'leading zeros' => ['0005.2291', 52_291_000_000];
        yield 'exponent, negative' => ['1.234E-5', 123_400];
        yield 'exponent, lower case' => ['1.234e-5', 123_400];
        yield 'exponent, positive' => ['1.5E+2', 1_500_000_000_000];
        yield 'exponent, no sign' => ['2E3', 20_000_000_000_000];
        yield 'exponent moves the point into the digits' => ['123456.789E-3', 1_234_567_890_000];
        yield 'exponent, 5.0E-11 rounds up' => ['5.0E-11', 1];
        // Rounding half away from zero on the eleventh decimal.
        yield '11th digit 4 rounds down' => ['0.12345678904', 1_234_567_890];
        yield '11th digit 4 then 9s rounds down' => ['0.123456789049999999', 1_234_567_890];
        yield '11th digit exactly 5 rounds up' => ['0.12345678905', 1_234_567_891];
        yield '11th digit 5 then digits rounds up' => ['0.123456789050001', 1_234_567_891];
        yield '11th digit 6 rounds up' => ['0.12345678906', 1_234_567_891];
        yield 'rounding carries into the integer part' => ['0.99999999995', 10_000_000_000];
        yield 'rounding carries across digits' => ['1.99999999995', 20_000_000_000];
        yield 'many decimals truncated after rounding' => ['5.229100000049999', 52_291_000_000];
        yield 'largest accepted' => ['899999999.9999999999', 8_999_999_999_999_999_999];
        yield 'largest accepted, 11th digit 4' => ['899999999.99999999994', 8_999_999_999_999_999_999];
    }

    #[DataProvider('decimalStrings')]
    public function testDecimalStringsScaleWithIntegerArithmetic(string $rate, int $scaled): void
    {
        self::assertSame($scaled, ScaledRate::fromDecimalString($rate));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badDecimalStrings(): iterable
    {
        foreach (['', '0', '0.0', '0.00000000004999', '4.9E-11', '1E-11', '-1', '+1', '1.', '.5', '1e', '1E+',
            ' 1', '1 ', '1,5', 'abc', 'NaN', 'INF', '0x10', "1\n",
            '900000000', '900000000.0', '899999999.99999999995', '9E8', '1000000000', '1E+20', '1E99999'] as $rate) {
            yield var_export($rate, true) => [$rate];
        }
    }

    #[DataProvider('badDecimalStrings')]
    public function testBadDecimalStringsAreRejected(string $rate): void
    {
        $this->expectException(InvalidArgumentException::class);
        ScaledRate::fromDecimalString($rate);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function badRates(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative zero' => [-0.0];
        yield 'negative' => [-5.2];
        yield 'NaN' => [NAN];
        yield 'INF' => [INF];
        yield 'rounds to zero' => [4e-11];
        yield 'the limit' => [900_000_000.0];
        yield 'above the limit' => [1e12];
    }

    #[DataProvider('badRates')]
    public function testBadRatesAreRejected(float $rate): void
    {
        $this->expectException(InvalidArgumentException::class);
        ScaledRate::fromRate($rate);
    }

    public function testToDecimalStringIsTheExactStoredValue(): void
    {
        self::assertSame('5.2291', ScaledRate::toDecimalString(52_291_000_000));
        self::assertSame('89500', ScaledRate::toDecimalString(895_000_000_000_000));
        self::assertSame('1', ScaledRate::toDecimalString(ScaledRate::SCALE));
        self::assertSame('0.0000000001', ScaledRate::toDecimalString(1));
        self::assertSame('1731936.861971', ScaledRate::toDecimalString(17_319_368_619_710_000));
        self::assertSame('899999999.9999999999', ScaledRate::toDecimalString(ScaledRate::MAX_SCALED - 1));

        // It round-trips through fromDecimalString(), across the range.
        mt_srand(5);
        for ($i = 0; $i < 2000; $i++) {
            $scaled = mt_rand(1, ScaledRate::MAX_SCALED - 1);
            self::assertSame($scaled, ScaledRate::fromDecimalString(ScaledRate::toDecimalString($scaled)));
        }

        foreach ([0, -1, ScaledRate::MAX_SCALED] as $scaled) {
            try {
                ScaledRate::toDecimalString($scaled);
                self::fail("accepted {$scaled}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testFromRateIgnoresTheSerializePrecisionSetting(): void
    {
        $previous = ini_set('serialize_precision', '17');
        try {
            self::assertSame(52_291_000_000, ScaledRate::fromRate(5.2291));
            self::assertSame('17', ini_get('serialize_precision'));
        } finally {
            ini_set('serialize_precision', (string) $previous);
        }
    }

    public function testToRateIsTheNearestFloatToTheStoredDecimal(): void
    {
        self::assertSame(5.2291, ScaledRate::toRate(52_291_000_000));
        self::assertSame(0.0001234, ScaledRate::toRate(1_234_000));
        self::assertSame(89_500.0, ScaledRate::toRate(895_000_000_000_000));
        self::assertSame(439_537.6134, ScaledRate::toRate(4_395_376_134_000_000));
        self::assertIsFloat(ScaledRate::toRate(10_000_000_000));
        // Above 2^53 scaled too (P1-05): IRR's rate is upstream's number exactly.
        self::assertSame(1_731_936.861971, ScaledRate::toRate(17_319_368_619_710_000));
        self::assertSame(899_999_999.9999999999, ScaledRate::toRate(ScaledRate::MAX_SCALED - 1));

        foreach ([0, -1, ScaledRate::MAX_SCALED] as $scaled) {
            try {
                ScaledRate::toRate($scaled);
                self::fail("accepted {$scaled}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Random rates with 0..16 decimals across (0, 899,999,999.x], compared with a reference
     * written differently: the shortest round-trip text comes from sprintf('%.Ne') instead of
     * var_export, and the scaling shifts the scientific mantissa by its exponent instead of
     * splitting integer and fraction digits. When the generated decimal has at most 15
     * significant digits (the float then prints as that decimal), the result must also equal
     * the decimal's exact scaled value, rounded half up on the eleventh decimal.
     */
    public function testARandomSweepMatchesAnIndependentReference(): void
    {
        mt_srand(20261021);
        $mismatches = [];
        $exactChecked = 0;
        $checked = [];
        for ($decimals = 0; $decimals <= self::SWEEP_MAX_DECIMALS; $decimals++) {
            $checked[$decimals] = 0;
            for ($i = 0; $checked[$decimals] < self::SWEEP_PER_DECIMAL_COUNT; $i++) {
                // Half uniform over the whole range, half spread over the integer digit count.
                $integer = $i % 2 === 0
                    ? mt_rand(0, ScaledRate::MAX_RATE - 1)
                    : mt_rand(0, 10 ** mt_rand(0, 8) - 1);
                $fractionDigits = '';
                for ($d = 0; $d < $decimals; $d++) {
                    $fractionDigits .= (string) mt_rand(0, 9);
                }
                if ($integer === 0 && trim($fractionDigits, '0') === '') {
                    continue;
                }
                $text = $decimals === 0 ? (string) $integer : "{$integer}.{$fractionDigits}";
                $rate = (float) $text;
                $checked[$decimals]++;

                $actual = ScaledRate::fromRate($rate);
                $expected = self::referenceScaled($rate);
                if ($actual !== $expected) {
                    $mismatches[] = "{$text}: {$actual} != {$expected}";
                }
                $significant = strlen(ltrim((string) $integer . $fractionDigits, '0'));
                if ($significant <= 15) {
                    $padded = str_pad($fractionDigits, 11, '0');
                    $exact = $integer * ScaledRate::SCALE + (int) substr($padded, 0, 10) + ($padded[10] >= '5' ? 1 : 0);
                    if ($actual !== $exact) {
                        $mismatches[] = "{$text}: {$actual} != exact {$exact}";
                    }
                    $exactChecked++;
                }
            }
        }

        self::assertSame([], array_slice($mismatches, 0, 20), count($mismatches) . ' mismatches');
        self::assertSame(array_fill(0, self::SWEEP_MAX_DECIMALS + 1, self::SWEEP_PER_DECIMAL_COUNT), $checked);
        self::assertGreaterThan(150_000, $exactChecked);
    }

    /**
     * The live table's shape (1 to 7 integer digits, up to 6 decimals; at most 13 significant
     * digits, so each float prints as its decimal): every rate scales to its exact value. Half
     * of them have exactly 7 integer digits and 6 decimals, like IRR.
     */
    public function testLiveTableShapedRatesAreExact(): void
    {
        mt_srand(17319368);
        $mismatches = [];
        for ($i = 0; $i < self::SWEEP_PER_DECIMAL_COUNT; $i++) {
            [$integer, $decimals] = $i % 2 === 0
                ? [mt_rand(1_000_000, 9_999_999), 6]
                : [mt_rand(0, 10 ** mt_rand(1, 7) - 1), mt_rand(1, 6)];
            $fraction = '';
            for ($d = 0; $d < $decimals; $d++) {
                $fraction .= (string) mt_rand(0, 9);
            }
            if ($integer === 0 && trim($fraction, '0') === '') {
                $fraction = str_repeat('0', $decimals - 1) . '1';
            }
            $text = "{$integer}.{$fraction}";
            $exact = $integer * ScaledRate::SCALE + (int) str_pad($fraction, 10, '0');
            foreach ([ScaledRate::fromRate((float) $text), ScaledRate::fromDecimalString($text), self::referenceScaled((float) $text)] as $scaled) {
                if ($scaled !== $exact) {
                    $mismatches[] = "{$text}: {$scaled} != {$exact}";
                }
            }
        }

        self::assertSame([], array_slice($mismatches, 0, 20), count($mismatches) . ' mismatches');
    }

    /**
     * The reference: shortest round-trip text via sprintf, then mantissa × 10^(exponent + 10)
     * with string digits, rounded half up on the first dropped digit.
     */
    private static function referenceScaled(float $rate): int
    {
        for ($precision = 0; $precision <= 17; $precision++) {
            $text = sprintf("%.{$precision}e", $rate);
            if ((float) $text === $rate) {
                break;
            }
        }
        self::assertSame(1, preg_match('/^(\d)(?:\.(\d+))?e([+-]\d+)$/', $text, $m), $text);
        $mantissa = $m[1] . ($m[2] ?? '');
        $shift = (int) $m[3] - strlen($m[2] ?? '') + 10;
        if ($shift >= 0) {
            return (int) ($mantissa . str_repeat('0', $shift));
        }
        $mantissa = str_repeat('0', -$shift + 1) . $mantissa;
        $kept = substr($mantissa, 0, strlen($mantissa) + $shift);
        $dropped = substr($mantissa, strlen($mantissa) + $shift);

        return (int) $kept + ($dropped[0] >= '5' ? 1 : 0);
    }
}
