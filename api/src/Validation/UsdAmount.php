<?php

declare(strict_types=1);

namespace FxInstallments\Validation;

/**
 * A validated amount: the total USD price (ADR 0006) as exact integer cents (ADR 0017) and its
 * canonical amount string with exactly two decimals and no leading zeros (`200.50`, `7.50`).
 */
final readonly class UsdAmount
{
    public function __construct(public int $cents, public string $canonical)
    {
    }
}
