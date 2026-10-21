<?php

declare(strict_types=1);

namespace FxInstallments\Validation;

/**
 * What `AmountParser::read()` made of the bot user's text.
 *
 * - Ok: `amount` is set.
 * - Unclear: `error` is VALIDATION_AMOUNT with reason `unclear`; the flow asks for a
 *   clarification (docs/PLAN.md §3c).
 * - Invalid: read, but the strict validator rejected it; `error` carries its reason
 *   (`not_positive`, `over_max`).
 */
final readonly class AmountReading
{
    private function __construct(
        public Outcome $outcome,
        public ?UsdAmount $amount,
        public ?ValidationFailed $error,
    ) {
    }

    public static function ok(UsdAmount $amount): self
    {
        return new self(Outcome::Ok, $amount, null);
    }

    public static function unclear(): self
    {
        return new self(Outcome::Unclear, null, ValidationFailed::amount(ValidationFailed::REASON_UNCLEAR));
    }

    public static function invalid(ValidationFailed $error): self
    {
        return new self(Outcome::Invalid, null, $error);
    }
}
