<?php

declare(strict_types=1);

namespace FxInstallments\Rates;

use FxInstallments\Metrics\ErrorCodes;
use InvalidArgumentException;
use JsonException;

/**
 * One trip of the breaker, as stored in `app_state` (JSON text).
 *
 * - `quota`: after `quota-reached`; open until `until`, 00:00 UTC on the first day of the
 *   next month.
 * - `auth`: after `invalid-key` or `inactive-account`; `until` is null, open until
 *   `bin/upstream.php reset`.
 * - `backoff`: after a failed refresh for any other reason (timeout, malformed answer); open
 *   for UpstreamBreaker::BACKOFF_SECONDS, so an outage costs one upstream call per five
 *   minutes instead of one per lookup.
 */
final class BreakerState
{
    public const QUOTA = 'quota';
    public const AUTH = 'auth';
    public const BACKOFF = 'backoff';

    public const KINDS = [self::QUOTA, self::AUTH, self::BACKOFF];

    public function __construct(
        public readonly string $kind,
        public readonly string $errorCode,
        public readonly int $since,
        public readonly ?int $until,
    ) {
        if (!in_array($kind, self::KINDS, true) || !in_array($errorCode, UpstreamError::CODES, true)) {
            throw new InvalidArgumentException('Invalid breaker state');
        }
        if (($kind === self::AUTH) !== ($until === null) || ($until !== null && $until <= $since)) {
            throw new InvalidArgumentException('Only an auth trip is open-ended; others end after they start');
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
        if (!is_string($kind) || !is_string($code) || !ErrorCodes::isKnown($code) || !is_int($since)
            || ($until !== null && !is_int($until))) {
            return null;
        }
        try {
            return new self($kind, $code, $since, $until);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
