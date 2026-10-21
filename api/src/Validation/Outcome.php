<?php

declare(strict_types=1);

namespace FxInstallments\Validation;

/**
 * How the bot's reading of user text ended (ADR 0013: strict API, lenient bot).
 *
 * - Ok: read and valid.
 * - Unclear: couldn't be read unambiguously; the flow asks for a clarification.
 * - Invalid: read, but rejected by the validator (out of range, not on the allow-list...);
 *   the flow re-asks with the step's retry text.
 */
enum Outcome: string
{
    case Ok = 'ok';
    case Unclear = 'unclear';
    case Invalid = 'invalid';
}
