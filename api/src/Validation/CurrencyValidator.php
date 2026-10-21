<?php

declare(strict_types=1);

namespace FxInstallments\Validation;

/**
 * The currency validator (docs/PLAN.md §3): trim, uppercase, `^[A-Z]{3}$`, then the injected
 * allow-list. Used by `/convert` for `cy` and by `CurrencyResolver` for every code it maps to.
 *
 * Trimming covers ASCII space, tab, CR, LF and vertical tab only, never NUL, so `USD\0` is
 * rejected. Uppercasing is ASCII-only (`strtoupper` is locale-independent since PHP 8.2).
 */
final class CurrencyValidator
{
    private const TRIM = " \t\r\n\v";

    public function __construct(private readonly CurrencyAllowList $allowList)
    {
    }

    /**
     * @return string the uppercase code
     *
     * @throws ValidationFailed VALIDATION_CURRENCY, reason format or not_allowed
     */
    public function validate(string $raw): string
    {
        $code = strtoupper(trim($raw, self::TRIM));
        if (preg_match('/^[A-Z]{3}$/D', $code) !== 1) {
            throw ValidationFailed::currency(ValidationFailed::REASON_FORMAT);
        }
        if (!$this->allowList->contains($code)) {
            throw ValidationFailed::currency(ValidationFailed::REASON_NOT_ALLOWED);
        }

        return $code;
    }
}
