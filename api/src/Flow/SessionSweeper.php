<?php

declare(strict_types=1);

namespace FxInstallments\Flow;

use Closure;
use FxInstallments\Metrics\ErrorCodes;
use FxInstallments\Metrics\Metrics;

/**
 * Closes expired sessions (docs/PLAN.md §9): open sessions with `expires_at <= now` become
 * abandoned at their last step, each with one `bot.session_abandoned` event carrying
 * BOT_SESSION_EXPIRED, the session's channel, its last step and what it collected.
 *
 * It needs only the sessions table and Metrics, not the flow file, so reports (P1-08) can sweep
 * before they count even when `flows/default.yaml` is broken. FlowEngine runs it on every
 * message. One sweep is one transaction (SessionStore::sweepExpired()), events included.
 */
final class SessionSweeper
{
    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param (Closure(): int)|null $clock UTC epoch seconds; defaults to time()
     */
    public function __construct(
        private readonly SessionStore $sessions,
        private readonly Metrics $metrics,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Sweeps up to SessionStore::SWEEP_LIMIT expired sessions; returns how many this call
     * closed (0 when another connection held the write lock).
     */
    public function sweep(?int $now = null): int
    {
        return count($this->sessions->sweepExpired($now ?? ($this->clock)(), $this->recordExpired(...)));
    }

    /**
     * Closes one expired open session the sweep hadn't reached yet; false when it was no longer
     * open and expired (another process closed it first).
     */
    public function closeOne(Session $session, int $now): bool
    {
        if (!$this->sessions->closeExpired($session->id, $now)) {
            return false;
        }
        $this->recordExpired($session);

        return true;
    }

    private function recordExpired(Session $session): void
    {
        $this->metrics->record('bot.session_abandoned', [
            'source' => FlowEngine::SOURCE,
            'channel' => $session->channel,
            'session_id' => $session->id,
            'step' => $session->step,
            'error_code' => ErrorCodes::BOT_SESSION_EXPIRED,
            'meta' => ['expires_at' => $session->expiresAt],
        ] + $session->collected->eventFields());
    }
}
