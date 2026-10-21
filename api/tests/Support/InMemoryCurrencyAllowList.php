<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Support;

use FxInstallments\Validation\CurrencyAllowList;

/**
 * A fixed allow-list for tests; P1-04 provides the real one from the rate table.
 */
final class InMemoryCurrencyAllowList implements CurrencyAllowList
{
    /** Every code the synonym table and the currency-country map point to. */
    public const COMMON = ['ARS', 'BRL', 'CLF', 'CLP', 'COP', 'EUR', 'GBP', 'JPY', 'KWD', 'MXN', 'PEN', 'USD'];

    /** @var array<string, true> */
    private readonly array $codes;

    /**
     * @param list<string> $codes
     */
    public function __construct(array $codes = self::COMMON)
    {
        $this->codes = array_fill_keys($codes, true);
    }

    public function contains(string $code): bool
    {
        return isset($this->codes[$code]);
    }
}
