<?php

declare(strict_types=1);

namespace FxInstallments\Rates;

/**
 * Upstream's `/quota` answer, for the admin health view (P1-08).
 */
final class Quota
{
    public function __construct(
        public readonly int $planQuota,
        public readonly int $requestsRemaining,
        public readonly int $refreshDayOfMonth,
    ) {
    }
}
