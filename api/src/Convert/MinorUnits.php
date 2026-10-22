<?php

declare(strict_types=1);

namespace FxInstallments\Convert;

use LogicException;

/**
 * ISO 4217 minor units (docs/PLAN.md §3a, ADR 0011, CONTEXT "minor unit"): how many decimals
 * each currency's smallest unit has. All splitting and display work in integer minor units.
 *
 * The table covers every code in `Rates\FallbackCurrencies` (a unit test checks it), and so
 * every code upstream quoted in a real `/latest/USD` answer on 2026-10-22
 * (tests/Acceptance/P1_10_LiveCodesTest.php). Sources and choices:
 * - The exponents are ISO 4217's ("minor unit" column), as of 2026. ISO's value is used even
 *   where everyday practice differs (IDR, HUF, IQD, LBP keep their ISO exponents).
 * - XDR has no minor unit in ISO 4217 ("N.A."): 2 by convention, like most currencies.
 * - Upstream's non-ISO territory codes take their parent currency's exponent: FOK (DKK), GGP,
 *   IMP, JEP (GBP), KID, TVD (AUD): all 2.
 * - CNH, the offshore yuan, is the same currency as CNY, so it takes CNY's exponent: 2.
 * - The legacy codes ANG, HRK, SLL and ZWL keep the exponent ISO 4217 gave them before their
 *   withdrawal: all 2.
 *
 * A code that isn't in the table (a code upstream starts quoting later) uses DEFAULT; callers
 * record `meta.minor_units_default=true` for it (isKnown()).
 */
final class MinorUnits
{
    public const DEFAULT = 2;

    /** @var array<string, int> code => exponent; every code not listed in EXCEPTIONS is 2 */
    private const EXCEPTIONS = [
        // 0 decimals
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0, 'KMF' => 0,
        'KRW' => 0, 'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'VND' => 0, 'VUV' => 0, 'XAF' => 0,
        'XOF' => 0, 'XPF' => 0,
        // 3 decimals
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
        // 4 decimals
        'CLF' => 4,
    ];

    /** @var list<string> codes with 2 decimals */
    private const TWO_DECIMALS = [
        'AED', 'AFN', 'ALL', 'AMD', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN',
        'BAM', 'BBD', 'BDT', 'BGN', 'BMD', 'BND', 'BOB', 'BRL', 'BSD', 'BTN', 'BWP', 'BYN', 'BZD',
        'CAD', 'CDF', 'CHF', 'CNY', 'COP', 'CRC', 'CUP', 'CVE', 'CZK',
        'DKK', 'DOP', 'DZD',
        'EGP', 'ERN', 'ETB', 'EUR',
        'FJD', 'FKP',
        'GBP', 'GEL', 'GHS', 'GIP', 'GMD', 'GTQ', 'GYD',
        'HKD', 'HNL', 'HTG', 'HUF',
        'IDR', 'ILS', 'INR', 'IRR',
        'JMD',
        'KES', 'KGS', 'KHR', 'KPW', 'KYD', 'KZT',
        'LAK', 'LBP', 'LKR', 'LRD', 'LSL',
        'MAD', 'MDL', 'MGA', 'MKD', 'MMK', 'MNT', 'MOP', 'MRU', 'MUR', 'MVR', 'MWK', 'MXN', 'MYR',
        'MZN',
        'NAD', 'NGN', 'NIO', 'NOK', 'NPR', 'NZD',
        'PAB', 'PEN', 'PGK', 'PHP', 'PKR', 'PLN',
        'QAR',
        'RON', 'RSD', 'RUB',
        'SAR', 'SBD', 'SCR', 'SDG', 'SEK', 'SGD', 'SHP', 'SLE', 'SOS', 'SRD', 'SSP', 'STN', 'SVC',
        'SYP', 'SZL',
        'THB', 'TJS', 'TMT', 'TOP', 'TRY', 'TTD', 'TWD', 'TZS',
        'UAH', 'USD', 'UYU', 'UZS',
        'VED', 'VES',
        'WST',
        'XCD', 'XCG', 'XDR',
        'YER',
        'ZAR', 'ZMW', 'ZWG',
        // Upstream's territory codes (parent currency's exponent)
        'FOK', 'GGP', 'IMP', 'JEP', 'KID', 'TVD',
        // The offshore yuan (CNY's exponent)
        'CNH',
        // Legacy codes upstream still quotes after their replacement (their last ISO exponent)
        'ANG', 'HRK', 'SLL', 'ZWL',
    ];

    /** @var array<string, int>|null */
    private static ?array $table = null;

    /**
     * The exponent for $code, or DEFAULT when the code isn't in the table.
     */
    public static function of(string $code): int
    {
        return self::table()[$code] ?? self::DEFAULT;
    }

    public static function isKnown(string $code): bool
    {
        return isset(self::table()[$code]);
    }

    /**
     * @return array<string, int> every listed code => its exponent, sorted by code
     */
    public static function all(): array
    {
        return self::table();
    }

    /**
     * @return array<string, int>
     */
    private static function table(): array
    {
        if (self::$table === null) {
            $twoDecimals = array_fill_keys(self::TWO_DECIMALS, 2);
            if (count($twoDecimals) !== count(self::TWO_DECIMALS) || array_intersect_key($twoDecimals, self::EXCEPTIONS) !== []) {
                throw new LogicException('A currency is listed twice in the minor-unit table');
            }
            $table = $twoDecimals + self::EXCEPTIONS;
            ksort($table, SORT_STRING);
            self::$table = $table;
        }

        return self::$table;
    }
}
