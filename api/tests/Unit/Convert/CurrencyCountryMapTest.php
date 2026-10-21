<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Convert;

use FxInstallments\Convert\CurrencyCountryMap;
use PHPUnit\Framework\TestCase;

final class CurrencyCountryMapTest extends TestCase
{
    public function testEveryRowHasACodeAndItsOwnEnglishName(): void
    {
        $rows = CurrencyCountryMap::all();
        self::assertNotEmpty($rows);

        foreach ($rows as $country => $row) {
            self::assertMatchesRegularExpression('/^[A-Z]{3}$/D', $row['currency'], $country);
            self::assertContains($country, $row['names'], "{$country} lists its canonical name");
            self::assertSame($row['currency'], CurrencyCountryMap::currencyOf($country));
        }
    }

    public function testTheButtonCurrenciesAndRequiredCountriesAreCovered(): void
    {
        self::assertSame('BRL', CurrencyCountryMap::currencyOf('Brazil'));
        self::assertSame('COP', CurrencyCountryMap::currencyOf('Colombia'));
        self::assertSame('MXN', CurrencyCountryMap::currencyOf('Mexico'));
        self::assertSame('EUR', CurrencyCountryMap::currencyOf('Spain'));
        self::assertSame('USD', CurrencyCountryMap::currencyOf('United States'));
        self::assertSame('JPY', CurrencyCountryMap::currencyOf('Japan'));
        self::assertSame('KWD', CurrencyCountryMap::currencyOf('Kuwait'));
        self::assertNull(CurrencyCountryMap::currencyOf('Atlantis'));
        self::assertNull(CurrencyCountryMap::currencyOf('brazil'), 'lookups by canonical name are exact');
    }

    public function testEveryEuroAreaMemberMapsToEur(): void
    {
        $euroArea = ['Austria', 'Belgium', 'Bulgaria', 'Croatia', 'Cyprus', 'Estonia', 'Finland', 'France', 'Germany',
            'Greece', 'Ireland', 'Italy', 'Latvia', 'Lithuania', 'Luxembourg', 'Malta', 'Netherlands', 'Portugal',
            'Slovakia', 'Slovenia', 'Spain'];

        foreach ($euroArea as $country) {
            self::assertSame('EUR', CurrencyCountryMap::currencyOf($country), $country);
        }
    }
}
