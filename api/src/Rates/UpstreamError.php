<?php

declare(strict_types=1);

namespace FxInstallments\Rates;

use FxInstallments\Metrics\ErrorCodes;
use InvalidArgumentException;
use RuntimeException;

/**
 * An upstream failure, mapped to one of the upstream error codes (docs/PLAN.md §3b, §5), with a
 * short fixed reason for event meta (`timeout`, `quota-reached`, `shape`, ...).
 *
 * Safe to log and to show (INV-03): the message is built only from the code and the reason,
 * both fixed strings chosen by this app, and the exception never chains a previous one, so no
 * Guzzle message (which holds the URL and the key) and no upstream body can reach a log, an
 * event or a response through it. `httpStatus` is upstream's status code, when one was the
 * reason.
 */
final class UpstreamError extends RuntimeException
{
    public const CODES = [
        ErrorCodes::UPSTREAM_UNSUPPORTED_CODE,
        ErrorCodes::UPSTREAM_TIMEOUT,
        ErrorCodes::UPSTREAM_QUOTA,
        ErrorCodes::UPSTREAM_AUTH,
        ErrorCodes::UPSTREAM_MALFORMED,
    ];

    public function __construct(
        public readonly string $errorCode,
        public readonly string $reason,
        public readonly ?int $httpStatus = null,
    ) {
        if (!in_array($errorCode, self::CODES, true)) {
            throw new InvalidArgumentException('Not an upstream error code');
        }
        if (preg_match('/^[a-z][a-z0-9_-]{0,39}$/D', $reason) !== 1) {
            throw new InvalidArgumentException('An upstream error reason is a short fixed word');
        }
        parent::__construct("{$errorCode} ({$reason})");
    }

    /**
     * The generic text front-ends show verbatim.
     */
    public function messageForUser(): string
    {
        return (string) ErrorCodes::messageForUser($this->errorCode);
    }

    public function status(): int
    {
        return (int) ErrorCodes::httpStatus($this->errorCode);
    }
}
