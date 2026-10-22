<?php

declare(strict_types=1);

namespace FxInstallments\Validation;

/**
 * What `InstallmentsValidator::read()` made of the bot user's text, in the same shape as
 * `AmountReading` and `CurrencyResolution`, so the flow engine handles all three alike.
 *
 * - Ok: `count` is set.
 * - Invalid: `error` is VALIDATION_INSTALLMENTS with reason `format` or `out_of_range`.
 *
 * There is no unclear outcome: a whole number is never ambiguous. Empty input and the "Just
 * one payment" button mean `installments.default`; that is the flow's choice (docs/PLAN.md §9),
 * made before the text gets here.
 */
final readonly class InstallmentsReading
{
    private function __construct(
        public Outcome $outcome,
        public ?int $count,
        public ?ValidationFailed $error,
    ) {
    }

    public static function ok(int $count): self
    {
        return new self(Outcome::Ok, $count, null);
    }

    public static function invalid(ValidationFailed $error): self
    {
        return new self(Outcome::Invalid, null, $error);
    }
}
