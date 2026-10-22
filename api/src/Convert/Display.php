<?php

declare(strict_types=1);

namespace FxInstallments\Convert;

/**
 * The display block (CONTEXT "display block"): the English-formatted strings `/convert` returns
 * for presentation, built only by InstallmentCalculator::display(). Front-ends show them
 * verbatim.
 *
 * The integer minor-unit amounts behind the strings are kept too (`totalMinor`,
 * `installmentMinor`, `lastInstallmentMinor`, `exponent`), for callers and tests; toArray()
 * holds only the documented fields (docs/PLAN.md §1).
 */
final readonly class Display
{
    public function __construct(
        public string $amountUsd,
        public string $rate,
        public string $converted,
        public string $installment,
        public string $lastInstallment,
        public int $regularCount,
        public bool $even,
        public string $rateAsOf,
        public int $exponent,
        public int $totalMinor,
        public int $installmentMinor,
        public int $lastInstallmentMinor,
    ) {
    }

    /**
     * @return array{amount_usd: string, rate: string, converted: string, installment: string,
     *               last_installment: string, regular_count: int, even: bool, rate_as_of: string}
     */
    public function toArray(): array
    {
        return [
            'amount_usd' => $this->amountUsd,
            'rate' => $this->rate,
            'converted' => $this->converted,
            'installment' => $this->installment,
            'last_installment' => $this->lastInstallment,
            'regular_count' => $this->regularCount,
            'even' => $this->even,
            'rate_as_of' => $this->rateAsOf,
        ];
    }
}
