<?php

declare(strict_types=1);

namespace FxInstallments\Validation;

/**
 * The allow-list (docs/PLAN.md §3b): the currency codes in the latest stored rate table, or
 * the fallback currency list when the cache is empty. P1-04 provides the real implementation;
 * the validators only depend on this interface.
 */
interface CurrencyAllowList
{
    /**
     * @param string $code an uppercase 3-letter code
     */
    public function contains(string $code): bool;
}
