<?php

declare(strict_types=1);

namespace FxInstallments\Validation;

use FxInstallments\Config\ConfigRepository;
use FxInstallments\Convert\UsdCents;
use InvalidArgumentException;

/**
 * The strict amount validator (docs/PLAN.md §3, ADR 0013). `/convert` passes the raw query
 * value; the bot passes `AmountParser` output. Nothing is trimmed or cast first:
 *
 * 1. the raw string must match `^\d{1,7}(\.\d{1,2})?$` (ASCII digits, no trailing newline), which
 *    rejects `1e3`, `NaN`, `INF`, signs, spaces, commas and `200.505`;
 * 2. it becomes integer cents through `UsdCents` (never a float);
 * 3. cents must be > 0 and at most `amount.max` × 100.
 *
 * Leading zeros match the pattern (`007.5`, `0000000.5`) and are accepted; the canonical output
 * never has them, because it is rebuilt from the cents (`7.50`, `0.50`).
 */
final class AmountValidator
{
    public function __construct(private readonly ConfigRepository $config)
    {
    }

    /**
     * @throws ValidationFailed VALIDATION_AMOUNT, reason format, not_positive or over_max
     */
    public function validate(string $raw): UsdAmount
    {
        try {
            $cents = UsdCents::fromCanonical($raw);
        } catch (InvalidArgumentException) {
            throw ValidationFailed::amount(ValidationFailed::REASON_FORMAT);
        }

        if ($cents <= 0) {
            throw ValidationFailed::amount(ValidationFailed::REASON_NOT_POSITIVE);
        }
        if ($cents > $this->maxCents()) {
            throw ValidationFailed::amount(ValidationFailed::REASON_OVER_MAX);
        }

        return new UsdAmount($cents, UsdCents::toCanonical($cents));
    }

    /**
     * `amount.max` (whole USD) in cents.
     */
    public function maxCents(): int
    {
        return $this->config->int('amount.max') * 100;
    }
}
