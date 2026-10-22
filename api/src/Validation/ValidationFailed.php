<?php

declare(strict_types=1);

namespace FxInstallments\Validation;

use FxInstallments\Metrics\ErrorCodes;
use RuntimeException;

/**
 * A rejected input: one of the three validation error codes (docs/PLAN.md §5), a short
 * machine reason for event meta (`meta.reason`), and the generic English message_for_user from
 * `ErrorCodes`.
 *
 * The exception message never contains the rejected input, so it is safe to log.
 */
final class ValidationFailed extends RuntimeException
{
    // Amount
    public const REASON_FORMAT = 'format';
    public const REASON_NOT_POSITIVE = 'not_positive';
    public const REASON_OVER_MAX = 'over_max';
    /** The bot couldn't read the amount unambiguously; the flow asks for a clarification. */
    public const REASON_UNCLEAR = 'unclear';
    /**
     * The converted total would reach 2^53 minor units (docs/PLAN.md §3a; checked by
     * ConvertService before display()). The amount itself is valid, yet the user sees the
     * generic VALIDATION_AMOUNT text; unreachable with real rates (the largest real total is
     * about 50× below the limit).
     */
    public const REASON_TOO_LARGE = 'too_large';

    // Currency (REASON_FORMAT also applies)
    public const REASON_NOT_ALLOWED = 'not_allowed';
    public const REASON_UNKNOWN = 'unknown';
    /** The text names more than one currency (`$`, `peso`, `dollar`); never guessed. */
    public const REASON_AMBIGUOUS = 'ambiguous';

    // Installments (REASON_FORMAT also applies)
    public const REASON_OUT_OF_RANGE = 'out_of_range';
    /** Fewer minor units in the converted total than installments (docs/PLAN.md §3a). */
    public const REASON_BELOW_MINOR_UNIT = 'below_minor_unit';

    private function __construct(public readonly string $errorCode, public readonly string $reason)
    {
        parent::__construct("{$errorCode} ({$reason})");
    }

    public static function amount(string $reason): self
    {
        return new self(ErrorCodes::VALIDATION_AMOUNT, $reason);
    }

    public static function currency(string $reason): self
    {
        return new self(ErrorCodes::VALIDATION_CURRENCY, $reason);
    }

    public static function installments(string $reason): self
    {
        return new self(ErrorCodes::VALIDATION_INSTALLMENTS, $reason);
    }

    /**
     * The generic English text a front-end shows verbatim. All three validation codes have
     * one in `ErrorCodes`.
     */
    public function messageForUser(): string
    {
        return (string) ErrorCodes::messageForUser($this->errorCode);
    }
}
