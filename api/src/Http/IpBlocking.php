<?php

declare(strict_types=1);

namespace FxInstallments\Http;

use FxInstallments\Config\ConfigRepository;
use FxInstallments\Metrics\Metrics;
use FxInstallments\Metrics\RequestLog;
use InvalidArgumentException;

/**
 * Automatic IP blocking (ADR 0016), from the `block.*` config keys.
 *
 * After a client-error response (4xx), the client IP's recent requests are counted in the
 * request log. It is blocked for `block.duration_minutes` (IpBlocks applies the row rules)
 * when, within the last `block.window_minutes`, it has reached either:
 *
 * - `block.rate_limit_hits` **rate-limited minutes**: distinct fixed one-minute UTC windows
 *   (RateLimiter's `window_start`) with at least one 429, so a burst inside one minute, however
 *   many 429s it gets, counts once (owner decision, 2026-10-21); or
 * - `block.error_responses` other 4xx responses. 429s don't count here, or a burst of a few
 *   minutes would still block through this threshold.
 *
 * - **Counted:** requests that were not blocked, `ts >= now − window + 1` (a request exactly
 *   `window` seconds old has left it), and not before the expiry of the IP's previous block, so
 *   the requests that earned a block, or that an operator forgave with `bin/ip.php unblock`,
 *   never count toward the next one. `--prune` keeps an ended block for one more window
 *   (Retention), so this holds across a prune too.
 * - **Not counted:** 5xx responses (our failures or upstream's, not the client's) and blocked
 *   requests (403 IP_BLOCKED): a block neither feeds nor extends itself.
 * - **Never blocked automatically:** loopback addresses, the trusted proxy itself (its address
 *   is the client IP only when it sent no valid X-Forwarded-For hop) and anything that isn't a
 *   valid IP. Their requests are still logged, and an operator can still block them by hand.
 *
 * A block that starts or is extended writes an `ip.block` event (source `api`) that never holds
 * the IP: meta `source` (auto), `reason` (the threshold), `duration_minutes`, `extended`.
 */
final class IpBlocking
{
    /** Upper bound for the two minute settings: one year. */
    public const MAX_MINUTES = 525_600;

    public function __construct(
        private readonly IpBlocks $blocks,
        private readonly RequestLog $requests,
        private readonly Metrics $metrics,
        public readonly int $windowMinutes,
        public readonly int $rateLimitHits,
        public readonly int $errorResponses,
        public readonly int $durationMinutes,
        private readonly ?string $trustedProxy = null,
    ) {
        self::assertMinutes('block.window_minutes', $windowMinutes);
        self::assertMinutes('block.duration_minutes', $durationMinutes);
        foreach (['block.rate_limit_hits' => $rateLimitHits, 'block.error_responses' => $errorResponses] as $key => $count) {
            if ($count < 1) {
                throw new InvalidArgumentException("{$key} must be at least 1");
            }
        }
    }

    public static function fromConfig(IpBlocks $blocks, RequestLog $requests, Metrics $metrics, ConfigRepository $config, ?string $trustedProxy): self
    {
        return new self(
            $blocks,
            $requests,
            $metrics,
            $config->int('block.window_minutes'),
            $config->int('block.rate_limit_hits'),
            $config->int('block.error_responses'),
            $config->int('block.duration_minutes'),
            $trustedProxy,
        );
    }

    /**
     * The validation for `block.window_minutes` and `block.duration_minutes`, shared with
     * Retention, which reads the window too.
     */
    public static function assertMinutes(string $key, int $minutes): void
    {
        if ($minutes < 1 || $minutes > self::MAX_MINUTES) {
            throw new InvalidArgumentException("{$key} must be between 1 and " . self::MAX_MINUTES);
        }
    }

    /**
     * The IP's block row, active or not: the Kernel's block check. It is a pass-through to
     * IpBlocks on purpose, so the Kernel has one collaborator for the whole block step: the row
     * it returns is both the check (isActiveAt) and the `$previous` that afterResponse() needs
     * for the clean slate after an earlier block. Throws on a database failure.
     */
    public function lookup(string $ip): ?IpBlock
    {
        return $this->blocks->find($ip);
    }

    /**
     * Called after a request's row is in the request log. Blocks the IP when it is over a
     * threshold and returns what IpBlocks did; returns null when no threshold was reached.
     * Throws on a database failure (the event write never throws).
     *
     * @param IpBlock|null $previous the IP's row as the block check found it (an expired one,
     *        when the request was let through)
     */
    public function afterResponse(string $ip, int $status, int $now, ?IpBlock $previous): ?AutoBlockOutcome
    {
        if ($status < 400 || $status > 499 || $this->isExempt($ip)) {
            return null;
        }

        $since = $now - $this->windowMinutes * 60 + 1;
        if ($previous !== null && $previous->expiresAt !== null) {
            $since = max($since, $previous->expiresAt);
        }
        [$rateLimitedMinutes, $otherClientErrors] = $this->requests->clientErrorCountsSince($ip, $since);

        $reason = match (true) {
            $rateLimitedMinutes >= $this->rateLimitHits => IpBlock::REASON_RATE_LIMIT_HITS,
            $otherClientErrors >= $this->errorResponses => IpBlock::REASON_ERROR_RESPONSES,
            default => null,
        };
        if ($reason === null) {
            return null;
        }

        $outcome = $this->blocks->blockAutomatically($ip, $reason, $now, $now + $this->durationMinutes * 60);
        if ($outcome->changed()) {
            $this->metrics->record('ip.block', [
                'source' => 'api',
                'meta' => [
                    'source' => IpBlock::AUTO,
                    'reason' => $reason,
                    'duration_minutes' => $this->durationMinutes,
                    'extended' => $outcome->outcome === AutoBlockOutcome::EXTENDED,
                ],
            ]);
        }

        return $outcome;
    }

    public function isExempt(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true;
        }
        if ($this->trustedProxy !== null && $this->trustedProxy !== '' && $ip === $this->trustedProxy) {
            return true;
        }

        return self::isLoopback($ip);
    }

    public static function isLoopback(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 4) {
            return $packed[0] === "\x7F";
        }
        // ::1, and IPv4-mapped loopback (::ffff:127.x.x.x).
        return $packed === str_repeat("\0", 15) . "\1"
            || (str_starts_with($packed, str_repeat("\0", 10) . "\xFF\xFF") && $packed[12] === "\x7F");
    }
}
