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
 * UYW). CLF and XDR stay: upstream quotes both.
 *
 * UPSTREAM_ONLY: codes ExchangeRate-API quotes that are not in ISO 4217 (territory currencies
 * pegged to a parent currency).
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
    public const UPSTREAM_ONLY = ['FOK', 'GGP', 'IMP', 'JEP', 'KID', 'TVD'];

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
