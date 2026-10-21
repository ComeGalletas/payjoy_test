<?php

declare(strict_types=1);

namespace FxInstallments\Rates;

use FxInstallments\Metrics\ErrorCodes;
use InvalidArgumentException;
use JsonException;

/**
 * One trip of the breaker, as stored in `app_state` (JSON text).
 *
 * - `quota`: after `quota-reached` (UPSTREAM_QUOTA); open until `until`, at most 00:00 UTC on
 *   the first day of the month after `since`.
 * - `auth`: after `invalid-key` or `inactive-account` (UPSTREAM_AUTH); `until` is null, open
 *   until `bin/upstream.php reset`.
 * - `backoff`: after any other failed refresh (UPSTREAM_TIMEOUT, UPSTREAM_MALFORMED,
 *   UPSTREAM_UNSUPPORTED_CODE); open for UpstreamBreaker::backoffSeconds($failures), at most
 *   one hour. `failures` counts consecutive failed refreshes (owner decision 2026-10-21:
 *   5, 10, 20, 40, then 60 minutes); it is 0 for the other kinds.
 *
 * The constructor rejects any other combination, so a hand-edited or corrupt row can't
 * produce an open-ended or mislabelled block: fromJson() returns null for it and the breaker
 * counts as closed.
 */
final class BreakerState
{
    public const QUOTA = 'quota';
    public const AUTH = 'auth';
    public const BACKOFF = 'backoff';

    public const KINDS = [self::QUOTA, self::AUTH, self::BACKOFF];

    /** Kind => the error codes it may carry. */
    private const CODES = [
        self::QUOTA => [ErrorCodes::UPSTREAM_QUOTA],
        self::AUTH => [ErrorCodes::UPSTREAM_AUTH],
        self::BACKOFF => [ErrorCodes::UPSTREAM_TIMEOUT, ErrorCodes::UPSTREAM_MALFORMED, ErrorCodes::UPSTREAM_UNSUPPORTED_CODE],
    ];

    public function __construct(
        public readonly string $kind,
        public readonly string $errorCode,
        public readonly int $since,
        public readonly ?int $until,
        public readonly int $failures = 0,
    ) {
        if (!isset(self::CODES[$kind]) || !in_array($errorCode, self::CODES[$kind], true)) {
            throw new InvalidArgumentException('Invalid breaker kind or error code for that kind');
        }
        $valid = match ($kind) {
            self::AUTH => $until === null && $failures === 0,
            self::QUOTA => $until !== null && $until > $since
                && $until <= UpstreamBreaker::nextMonthStart($since) && $failures === 0,
            default => $until !== null && $until > $since
                && $until - $since <= UpstreamBreaker::MAX_BACKOFF_SECONDS && $failures >= 1,
        };
        if (!$valid) {
            throw new InvalidArgumentException('Breaker block out of range for its kind');
        }
    }

    public function isOpenAt(int $now): bool
    {
        return $this->until === null || $now < $this->until;
    }

    /**
     * The error a blocked lookup returns: UPSTREAM_QUOTA, UPSTREAM_AUTH, or for a backoff the
     * code of the failure that started it.
     */
    public function error(): UpstreamError
    {
        return new UpstreamError($this->errorCode, 'blocked_' . $this->kind);
    }

    public function toJson(): string
    {
        return json_encode([
            'kind' => $this->kind,
            'error_code' => $this->errorCode,
            'since' => $this->since,
            'until' => $this->until,
            'failures' => $this->failures,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Null for text that isn't a valid stored state.
     */
    public static function fromJson(string $json): ?self
    {
        try {
            $data = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($data)) {
            return null;
        }
        $kind = $data['kind'] ?? null;
        $code = $data['error_code'] ?? null;
        $since = $data['since'] ?? null;
        $until = $data['until'] ?? null;
        $failures = $data['failures'] ?? null;
        if (!is_string($kind) || !is_string($code) || !is_int($since) || !is_int($failures)
            || ($until !== null && !is_int($until))) {
            return null;
        }
        try {
            return new self($kind, $code, $since, $until, $failures);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
