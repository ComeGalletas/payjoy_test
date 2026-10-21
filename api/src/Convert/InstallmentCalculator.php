<?php

declare(strict_types=1);

namespace FxInstallments\Convert;

use FxInstallments\Validation\ValidationFailed;
use InvalidArgumentException;

/**
 * Splitting and display (docs/PLAN.md §3a, ADR 0011): the only place that rounds money or
 * rates (§3 "Rounding"). Everything here works in integer minor units of the target currency
 * (MinorUnits); the strings are built from those integers, never by printing floats.
 *
 * - `total_m = (int) round(round(converted, e) × 10^e)`, half away from zero; the inner round
 *   absorbs `x.xx5` float drift (1.005 is stored as 1.00499999…).
 * - A total of 2^53 minor units or more is rejected (VALIDATION_AMOUNT, `too_large`): from
 *   there on a float no longer holds every integer, so the cast could be off. ConvertService
 *   checks it first (minorUnitTotal()); display() checks again so it can never cast an
 *   inexact value.
 * - `total_m < n` is rejected (VALIDATION_INSTALLMENTS, `below_minor_unit`): every installment
 *   is at least one minor unit.
 * - `base_m = intdiv(total_m, n)`, `last_m = base_m + total_m % n`: the final payment absorbs
 *   the remainder, so the displayed installments always sum to the displayed total, and none
 *   is zero or negative.
 */
final class InstallmentCalculator
{
    /** 2^53, the first integer a float can't tell from its neighbour. */
    public const EXACT_LIMIT = 9_007_199_254_740_992;

    /** display.rate keeps at most this many decimals. */
    public const RATE_DECIMALS = 6;

    /**
     * total_m before the int cast: round(round(converted, e) × 10^e), as a float, so a caller
     * can compare it with EXACT_LIMIT before display().
     *
     * @throws InvalidArgumentException for a negative or non-finite amount
     */
    public static function minorUnitTotal(float $converted, string $currency): float
    {
        if (!is_finite($converted) || $converted < 0.0) {
            throw new InvalidArgumentException('A converted amount must be finite and not negative');
        }
        $exponent = MinorUnits::of($currency);
        $total = round(round($converted, $exponent) * (10 ** $exponent));
        if ($total !== floor($total)) {
            // PHP's round() returns a value of 10^15 or more unchanged, and below 2^52 such a
            // float can still have a fraction (a multiple of 0.25 or 0.5). Round it half away
            // from zero here; adding 0.5 is exact at that size.
            $total = floor($total + 0.5);
        }

        return $total;
    }

    /**
     * Whether the converted total fits the exact integer range (below 2^53 minor units).
     */
    public static function fitsExactly(float $converted, string $currency): bool
    {
        return self::minorUnitTotal($converted, $currency) < (float) self::EXACT_LIMIT;
    }

    /**
     * The display block for $converted (in $currency) split into $installments payments.
     *
     * @param float $converted amount_usd × rate, full precision
     * @param int $rateScaled the stored rate (ADR 0017), for display.rate
     * @param int $amountUsdCents the total USD price in cents, for display.amount_usd
     * @param int $rateAsOf upstream's time_last_update_unix, for display.rate_as_of
     *
     * @throws ValidationFailed VALIDATION_AMOUNT (`too_large`) at 2^53 minor units or more;
     *         VALIDATION_INSTALLMENTS (`below_minor_unit`) when the total has fewer minor units
     *         than installments
     * @throws InvalidArgumentException for out-of-range arguments (callers validate first)
     */
    public static function display(
        float $converted,
        int $installments,
        string $currency,
        int $rateScaled,
        int $amountUsdCents,
        int $rateAsOf,
    ): Display {
        if ($installments < 1) {
            throw new InvalidArgumentException('At least one installment');
        }
        $exponent = MinorUnits::of($currency);
        $total = self::minorUnitTotal($converted, $currency);
        if ($total >= (float) self::EXACT_LIMIT) {
            throw ValidationFailed::amount(ValidationFailed::REASON_TOO_LARGE);
        }
        $totalMinor = (int) $total;
        if ($totalMinor < $installments) {
            throw ValidationFailed::installments(ValidationFailed::REASON_BELOW_MINOR_UNIT);
        }

        $baseMinor = intdiv($totalMinor, $installments);
        $lastMinor = $baseMinor + $totalMinor % $installments;
        $even = $lastMinor === $baseMinor;

        return new Display(
            UsdCents::toCanonical($amountUsdCents),
            self::formatRate($rateScaled),
            self::formatMinor($totalMinor, $exponent),
            self::formatMinor($baseMinor, $exponent),
            self::formatMinor($lastMinor, $exponent),
            $even ? $installments : $installments - 1,
            $even,
            gmdate('Y-m-d H:i', $rateAsOf) . ' UTC',
            $exponent,
            $totalMinor,
            $baseMinor,
            $lastMinor,
        );
    }

    /**
     * $minor minor units with $exponent decimals, English format: `,` thousands grouping, `.`
     * decimal point, no decimal point when $exponent is 0 (104582, 2 → `1,045.82`).
     */
    public static function formatMinor(int $minor, int $exponent): string
    {
        if ($minor < 0 || $exponent < 0) {
            throw new InvalidArgumentException('Minor units and exponents are not negative');
        }
        if ($exponent === 0) {
            return self::group($minor);
        }
        $scale = 10 ** $exponent;

        return self::group(intdiv($minor, $scale)) . '.' . str_pad((string) ($minor % $scale), $exponent, '0', STR_PAD_LEFT);
    }

    /**
     * The stored rate rounded half away from zero to RATE_DECIMALS decimals, trailing zeros
     * trimmed, integer part grouped (52291000000 → `5.2291`, 17319368619710000 →
     * `1,731,936.861971`). Integer arithmetic on rate_scaled only.
     */
    public static function formatRate(int $rateScaled): string
    {
        if ($rateScaled < 1) {
            throw new InvalidArgumentException('A scaled rate is positive');
        }
        $divisor = 10 ** (ScaledRate::DECIMALS - self::RATE_DECIMALS);
        $rounded = intdiv($rateScaled + intdiv($divisor, 2), $divisor);
        $scale = 10 ** self::RATE_DECIMALS;
        $fraction = rtrim(str_pad((string) ($rounded % $scale), self::RATE_DECIMALS, '0', STR_PAD_LEFT), '0');
        $whole = self::group(intdiv($rounded, $scale));

        return $fraction === '' ? $whole : "{$whole}.{$fraction}";
    }

    /**
     * A non-negative integer with `,` between groups of three digits.
     */
    private static function group(int $value): string
    {
        $digits = (string) $value;
        $head = strlen($digits) % 3 ?: 3;
        $grouped = substr($digits, 0, $head);
        for ($i = $head, $length = strlen($digits); $i < $length; $i += 3) {
            $grouped .= ',' . substr($digits, $i, 3);
        }

        return $grouped;
    }
}
