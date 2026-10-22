<?php

declare(strict_types=1);

namespace FxInstallments\Flow;

/**
 * The engine's answer to one message: the `POST /bot/message` data (docs/PLAN.md §6),
 * `{session_id, reply, buttons?, step, done}`.
 *
 * - `reply`: the bot's text, from the flow file only; parts (the greeting and the first
 *   question, a result and its stale note) are joined with "\n". Front-ends show it verbatim.
 * - `buttons`: the shortcuts of the step now waiting for an answer, only when it has any and
 *   the session is still open.
 * - `step`: the step the session is now at: the step waiting for an answer, `result` once
 *   completed, or the step where it was abandoned.
 * - `done`: true once the session is closed (completed or abandoned). A later message with the
 *   same `session_id` starts a new session.
 *
 * `status` (open, completed, abandoned) is for callers such as the CLI; it isn't sent.
 */
final readonly class BotReply
{
    /**
     * @param list<string> $buttons
     */
    public function __construct(
        public string $sessionId,
        public string $reply,
        public array $buttons,
        public string $step,
        public string $status,
    ) {
    }

    public function done(): bool
    {
        return $this->status !== Session::OPEN;
    }

    /**
     * @return array{session_id: string, reply: string, buttons?: list<string>, step: string, done: bool}
     */
    public function toArray(): array
    {
        $data = ['session_id' => $this->sessionId, 'reply' => $this->reply];
        if ($this->buttons !== [] && !$this->done()) {
            $data['buttons'] = $this->buttons;
        }
        $data['step'] = $this->step;
        $data['done'] = $this->done();

        return $data;
    }
}
