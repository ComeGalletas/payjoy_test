<?php

declare(strict_types=1);

namespace FxInstallments\Http;

use FxInstallments\Config\ConfigRepository;
use FxInstallments\Db\Retention;
use FxInstallments\Metrics\Metrics;
use FxInstallments\Metrics\RequestLog;
use InvalidArgumentException;

/**
 * Automatic IP blocking (ADR 0016), from the `block.*` config keys.
 *
 * After a client-error response (4xx), the client IP is blocked for `block.duration_minutes`
 * (IpBlocks applies the row rules) when, within the last `block.window_minutes`, it has
 * reached either threshold. Each response checks only the threshold it can move:
 *
 * - **On a 429:** `block.rate_limit_hits` **rate-limited minutes**, the fixed one-minute UTC
 *   windows in which the IP got at least one 429 (owner decision, 2026-10-21), so a burst
 *   inside one minute counts once. They are read from `rate_limits`
 *   (RateLimiter::rateLimitedWindows()): at most one primary-key row per minute of the window,
 *   whatever the IP's traffic, so a 429 flood costs a constant amount per request.
 *   The window is the current minute and the `block.window_minutes − 1` before it.
 * - **On another 4xx:** `block.error_responses` 4xx responses other than 429, from the request
 *   log (RequestLog::otherClientErrorsSince(), whose cost note explains the bound), counted
 *   over `ts >= now − window + 1` (a request exactly `window` seconds old has left it).
 *
 * - **Clean slate:** nothing before the end of the IP's previous block counts again, so the
 *   requests that earned a block, or that an operator forgave with `bin/ip.php unblock`, never
 *   count toward the next one. For the request log that is `ts >= expires_at`. For
 *   `rate_limits`, which only knows minutes, the minute holding `expires_at` counts only when
 *   `expires_at` is its first second (rounded up), because that minute may hold 429s from
 *   before a block that started and ended in it. `--prune` keeps an ended block for one more
 *   window (Retention), so this holds across a prune too.
 * - **Not counted:** 5xx responses (our failures or upstream's, not the client's) and blocked
 *   requests (403 IP_BLOCKED, which never reach RateLimiter either): a block neither feeds nor
 *   extends itself.
 * - **Never blocked automatically:** loopback addresses, the trusted proxy itself (its address
 *   is the client IP only when it sent no valid X-Forwarded-For hop) and anything that isn't a
 *   valid IP. Their requests are still logged, and an operator can still block them by hand.
 *
 * A block that starts or is extended writes an `ip.block` event (source `api`) that never holds
 * the IP: meta `block_source` (auto), `reason` (the threshold), `duration_minutes`, `extended`.
 * `duration_minutes` is the configured duration, which is also the time from now to the new
 * expiry in both cases: an extension sets the expiry to now + the duration.
 */
final class IpBlocking
{
    /** Upper bound for `block.duration_minutes`: one year. */
    public const MAX_MINUTES = 525_600;

    /**
     * Upper bound for `block.window_minutes`: one day. Rate-limited minutes are read from
     * `rate_limits`, which `--prune` keeps for Retention::RATE_LIMIT_DAYS (one day), so a longer
     * window would undercount.
     */
    public const MAX_WINDOW_MINUTES = Retention::RATE_LIMIT_DAYS * 1440;

    public function __construct(
        private readonly IpBlocks $blocks,
        private readonly RequestLog $requests,
        private readonly RateLimiter $rateLimiter,
        private readonly Metrics $metrics,
        public readonly int $windowMinutes,
        public readonly int $rateLimitHits,
        public readonly int $errorResponses,
        public readonly int $durationMinutes,
        private readonly ?string $trustedProxy = null,
    ) {
        self::assertWindowMinutes($windowMinutes);
        if ($durationMinutes < 1 || $durationMinutes > self::MAX_MINUTES) {
            throw new InvalidArgumentException('block.duration_minutes must be between 1 and ' . self::MAX_MINUTES);
        }
        foreach (['block.rate_limit_hits' => $rateLimitHits, 'block.error_responses' => $errorResponses] as $key => $count) {
            if ($count < 1) {
                throw new InvalidArgumentException("{$key} must be at least 1");
            }
        }
    }

    public static function fromConfig(
        IpBlocks $blocks,
        RequestLog $requests,
        RateLimiter $rateLimiter,
        Metrics $metrics,
        ConfigRepository $config,
        ?string $trustedProxy,
    ): self {
        return new self(
            $blocks,
            $requests,
            $rateLimiter,
            $metrics,
            $config->int('block.window_minutes'),
            $config->int('block.rate_limit_hits'),
            $config->int('block.error_responses'),
            $config->int('block.duration_minutes'),
            $trustedProxy,
        );
    }

    /**
     * The validation for `block.window_minutes`, shared with Retention, which reads it too.
     */
    public static function assertWindowMinutes(int $minutes): void
    {
        if ($minutes < 1 || $minutes > self::MAX_WINDOW_MINUTES) {
            throw new InvalidArgumentException('block.window_minutes must be between 1 and ' . self::MAX_WINDOW_MINUTES);
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
     * Called after a request's row is in the request log (and, for a 429, after RateLimiter
     * counted it). Blocks the IP when it is over a threshold and returns what IpBlocks did;
     * returns null when no threshold was reached. Throws on a database failure (the event
     * write never throws).
     *
     * @param IpBlock|null $previous the IP's row as the block check found it (an expired one,
     *        when the request was let through)
     */
    public function afterResponse(string $ip, int $status, int $now, ?IpBlock $previous): ?AutoBlockOutcome
    {
        if ($status < 400 || $status > 499 || $this->isExempt($ip)) {
            return null;
        }
        $previousEnd = $previous?->expiresAt;

        if ($status === 429) {
            $current = RateLimiter::windowStart($now);
            $from = $current - ($this->windowMinutes - 1) * RateLimiter::WINDOW_SECONDS;
            if ($previousEnd !== null) {
                $from = max($from, self::roundUpToWindow($previousEnd));
            }
            $reached = $this->rateLimiter->rateLimitedWindows($ip, $from, $current) >= $this->rateLimitHits;
            $reason = IpBlock::REASON_RATE_LIMIT_HITS;
        } else {
            $since = $now - $this->windowMinutes * 60 + 1;
            if ($previousEnd !== null) {
                $since = max($since, $previousEnd);
            }
            $reached = $this->requests->otherClientErrorsSince($ip, $since) >= $this->errorResponses;
            $reason = IpBlock::REASON_ERROR_RESPONSES;
        }
        if (!$reached) {
            return null;
        }

        $outcome = $this->blocks->blockAutomatically($ip, $reason, $now, $now + $this->durationMinutes * 60);
        if ($outcome->changed()) {
            $this->metrics->record('ip.block', [
                'source' => 'api',
                'meta' => [
                    'block_source' => IpBlock::AUTO,
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

    /**
     * The first window starting at or after $ts.
     */
    private static function roundUpToWindow(int $ts): int
    {
        return RateLimiter::windowStart($ts + RateLimiter::WINDOW_SECONDS - 1);
    }
}
