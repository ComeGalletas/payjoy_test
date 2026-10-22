<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Convert;

use FxInstallments\Convert\MinorUnits;
use FxInstallments\Rates\FallbackCurrencies;
use FxInstallments\Tests\Support\MockUpstream;
use PHPUnit\Framework\TestCase;

final class MinorUnitsTest extends TestCase
{
    public function testItCoversEveryFallbackCurrency(): void
    {
        $missing = array_values(array_filter(
            FallbackCurrencies::all(),
            static fn (string $code): bool => !MinorUnits::isKnown($code),
        ));

        self::assertSame([], $missing, 'every fallback currency has an exponent');
    }

    public function testItCoversTheMockedUpstreamTable(): void
    {
        foreach (array_keys(MockUpstream::RATES) as $code) {
            self::assertTrue(MinorUnits::isKnown($code), $code);
        }
    }

    public function testTheIso4217ExponentsFromThePlan(): void
    {
        $expected = [
            'JPY' => 0, 'CLP' => 0, 'KRW' => 0, 'PYG' => 0, 'VND' => 0, 'XAF' => 0, 'XOF' => 0,
            'ISK' => 0, 'UGX' => 0,
            'BRL' => 2, 'COP' => 2, 'USD' => 2, 'EUR' => 2, 'MXN' => 2, 'ARS' => 2, 'PEN' => 2,
            'IRR' => 2, 'IDR' => 2, 'XDR' => 2,
            'KWD' => 3, 'BHD' => 3, 'OMR' => 3, 'JOD' => 3, 'TND' => 3, 'LYD' => 3, 'IQD' => 3,
            'CLF' => 4,
            // Upstream's territory codes follow their parent currency.
            'FOK' => 2, 'GGP' => 2, 'IMP' => 2, 'JEP' => 2, 'KID' => 2, 'TVD' => 2,
            // The offshore yuan follows CNY; legacy codes keep their last ISO exponent (P1-10).
            'CNH' => 2, 'ANG' => 2, 'HRK' => 2, 'SLL' => 2, 'ZWL' => 2,
        ];
        foreach ($expected as $code => $exponent) {
            self::assertSame($exponent, MinorUnits::of($code), $code);
        }
    }

    public function testEveryExponentIsBetweenZeroAndFour(): void
    {
        foreach (MinorUnits::all() as $code => $exponent) {
            self::assertMatchesRegularExpression('/^[A-Z]{3}$/', $code);
            self::assertContains($exponent, [0, 2, 3, 4], $code);
        }
    }

    public function testAnUnknownCodeUsesTwoAndIsReported(): void
    {
        self::assertFalse(MinorUnits::isKnown('ZZZ'));
        self::assertSame(MinorUnits::DEFAULT, MinorUnits::of('ZZZ'));
        self::assertSame(2, MinorUnits::DEFAULT);
        self::assertFalse(MinorUnits::isKnown('brl'), 'codes are uppercase');
    }

    public function testTheTableIsSortedAndHasNoDuplicates(): void
    {
        $codes = array_keys(MinorUnits::all());
        $sorted = $codes;
        sort($sorted, SORT_STRING);

        self::assertSame($sorted, $codes);
        self::assertSame(array_unique($codes), $codes);
    }
}
