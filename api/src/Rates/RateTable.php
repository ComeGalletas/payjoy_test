<?php

declare(strict_types=1);

namespace FxInstallments\Rates;

/**
 * The stored rate table: every row of `rate_cache`, written together by one refresh.
 *
 * The times are the smallest over the rows (they are all equal unless the table was edited by
 * hand), so a mixed table is treated as its oldest part.
 */
final class RateTable
{
    /**
     * @param array<string, int> $rates code => rate_scaled
     */
    public function __construct(
        public readonly array $rates,
        public readonly int $lastUpdateUnix,
        public readonly int $nextUpdateUnix,
        public readonly int $fetchedAt,
    ) {
    }

    /**
     * Seconds since upstream last updated the rates (the stale-age basis).
     */
    public function ageAt(int $now): int
    {
        return $now - $this->lastUpdateUnix;
    }
}
