<?php

declare(strict_types=1);

namespace FxInstallments\Rates;

/**
 * The fallback currency list (CONTEXT.md): the codes the allow-list uses while `rate_cache`
 * is empty (a cold start), so a user still gets VALIDATION_CURRENCY for a code that isn't a
 * currency, and an upstream error only when upstream is the problem (docs/PLAN.md §3b).
 *
 * ISO_4217: the active ISO 4217 codes that name a currency, as of 2026 (`BGN` kept while
 * upstream may still publish it after the 2026 euro change). Left out: precious metals (XAU,
 * XAG, XPD, XPT), bond-market units (XBA to XBD), XSU, XUA, the test and no-currency codes
 * (XTS, XXX), and the fund codes upstream doesn't quote (BOV, CHE, CHW, COU, MXV, USN, UYI,
 * UYW). CLF and XDR stay: upstream quotes both. KPW, SVC and VED stay although upstream didn't
 * quote them on 2026-10-22: on a cold start they pass validation, and once a refresh has stored
 * the table they answer `not_in_table` like any code the table lacks (ADR 0012 amendment item 9).
 *
 * UPSTREAM_ONLY: codes ExchangeRate-API quotes that are not active ISO 4217 codes: territory
 * currencies pegged to a parent currency, the offshore yuan, and legacy codes upstream still
 * quotes after their replacement.
 *
 * Both lists were compared with the codes of a real `/latest/USD` answer on 2026-10-22 (P1-10):
 * every code upstream quoted is here. tests/Acceptance/P1_10_LiveCodesTest.php pins that, with
 * the captured codes in tests/Fixtures/upstream_latest_usd_codes.txt.
 *
 * Once a refresh has stored a table, its codes are the allow-list instead (RateTableAllowList).
 */
final class FallbackCurrencies
{
    /** @var list<string> */
    public const ISO_4217 = [
        'AED', 'AFN', 'ALL', 'AMD', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN',
        'BAM', 'BBD', 'BDT', 'BGN', 'BHD', 'BIF', 'BMD', 'BND', 'BOB', 'BRL', 'BSD', 'BTN', 'BWP',
        'BYN', 'BZD',
        'CAD', 'CDF', 'CHF', 'CLF', 'CLP', 'CNY', 'COP', 'CRC', 'CUP', 'CVE', 'CZK',
        'DJF', 'DKK', 'DOP', 'DZD',
        'EGP', 'ERN', 'ETB', 'EUR',
        'FJD', 'FKP',
        'GBP', 'GEL', 'GHS', 'GIP', 'GMD', 'GNF', 'GTQ', 'GYD',
        'HKD', 'HNL', 'HTG', 'HUF',
        'IDR', 'ILS', 'INR', 'IQD', 'IRR', 'ISK',
        'JMD', 'JOD', 'JPY',
        'KES', 'KGS', 'KHR', 'KMF', 'KPW', 'KRW', 'KWD', 'KYD', 'KZT',
        'LAK', 'LBP', 'LKR', 'LRD', 'LSL', 'LYD',
        'MAD', 'MDL', 'MGA', 'MKD', 'MMK', 'MNT', 'MOP', 'MRU', 'MUR', 'MVR', 'MWK', 'MXN', 'MYR',
        'MZN',
        'NAD', 'NGN', 'NIO', 'NOK', 'NPR', 'NZD',
        'OMR',
        'PAB', 'PEN', 'PGK', 'PHP', 'PKR', 'PLN', 'PYG',
        'QAR',
        'RON', 'RSD', 'RUB', 'RWF',
        'SAR', 'SBD', 'SCR', 'SDG', 'SEK', 'SGD', 'SHP', 'SLE', 'SOS', 'SRD', 'SSP', 'STN', 'SVC',
        'SYP', 'SZL',
        'THB', 'TJS', 'TMT', 'TND', 'TOP', 'TRY', 'TTD', 'TWD', 'TZS',
        'UAH', 'UGX', 'USD', 'UYU', 'UZS',
        'VED', 'VES', 'VND', 'VUV',
        'WST',
        'XAF', 'XCD', 'XCG', 'XDR', 'XOF', 'XPF',
        'YER',
        'ZAR', 'ZMW', 'ZWG',
    ];

    /** @var list<string> */
    public const UPSTREAM_ONLY = [
        // Territory currencies pegged to a parent currency
        'FOK', // Faroese krona (DKK)
        'GGP', // Guernsey pound (GBP)
        'IMP', // Manx pound (GBP)
        'JEP', // Jersey pound (GBP)
        'KID', // Kiribati dollar (AUD)
        'TVD', // Tuvaluan dollar (AUD)
        // Offshore market rate
        'CNH', // offshore yuan: the renminbi traded outside mainland China; ISO 4217 has only CNY
        // Legacy codes upstream still quotes after their replacement
        'ANG', // Netherlands Antillean guilder, replaced by XCG in 2025
        'HRK', // Croatian kuna, replaced by EUR in 2023
        'SLL', // Sierra Leonean leone, redenominated as SLE in 2022
        'ZWL', // Zimbabwean dollar, replaced by ZWG in 2024
    ];

    /** @var array<string, true>|null */
    private static ?array $set = null;

    /**
     * @return list<string> every fallback code, sorted
     */
    public static function all(): array
    {
        $all = array_merge(self::ISO_4217, self::UPSTREAM_ONLY);
        sort($all, SORT_STRING);

        return $all;
    }

    public static function contains(string $code): bool
    {
        self::$set ??= array_fill_keys(self::all(), true);

        return isset(self::$set[$code]);
    }
}
