<?php

declare(strict_types=1);

namespace FxInstallments\Flow;

/**
 * @internal FlowEngine's result for one message, before it is saved: the session's next state,
 * the reply, and the events to record once the save succeeded.
 */
final readonly class Transition
{
    /**
     * @param list<array{string, array<string, mixed>}> $events event type => Metrics fields
     *        (source, channel and session_id are added when recorded)
     */
    public function __construct(
        public Session $session,
        public BotReply $reply,
        public array $events,
    ) {
    }
}
