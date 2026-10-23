<?php

declare(strict_types=1);

namespace FxInstallments\Convert;

use FxInstallments\Rates\RateCache;
use FxInstallments\Rates\UpstreamError;
use FxInstallments\Validation\AmountValidator;
use FxInstallments\Validation\CurrencyValidator;
use FxInstallments\Validation\InstallmentsValidator;
use FxInstallments\Validation\UsdAmount;
use FxInstallments\Validation\ValidationFailed;

/**
 * The conversion behind `GET /convert` (docs/PLAN.md §1, §3a, §3b; ADRs 0006, 0011, 0012):
 * validate, look the rate up in the rate cache, compute locally, split and display.
 *
 * - **Inputs** go through the P1-03 validators. amount(), currency() and installments() take
 *   the raw `/convert` strings (strict: ADR 0013); null means the parameter was absent. The bot
 *   (P1-06) validates its own text and calls convert() with the results.
 * - **Rate**: RateCache (hit, miss, stale or blocked; it writes the `upstream.call` event). The
 *   float rate is the stored decimal's nearest float (ScaledRate::toRate()).
 * - **Raw numbers**, floats at full precision, never rounded: `converted = amount_usd × rate`,
 *   `installment_amount = converted / installments` (amount is the total, ADR 0006; floats
 *   by ADR 0011). They are double products (and a quotient) of the nearest doubles of the
 *   amount and the rate, not the nearest doubles of the exact results, so their last digits
 *   may differ from the exact decimal. Upstream's `conversion_result` is never used.
 * - **Display**, exact: InstallmentCalculator computes the minor-unit total from the cents
 *   and `rate_scaled` with integer arithmetic (owner decision 2026-10-21). A total of 2^53
 *   minor units or more is rejected here, before display(), with VALIDATION_AMOUNT
 *   (`too_large`; the user sees the generic amount text although the amount itself is valid —
 *   unreachable with real rates, §3a). A total below one minor unit per installment is
 *   VALIDATION_INSTALLMENTS.
 */
final class ConvertService
{
    public function __construct(
        private readonly AmountValidator $amounts,
        private readonly CurrencyValidator $currencies,
        private readonly InstallmentsValidator $installments,
        private readonly RateCache $rates,
    ) {
    }

    /**
     * @throws ValidationFailed VALIDATION_AMOUNT; reason `format` when absent
     */
    public function amount(?string $raw): UsdAmount
    {
        if ($raw === null) {
            throw ValidationFailed::amount(ValidationFailed::REASON_FORMAT);
        }

        return $this->amounts->validate($raw);
    }

    /**
     * @return string the uppercase code, on the allow-list
     *
     * @throws ValidationFailed VALIDATION_CURRENCY; reason `format` when absent
     */
    public function currency(?string $raw): string
    {
        if ($raw === null) {
            throw ValidationFailed::currency(ValidationFailed::REASON_FORMAT);
        }

        return $this->currencies->validate($raw);
    }

    /**
     * Absent means `installments.default`; present, it must be the strict `/convert` form
     * (InstallmentsValidator::fromQuery(): digits, no leading zero, nothing trimmed; an empty
     * value is `format`).
     *
     * @throws ValidationFailed VALIDATION_INSTALLMENTS
     */
    public function installments(?string $raw): int
    {
        if ($raw === null) {
            return $this->installments->validate($this->installments->defaultCount());
        }

        return $this->installments->fromQuery($raw);
    }

    /**
     * @param string $currency a code from currency() (or CurrencyValidator)
     *
     * @throws ValidationFailed VALIDATION_INSTALLMENTS out of range or below one minor unit per
     *         installment; VALIDATION_AMOUNT `too_large` at 2^53 minor units or more
     * @throws UpstreamError when no usable rate exists
     */
    public function convert(UsdAmount $amount, string $currency, int $installments): Conversion
    {
        $installments = $this->installments->validate($installments);
        $result = $this->rates->rate($currency);

        // The exact minor-unit total must stay below 2^53 (§3a). Checked here, before display(),
        // which treats a larger total as a programming error.
        if (InstallmentCalculator::minorUnitTotal($amount->cents, $result->rateScaled, $currency) === null) {
            throw ValidationFailed::amount(ValidationFailed::REASON_TOO_LARGE);
        }
        $display = InstallmentCalculator::display(
            $amount->cents,
            $result->rateScaled,
            $installments,
            $currency,
            $result->rateAsOf,
        );

        $rate = $result->rate();
        $converted = $amount->cents / 100 * $rate;

        return new Conversion(
            $amount,
            $currency,
            $rate,
            $converted,
            $installments,
            $converted / $installments,
            $result->rateAsOf,
            $result->stale,
            $display,
            $result->rateScaled,
        );
    }
}
