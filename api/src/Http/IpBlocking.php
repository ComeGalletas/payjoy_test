<?php

declare(strict_types=1);

namespace FxInstallments\Http;

use FxInstallments\Config\ConfigRepository;
use FxInstallments\Metrics\RequestLog;
use InvalidArgumentException;

/**
 * Automatic IP blocking (ADR 0016), from the `block.*` config keys.
 *
 * After a client-error response (4xx), the client IP's recent requests are counted in the
 * request log. When, within the last `block.window_minutes`, it has reached
 * `block.rate_limit_hits` 429 responses, or `block.error_responses` 4xx responses (429s
 * included), it is blocked for `block.duration_minutes` (IpBlocks applies the row rules).
 *
 * - **Counted:** requests that were not blocked, with a 4xx status, `ts >= now − window + 1`
 *   (so a request exactly `window` seconds old has left it) and not before the expiry of the
 *   IP's previous block, so the requests that earned a block, or that an operator forgave with
 *   `bin/ip.php unblock`, never count toward the next one.
 * - **Not counted:** 5xx responses (our failures or upstream's, not the client's) and blocked
 *   requests (403 IP_BLOCKED): a block neither feeds nor extends itself.
 * - **Never blocked automatically:** loopback addresses, the trusted proxy itself (its address
 *   is the client IP only when it sent no valid X-Forwarded-For hop) and anything that isn't a
 *   valid IP. Their requests are still logged, and an operator can still block them by hand.
 */
final class IpBlocking
{
    /** Upper bound for the two minute settings: one year. */
    public const MAX_MINUTES = 525_600;

    public function __construct(
        private readonly IpBlocks $blocks,
        private readonly RequestLog $requests,
        public readonly int $windowMinutes,
        public readonly int $rateLimitHits,
        public readonly int $errorResponses,
        public readonly int $durationMinutes,
        private readonly ?string $trustedProxy = null,
    ) {
        foreach ([
            'block.window_minutes' => $windowMinutes,
            'block.duration_minutes' => $durationMinutes,
        ] as $key => $minutes) {
            if ($minutes < 1 || $minutes > self::MAX_MINUTES) {
                throw new InvalidArgumentException("{$key} must be between 1 and " . self::MAX_MINUTES);
            }
        }
        foreach (['block.rate_limit_hits' => $rateLimitHits, 'block.error_responses' => $errorResponses] as $key => $count) {
            if ($count < 1) {
                throw new InvalidArgumentException("{$key} must be at least 1");
            }
        }
    }

    public static function fromConfig(IpBlocks $blocks, RequestLog $requests, ConfigRepository $config, ?string $trustedProxy): self
    {
        return new self(
            $blocks,
            $requests,
            $config->int('block.window_minutes'),
            $config->int('block.rate_limit_hits'),
            $config->int('block.error_responses'),
            $config->int('block.duration_minutes'),
            $trustedProxy,
        );
    }

    /**
     * The IP's block row, active or not (the Kernel's block check). Throws on a database failure.
     */
    public function lookup(string $ip): ?IpBlock
    {
        return $this->blocks->find($ip);
    }

    /**
     * Called after a request's row is in the request log. Blocks the IP when it is over a
     * threshold and returns the block as it now stands; returns null when nothing was blocked.
     * Throws on a database failure.
     *
     * @param IpBlock|null $previous the IP's row as the block check found it (an expired one,
     *        when the request was let through)
     */
    public function afterResponse(string $ip, int $status, int $now, ?IpBlock $previous): ?IpBlock
    {
        if ($status < 400 || $status > 499 || $this->isExempt($ip)) {
            return null;
        }

        $since = $now - $this->windowMinutes * 60 + 1;
        if ($previous !== null && $previous->expiresAt !== null) {
            $since = max($since, $previous->expiresAt);
        }
        [$rateLimited, $clientErrors] = $this->requests->clientErrorCountsSince($ip, $since);

        $reason = match (true) {
            $rateLimited >= $this->rateLimitHits => IpBlock::REASON_RATE_LIMIT_HITS,
            $clientErrors >= $this->errorResponses => IpBlock::REASON_ERROR_RESPONSES,
            default => null,
        };
        if ($reason === null) {
            return null;
        }

        return $this->blocks->blockAutomatically($ip, $reason, $now, $now + $this->durationMinutes * 60);
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
