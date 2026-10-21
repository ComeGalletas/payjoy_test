<?php

declare(strict_types=1);

namespace FxInstallments\Rates;

/**
 * One validated `/latest/USD` table: every kept rate as a scaled rate (ADR 0017), upstream's
 * update times, and the currencies that were dropped.
 *
 * A dropped entry names the currency only when its key was a valid 3-letter code; otherwise
 * `currency` is null, so no upstream text reaches an event.
 */
final class LatestRates
{
    /**
     * @param array<string, int> $rates code => rate_scaled, never empty
     * @param list<array{currency: string|null, reason: string}> $dropped
     */
    public function __construct(
        public readonly int $lastUpdateUnix,
        public readonly int $nextUpdateUnix,
        public readonly array $rates,
        public readonly array $dropped = [],
    ) {
    }
}
