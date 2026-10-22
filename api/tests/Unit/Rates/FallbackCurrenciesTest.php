<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Rates;

use FxInstallments\Convert\CurrencyCountryMap;
use FxInstallments\Rates\FallbackCurrencies;
use FxInstallments\Tests\Support\InMemoryCurrencyAllowList;
use FxInstallments\Tests\Support\MockUpstream;
use FxInstallments\Validation\CurrencySynonyms;
use PHPUnit\Framework\TestCase;

final class FallbackCurrenciesTest extends TestCase
{
    public function testEveryCodeIsThreeUppercaseLettersAndListedOnce(): void
    {
        $all = FallbackCurrencies::all();

        foreach ($all as $code) {
            self::assertMatchesRegularExpression('/^[A-Z]{3}$/D', $code);
        }
        self::assertSame(array_values(array_unique($all)), $all, 'no duplicates');
        self::assertSame(array_values(array_unique(FallbackCurrencies::ISO_4217)), FallbackCurrencies::ISO_4217);
        self::assertSame([], array_intersect(FallbackCurrencies::ISO_4217, FallbackCurrencies::UPSTREAM_ONLY));
    }

    public function testTheIsoListIsSortedSoAdditionsAreEasyToReview(): void
    {
        $sorted = FallbackCurrencies::ISO_4217;
        sort($sorted, SORT_STRING);

        self::assertSame($sorted, FallbackCurrencies::ISO_4217);
    }

    public function testItCoversEveryCurrencyTheAppMapsByName(): void
    {
        $codes = array_keys(CurrencySynonyms::BY_CURRENCY);
        foreach (CurrencyCountryMap::all() as $row) {
            $codes[] = $row['currency'];
        }
        foreach (array_unique([...$codes, ...InMemoryCurrencyAllowList::COMMON, ...array_keys(MockUpstream::RATES)]) as $code) {
            self::assertTrue(FallbackCurrencies::contains($code), $code);
        }
    }

    public function testItLeavesOutNonCurrencyCodes(): void
    {
        // ZWD and DEM: legacy codes upstream doesn't quote (HRK and ZWL moved in with P1-10).
        foreach (['XAU', 'XAG', 'XPD', 'XPT', 'XTS', 'XXX', 'XBA', 'XSU', 'XUA', 'BOV', 'USN', 'ZWD', 'DEM', 'usd', 'cnh', 'US', ''] as $code) {
            self::assertFalse(FallbackCurrencies::contains($code), $code);
        }
    }

    public function testTheCodesUpstreamQuotesOutsideIso4217AreUpstreamOnly(): void
    {
        // P1-10: the offshore yuan and the legacy codes upstream still quoted on 2026-10-22
        // (BGN moved from the ISO list by owner decision, round 2).
        foreach (['CNH', 'ANG', 'BGN', 'HRK', 'SLL', 'ZWL'] as $code) {
            self::assertContains($code, FallbackCurrencies::UPSTREAM_ONLY, $code);
            self::assertNotContains($code, FallbackCurrencies::ISO_4217, $code);
        }
        // Their replacements stay in the ISO list.
        foreach (['CNY', 'XCG', 'EUR', 'SLE', 'ZWG'] as $code) {
            self::assertContains($code, FallbackCurrencies::ISO_4217, $code);
        }
    }

    public function testSize(): void
    {
        // A sanity bound: ISO 4217 has about 155 active currency codes.
        self::assertGreaterThan(140, count(FallbackCurrencies::ISO_4217));
        self::assertLessThan(180, count(FallbackCurrencies::all()));
    }
}
