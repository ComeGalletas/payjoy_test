<?php

declare(strict_types=1);

namespace FxInstallments\Convert;

use FxInstallments\Validation\ValidationFailed;
use InvalidArgumentException;

/**
 * Splitting and display (docs/PLAN.md §3a, ADR 0011; exact total by owner decision
 * 2026-10-21, P1-05 round 2): the only place that rounds money or rates (§3 "Rounding").
 * Everything here works in integer minor units of the target currency (MinorUnits), and the
 * strings are built from those integers, never by printing floats.
 *
 * - **Exact total.** `total_m = round_half_away_from_zero(cents × rate_scaled × 10^e / 10^12)`,
 *   computed from the canonical amount's cents (ADR 0017) and the stored `rate_scaled` with
 *   integer arithmetic only: no float, no bcmath. `cents × rate_scaled` can pass 2^63, so
 *   rate_scaled is split into 10^9 limbs and the division carries its remainder
 *   (minorUnitTotal()). The display is therefore exact for the stored rate, while the raw
 *   `converted` and `installment_amount` in the response stay the nearest floats (ADR 0011).
 * - A total of 2^53 minor units or more is too large (VALIDATION_AMOUNT, `too_large`):
 *   ConvertService checks minorUnitTotal() before display(), and display() refuses such a
 *   total as a programming error (InvalidArgumentException), so the check can't silently
 *   move.
 * - `total_m < n` is rejected (VALIDATION_INSTALLMENTS, `below_minor_unit`): every installment
 *   is at least one minor unit.
 * - `base_m = intdiv(total_m, n)`, `last_m = base_m + total_m % n`: the final payment absorbs
 *   the remainder, so the displayed installments always sum to the displayed total, and none
 *   is zero or negative.
 */
final class InstallmentCalculator
{
    /** 2^53: totals must stay below it (docs/PLAN.md §3a). */
    public const EXACT_LIMIT = 9_007_199_254_740_992;

    /** display.rate keeps at most this many decimals. */
    public const RATE_DECIMALS = 6;

    /**
     * The largest minor-unit exponent exactTotal() accepts: the largest in MinorUnits (CLF).
     * L-04 cleanup B narrowed it from 12, so no exponent outside the ones in use can reach the
     * arithmetic; a currency with more decimals needs this raised, with its tests.
     */
    public const MAX_EXPONENT = 4;

    /** cents (10^2) × rate_scaled (10^10): the product's scale is 10^12. */
    private const PRODUCT_DECIMALS = 12;

    private const LIMB = 1_000_000_000;

    /**
     * The exact total in minor units, `round_half_away_from_zero(cents × rate_scaled × 10^e /
     * 10^12)`, or null when it is 2^53 or more (including any product too large for the
     * limbed arithmetic, which is far above that).
     *
     * @throws InvalidArgumentException for negative cents, a rate outside ScaledRate's range,
     *         or an exponent outside 0..MAX_EXPONENT (4)
     */
    public static function minorUnitTotal(int $cents, int $rateScaled, string $currency): ?int
    {
        return self::exactTotal($cents, $rateScaled, MinorUnits::of($currency));
    }

    /**
     * @see minorUnitTotal(), with the exponent given directly
     */
    public static function exactTotal(int $cents, int $rateScaled, int $exponent): ?int
    {
        // Canonical amounts have at most 7 + 2 digits (UsdCents), so cents < 10^9.
        if ($cents < 0 || $cents >= self::LIMB || $rateScaled < 1 || $rateScaled >= ScaledRate::MAX_SCALED) {
            throw new InvalidArgumentException('Cents are in 0..10^9 − 1 and a scaled rate is in range');
        }
        if ($exponent < 0 || $exponent > self::MAX_EXPONENT) {
            throw new InvalidArgumentException('An exponent is between 0 and ' . self::MAX_EXPONENT);
        }

        // rate_scaled = hi × 10^9 + lo, so cents × rate_scaled = high × 10^9 + low, low < 10^9.
        $hi = intdiv($rateScaled, self::LIMB);
        $lo = $rateScaled % self::LIMB;
        if ($hi > 0 && $cents > intdiv(PHP_INT_MAX - self::LIMB, $hi)) {
            // high would pass PHP_INT_MAX − 10^9 ≈ 9.2 × 10^18, so the product is at least
            // 9.2 × 10^27 at scale 10^12: 9.2 × 10^15 whole units, past 2^53 for any e ≥ 0.
            // (Unreachable with real inputs: cents < 10^9 and hi < 9 × 10^9 keep it below.)
            return null;
        }
        $low = $cents * $lo; // < 10^9 × 10^9
        $high = $cents * $hi + intdiv($low, self::LIMB); // fits: checked above
        $low %= self::LIMB;

        // Divide high × 10^9 + low by 10^k, k = 12 − e.
        $k = self::PRODUCT_DECIMALS - $exponent;
        if ($k >= 9) {
            $split = 10 ** ($k - 9);
            $quotient = intdiv($high, $split);
            $remainder = ($high % $split) * self::LIMB + $low; // < 10^k ≤ 10^12
        } else {
            $multiplier = 10 ** (9 - $k);
            if ($high >= intdiv(self::EXACT_LIMIT, $multiplier) + 1) {
                return null; // quotient ≥ high × multiplier > 2^53
            }
            $split = 10 ** $k;
            $quotient = $high * $multiplier + intdiv($low, $split);
            $remainder = $low % $split;
        }
        if (2 * $remainder >= 10 ** $k) {
            $quotient++; // half away from zero (all values are positive)
        }

        return $quotient >= self::EXACT_LIMIT ? null : $quotient;
    }

    /**
     * The display block for $amountUsdCents converted at $rateScaled into $currency, split into
     * $installments payments.
     *
     * @param int $amountUsdCents the total USD price in cents (ADR 0006, 0017)
     * @param int $rateScaled the stored rate (ADR 0017)
     * @param int $rateAsOf upstream's time_last_update_unix, for display.rate_as_of
     *
     * @throws ValidationFailed VALIDATION_INSTALLMENTS (`below_minor_unit`) when the total has
     *         fewer minor units than installments
     * @throws InvalidArgumentException for out-of-range arguments, including a total of 2^53
     *         minor units or more (callers check minorUnitTotal() first)
     */
    public static function display(
        int $amountUsdCents,
        int $rateScaled,
        int $installments,
        string $currency,
        int $rateAsOf,
    ): Display {
        if ($installments < 1) {
            throw new InvalidArgumentException('At least one installment');
        }
        $exponent = MinorUnits::of($currency);
        $totalMinor = self::exactTotal($amountUsdCents, $rateScaled, $exponent);
        if ($totalMinor === null) {
            throw new InvalidArgumentException('The total is 2^53 minor units or more; check minorUnitTotal() first');
        }
        if ($totalMinor < $installments) {
            throw ValidationFailed::installments(ValidationFailed::REASON_BELOW_MINOR_UNIT);
        }

        $baseMinor = intdiv($totalMinor, $installments);
        $lastMinor = $baseMinor + $totalMinor % $installments;
        $even = $lastMinor === $baseMinor;

        return new Display(
            self::formatMinor($amountUsdCents, 2),
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
