<?php

declare(strict_types=1);

namespace FxInstallments\Validation;

/**
 * What `CurrencyResolver::resolve()` made of the bot user's text.
 *
 * - Ok: `currency` is an allowed code; `country` is the canonical English country name only
 *   when the user typed a country (`brazil` → `Brazil`), otherwise null (`BRL`, `reais`, `R$`).
 * - Unclear: the text names more than one currency (`$`, `peso`, `dollar`); `error` is
 *   VALIDATION_CURRENCY with reason `ambiguous`.
 * - Invalid: `error` is VALIDATION_CURRENCY with reason `format`, `unknown` or `not_allowed`.
 */
final readonly class CurrencyResolution
{
    private function __construct(
        public Outcome $outcome,
        public ?string $currency,
        public ?string $country,
        public ?ValidationFailed $error,
    ) {
    }

    public static function resolved(string $currency, ?string $country): self
    {
        return new self(Outcome::Ok, $currency, $country, null);
    }

    public static function unclear(): self
    {
        return new self(Outcome::Unclear, null, null, ValidationFailed::currency(ValidationFailed::REASON_AMBIGUOUS));
    }

    public static function invalid(ValidationFailed $error): self
    {
        return new self(Outcome::Invalid, null, null, $error);
    }
}
