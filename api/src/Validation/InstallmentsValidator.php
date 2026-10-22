<?php

declare(strict_types=1);

namespace FxInstallments\Validation;

use FxInstallments\Config\ConfigRepository;

/**
 * The installments validator (docs/PLAN.md §3): an integer from 1 to `installments.max`.
 *
 * Text must be ASCII digits only: no sign, no decimal point, no exponent (`-1`, `3.5` and
 * `1e1` are rejected).
 * - `fromText()` (bot) trims the same end whitespace as `AmountParser` (`EdgeWhitespace`:
 *   space, tab, CR, LF, U+00A0, U+202F) and accepts leading zeros (`03` → 3,
 *   `007` → 7; `00` is 0, out of range), per the 2026-10-21 owner decision.
 * - `fromQuery()` (`/convert`) trims nothing and rejects leading zeros (`03`).
 *
 * Not checked here: "at least one minor unit per installment" needs the rate and the
 * currency's minor units (P1-05). Empty input and the "Just one payment" button mean
 * `installments.default`; choosing that is the flow's job (P1-06, `Flow\FlowEngine`), so
 * `defaultCount()` only exposes it. `read()` gives the bot's reading as an
 * `InstallmentsReading`.
 */
final class InstallmentsValidator
{
    public function __construct(private readonly ConfigRepository $config)
    {
    }

    /**
     * @throws ValidationFailed VALIDATION_INSTALLMENTS, reason out_of_range
     */
    public function validate(int $count): int
    {
        if ($count < 1 || $count > $this->max()) {
            throw ValidationFailed::installments(ValidationFailed::REASON_OUT_OF_RANGE);
        }

        return $count;
    }

    /**
     * fromText() as a reading (ok or invalid), for the flow engine (P1-06).
     */
    public function read(string $text): InstallmentsReading
    {
        try {
            return InstallmentsReading::ok($this->fromText($text));
        } catch (ValidationFailed $error) {
            return InstallmentsReading::invalid($error);
        }
    }

    /**
     * Bot input: end whitespace trimmed, then digits only; leading zeros allowed.
     *
     * @throws ValidationFailed VALIDATION_INSTALLMENTS, reason format or out_of_range
     */
    public function fromText(string $text): int
    {
        $digits = EdgeWhitespace::trim($text);
        if (preg_match('/^[0-9]+$/D', $digits) !== 1) {
            throw ValidationFailed::installments(ValidationFailed::REASON_FORMAT);
        }
        $digits = ltrim($digits, '0');

        return $this->fromQuery($digits === '' ? '0' : $digits);
    }

    /**
     * `/convert` input: the raw string, digits only, no leading zero, nothing trimmed.
     *
     * @throws ValidationFailed VALIDATION_INSTALLMENTS, reason format or out_of_range
     */
    public function fromQuery(string $raw): int
    {
        // `0` alone is a well-formed number, out of range; `03` and `00` are malformed.
        if (preg_match('/^(0|[1-9][0-9]*)$/D', $raw) !== 1) {
            throw ValidationFailed::installments(ValidationFailed::REASON_FORMAT);
        }
        // Compare lengths first, so a huge digit string never overflows the int cast.
        if (strlen($raw) > strlen((string) $this->max())) {
            throw ValidationFailed::installments(ValidationFailed::REASON_OUT_OF_RANGE);
        }

        return $this->validate((int) $raw);
    }

    public function max(): int
    {
        return $this->config->int('installments.max');
    }

    /**
     * `installments.default`, for empty input and the "Just one payment" button (P1-06).
     */
    public function defaultCount(): int
    {
        return $this->config->int('installments.default');
    }
}
