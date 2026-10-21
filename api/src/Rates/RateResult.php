<?php

declare(strict_types=1);

namespace FxInstallments\Rates;

use FxInstallments\Convert\ScaledRate;

/**
 * One USD → currency rate, as `/convert` uses it (P1-05): the scaled rate, `rate_as_of`
 * (upstream's `time_last_update_unix`) and whether it was served stale (CONTEXT.md).
 */
final class RateResult
{
    public function __construct(
        public readonly string $currency,
        public readonly int $rateScaled,
        public readonly int $rateAsOf,
        public readonly bool $stale,
        public readonly int $fetchedAt,
    ) {
    }

    /**
     * The rate as a float for the calculation (ADR 0011); see ScaledRate::toRate().
     */
    public function rate(): float
    {
        return ScaledRate::toRate($this->rateScaled);
    }
}
