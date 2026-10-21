<?php

declare(strict_types=1);

namespace FxInstallments\Convert;

use InvalidArgumentException;

/**
 * Exchange rates stored as integers, `rate_scaled = round(rate × 10^10)` (ADR 0017,
 * CONTEXT "scaled rate"). P1-04 writes rate_cache with fromRate(); readers use toRate().
 *
 * Exactness: every accepted scaled value is below 2^53, so the float product `rate × 10^10`
 * is off by far less than 0.5 and round() gives the right integer, and toRate() divides two
 * exactly representable numbers. For a rate with at most ten decimals the round trip
 * toRate(fromRate($rate)) returns the same float.
 */
final class ScaledRate
{
    public const DECIMALS = 10;
    public const SCALE = 10_000_000_000;

    /** 2^53: above it not every integer is a float, and the exactness argument fails. */
    public const MAX_SCALED = 9_007_199_254_740_992;

    /**
     * @throws InvalidArgumentException for a rate that is not finite and positive, rounds to 0,
     *         or is too large to scale exactly (above about 900,719.92)
     */
    public static function fromRate(float $rate): int
    {
        if (!is_finite($rate) || $rate <= 0.0) {
            throw new InvalidArgumentException('A rate must be a finite positive number');
        }
        $scaled = round($rate * self::SCALE);
        if ($scaled < 1.0 || $scaled >= self::MAX_SCALED) {
            throw new InvalidArgumentException('The rate is outside the range the 10^10 scale stores exactly');
        }

        return (int) $scaled;
    }

    public static function toRate(int $scaled): float
    {
        if ($scaled < 1 || $scaled >= self::MAX_SCALED) {
            throw new InvalidArgumentException('A scaled rate must be positive and below 2^53');
        }

        return (float) $scaled / self::SCALE;
    }
}
