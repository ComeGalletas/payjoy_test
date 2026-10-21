<?php

declare(strict_types=1);

namespace FxInstallments\Http;

/**
 * One `ip_blocks` row (ADR 0016). A block is active while `now < expires_at`, or forever when
 * `expires_at` is null, which only a manual block may be (the ADR 0016 amendment; `--prune`
 * uses the same contract and deletes rows with `expires_at <= now`).
 */
final class IpBlock
{
    public const AUTO = 'auto';
    public const MANUAL = 'manual';

    /** Reasons an automatic block records. */
    public const REASON_RATE_LIMIT_HITS = 'rate_limit_hits';
    public const REASON_ERROR_RESPONSES = 'error_responses';

    public function __construct(
        public readonly string $ip,
        public readonly string $reason,
        public readonly string $source,
        public readonly int $blockedAt,
        public readonly ?int $expiresAt,
    ) {
    }

    public function isActiveAt(int $now): bool
    {
        return $this->expiresAt === null || $now < $this->expiresAt;
    }

    public function isManual(): bool
    {
        return $this->source === self::MANUAL;
    }
}
