<?php

declare(strict_types=1);

namespace FxInstallments\Convert;

use InvalidArgumentException;

/**
 * Exchange rates stored as integers, `rate_scaled` = the rate rounded to ten decimals, times
 * 10^10 (ADR 0017, CONTEXT "scaled rate"). P1-04 writes rate_cache with fromDecimalString()
 * on upstream's decimal text; readers use toRate().
 *
 * How the value is built: from the rate's decimal text, with integer and string arithmetic
 * only. No float is ever multiplied by 10^10, because that product is not exact: for
 * 439537.6134 it is 4395376133999999.5, and above 10^15 PHP's round() leaves it unchanged, so
 * a float route would store 4395376133999999 instead of 4395376134000000.
 *
 * - fromDecimalString() takes the text as given (`5.2291`, `89500`, `1.234E-5`). The digits
 *   beyond the tenth decimal are rounded half away from zero on the eleventh digit, so the
 *   result is exactly round(decimal × 10^10) for the decimal the text denotes.
 * - fromRate() takes a float and uses its shortest round-trip decimal text, the one PHP prints
 *   with `serialize_precision = -1` (`var_export(5.2291)` is `5.2291`). For a float parsed
 *   from upstream's JSON, that text is upstream's number whenever upstream sent 15 or fewer
 *   significant digits: every decimal with at most 15 significant digits round-trips through
 *   a double, while some with 16 or 17 come back as a different, shorter text. P1-04 passes
 *   upstream's own text to fromDecimalString() instead (Rates/ConversionRatesScanner).
 * - Range: positive, at least one unit at the tenth decimal after rounding (≥ 0.00000000005),
 *   and below MAX_RATE (900,000,000), so the scaled value is below MAX_SCALED (9 × 10^18) and
 *   fits a signed 64-bit integer (PHP int, Postgres BIGINT) with margin.
 *
 * toRate() is `scaled / 10^10` as a float, because the calculation is float by design
 * (ADR 0011). It is the float nearest to the stored decimal while the scaled value is below
 * 2^53 (rates below about 900,719.92); above that the int-to-float conversion itself rounds,
 * so the result is only approximate (relative error below 2^-52).
 */
final class ScaledRate
{
    public const DECIMALS = 10;
    public const SCALE = 10_000_000_000;

    /** Rates must be below this (exclusive). */
    public const MAX_RATE = 900_000_000;

    /** MAX_RATE × SCALE: every stored rate_scaled is below this, under PHP_INT_MAX (≈ 9.22 × 10^18). */
    public const MAX_SCALED = 9_000_000_000_000_000_000;

    /** An optional exponent form (`1.234E-5`, `5.0E-11`, `1.0E+25`), as var_export prints. */
    private const DECIMAL_PATTERN = '/^(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d{1,4}))?$/D';

    /**
     * @throws InvalidArgumentException for a rate that is not finite and positive, rounds to 0
     *         at ten decimals, or is MAX_RATE or more
     */
    public static function fromRate(float $rate): int
    {
        if (!is_finite($rate) || $rate <= 0.0) {
            throw new InvalidArgumentException('A rate must be a finite positive number');
        }

        return self::fromDecimalString(self::shortestDecimal($rate));
    }

    /**
     * @param string $rate a non-negative decimal: digits, an optional fraction, an optional
     *        exponent (`5.2291`, `89500`, `1.234E-5`); no sign, spaces or grouping
     *
     * @throws InvalidArgumentException for anything else, or a value that rounds to 0 at ten
     *         decimals, or one that is MAX_RATE or more
     */
    public static function fromDecimalString(string $rate): int
    {
        if (preg_match(self::DECIMAL_PATTERN, $rate, $match) !== 1) {
            throw new InvalidArgumentException('Not a decimal rate string');
        }
        $digits = $match[1] . ($match[2] ?? '');
        // The decimal point sits after $point digits of $digits (it may be outside them).
        $point = strlen($match[1]) + (int) ($match[3] ?? '0');

        // Drop leading zeros so $point counts significant integer digits.
        $trimmed = ltrim($digits, '0');
        $point -= strlen($digits) - strlen($trimmed);
        $digits = $trimmed;
        if ($digits === '') {
            throw new InvalidArgumentException('A rate must be positive');
        }
        if ($point > strlen((string) self::MAX_RATE)) {
            throw new InvalidArgumentException('The rate must be below ' . self::MAX_RATE);
        }
        if ($point < -self::DECIMALS) {
            // The first eleven decimals are all zero: it rounds to 0.
            throw new InvalidArgumentException('The rate rounds to 0 at ten decimals');
        }

        if ($point <= 0) {
            $integer = '0';
            $fraction = str_repeat('0', -$point) . $digits;
        } elseif ($point >= strlen($digits)) {
            $integer = $digits . str_repeat('0', $point - strlen($digits));
            $fraction = '';
        } else {
            $integer = substr($digits, 0, $point);
            $fraction = substr($digits, $point);
        }

        // Ten decimals, plus the eleventh for rounding half away from zero.
        $fraction = str_pad(substr($fraction, 0, self::DECIMALS + 1), self::DECIMALS + 1, '0');
        $whole = (int) $integer; // at most 9 digits
        $tenths = (int) substr($fraction, 0, self::DECIMALS);
        if ($fraction[self::DECIMALS] >= '5') {
            $tenths++;
            if ($tenths === self::SCALE) {
                $tenths = 0;
                $whole++;
            }
        }

        if ($whole >= self::MAX_RATE) {
            throw new InvalidArgumentException('The rate must be below ' . self::MAX_RATE);
        }
        // $whole < MAX_RATE, so this stays below MAX_SCALED and never overflows.
        $scaled = $whole * self::SCALE + $tenths;
        if ($scaled < 1) {
            throw new InvalidArgumentException('The rate rounds to 0 at ten decimals');
        }

        return $scaled;
    }

    /**
     * The stored rate as a float: the nearest float while $scaled < 2^53, approximate above.
     */
    public static function toRate(int $scaled): float
    {
        if ($scaled < 1 || $scaled >= self::MAX_SCALED) {
            throw new InvalidArgumentException('A scaled rate must be positive and below MAX_SCALED');
        }

        return (float) $scaled / self::SCALE;
    }

    /**
     * The float's shortest round-trip decimal text, as PHP prints it with
     * serialize_precision = -1, whatever the current setting is.
     */
    private static function shortestDecimal(float $rate): string
    {
        $previous = ini_set('serialize_precision', '-1');
        try {
            return var_export($rate, true);
        } finally {
            if ($previous !== false) {
                ini_set('serialize_precision', $previous);
            }
        }
    }
}
