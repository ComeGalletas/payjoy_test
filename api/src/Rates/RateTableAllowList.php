<?php

declare(strict_types=1);

namespace FxInstallments\Rates;

use FxInstallments\Validation\CurrencyAllowList;

/**
 * The allow-list (docs/PLAN.md §3b, CONTEXT.md): the currency codes in the latest stored rate
 * table, whatever its age, or the fallback currency list while `rate_cache` is empty.
 *
 * The codes are read once per instance and reused, so one request sees one list. Input not on
 * it never reaches the rate cache or upstream.
 */
final class RateTableAllowList implements CurrencyAllowList
{
    /** @var array<string, true>|null */
    private ?array $codes = null;

    private bool $fallback = false;

    public function __construct(private readonly RateStore $store)
    {
    }

    public function contains(string $code): bool
    {
        return isset($this->codes()[$code]);
    }

    /**
     * Whether the list is the fallback currency list (the cache is empty).
     */
    public function usesFallback(): bool
    {
        $this->codes();

        return $this->fallback;
    }

    /**
     * @return array<string, true>
     */
    private function codes(): array
    {
        if ($this->codes === null) {
            $stored = $this->store->codes();
            $this->fallback = $stored === [];
            $this->codes = array_fill_keys($this->fallback ? FallbackCurrencies::all() : $stored, true);
        }

        return $this->codes;
    }
}
