<?php

declare(strict_types=1);

namespace FxInstallments\Convert;

use FxInstallments\Validation\UsdAmount;

/**
 * One conversion (docs/PLAN.md §1): the full-precision numbers computed locally, `rate_as_of`,
 * `stale`, and the display block. Nothing here is rounded except the display strings.
 */
final readonly class Conversion
{
    public function __construct(
        public UsdAmount $amount,
        public string $currency,
        public int $rateScaled,
        public float $rate,
        public float $converted,
        public int $installments,
        public float $installmentAmount,
        public int $rateAsOf,
        public bool $stale,
        public Display $display,
    ) {
    }

    /**
     * The `/convert` response data, in the order of docs/PLAN.md §1. The raw numbers are JSON
     * numbers at full precision (`serialize_precision = -1`); `rate_as_of` is ISO 8601 UTC.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            // Exact: cents / 100 is the nearest double to the 2-decimal amount.
            'amount_usd' => $this->amount->cents / 100,
            'currency' => $this->currency,
            'rate' => $this->rate,
            'converted' => $this->converted,
            'installments' => $this->installments,
            'installment_amount' => $this->installmentAmount,
            'rate_as_of' => gmdate('Y-m-d\TH:i:s\Z', $this->rateAsOf),
            'stale' => $this->stale,
            'display' => $this->display->toArray(),
        ];
    }
}
