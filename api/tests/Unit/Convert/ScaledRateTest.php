<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Convert;

use FxInstallments\Convert\ScaledRate;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScaledRateTest extends TestCase
{
    public function testTheScaleIsTenToTheTenth(): void
    {
        self::assertSame(10 ** 10, ScaledRate::SCALE);
        self::assertSame(10, ScaledRate::DECIMALS);
        self::assertSame(2 ** 53, ScaledRate::MAX_SCALED);
    }

    /**
     * @return iterable<string, array{float, int}>
     */
    public static function rates(): iterable
    {
        yield 'USD' => [1.0, 10_000_000_000];
        yield 'BRL' => [5.2291, 52_291_000_000];
        yield 'LBP about 89,500' => [89_500.0, 895_000_000_000_000];
        yield 'LBP with decimals' => [89_507.123456, 895_071_234_560_000];
        yield 'a small rate' => [0.0001234, 1_234_000];
        yield 'KWD' => [0.307, 3_070_000_000];
        yield 'ten decimals' => [0.1234567891, 1_234_567_891];
        yield 'the smallest rate' => [1e-10, 1];
    }

    #[DataProvider('rates')]
    public function testRatesScaleAndRoundTripExactly(float $rate, int $scaled): void
    {
        self::assertSame($scaled, ScaledRate::fromRate($rate));
        self::assertSame($rate, ScaledRate::toRate($scaled));
        self::assertSame($scaled, ScaledRate::fromRate(ScaledRate::toRate($scaled)));
    }

    public function testRatesWithMoreThanTenDecimalsRoundAtTheTenth(): void
    {
        self::assertSame(1_234_567_891, ScaledRate::fromRate(0.12345678906));
        self::assertSame(1_234_567_890, ScaledRate::fromRate(0.12345678904));
    }

    public function testScaledValuesAreIntsAndToRateIsAlwaysAFloat(): void
    {
        self::assertIsInt(ScaledRate::fromRate(89_500.0));
        self::assertIsFloat(ScaledRate::toRate(10_000_000_000));
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function badRates(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-5.2];
        yield 'NaN' => [NAN];
        yield 'INF' => [INF];
        yield 'rounds to zero' => [4e-11];
        yield 'too large to scale exactly' => [1_000_000.0];
    }

    #[DataProvider('badRates')]
    public function testBadRatesAreRejected(float $rate): void
    {
        $this->expectException(InvalidArgumentException::class);
        ScaledRate::fromRate($rate);
    }

    public function testBadScaledValuesAreRejected(): void
    {
        foreach ([0, -1, ScaledRate::MAX_SCALED] as $scaled) {
            try {
                ScaledRate::toRate($scaled);
                self::fail("accepted {$scaled}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
