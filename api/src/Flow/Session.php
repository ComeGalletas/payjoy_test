<?php

declare(strict_types=1);

namespace FxInstallments\Flow;

/**
 * One `sessions` row (CONTEXT "session"): a server-issued id, the channel, the step the
 * session is at, its status (`open`, `completed`, `abandoned`), what it collected, the retry
 * count at the current step, and its times (UTC epoch seconds). An open session is live while
 * `now < expires_at`.
 */
final readonly class Session
{
    public const OPEN = 'open';
    public const COMPLETED = 'completed';
    public const ABANDONED = 'abandoned';

    public function __construct(
        public string $id,
        public string $channel,
        public string $step,
        public string $status,
        public Collected $collected,
        public int $retries,
        public int $createdAt,
        public int $updatedAt,
        public int $expiresAt,
    ) {
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function isExpiredAt(int $now): bool
    {
        return $now >= $this->expiresAt;
    }

    /**
     * The next state of this session at $now. An open session's expiry moves to
     * `now + ttl`; a closed one's is set to `now`.
     */
    public function next(string $step, string $status, Collected $collected, int $retries, int $now, int $ttlSeconds): self
    {
        return new self(
            $this->id,
            $this->channel,
            $step,
            $status,
            $collected,
            $retries,
            $this->createdAt,
            $now,
            $status === self::OPEN ? $now + $ttlSeconds : $now,
        );
    }
}
