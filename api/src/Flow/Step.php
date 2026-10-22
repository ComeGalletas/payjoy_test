<?php

declare(strict_types=1);

namespace FxInstallments\Flow;

use LogicException;

/**
 * One step of the flow (CONTEXT "step"), as FlowDefinition loaded and checked it. There are
 * three kinds:
 *
 * - **say** (`greet`): a message shown when a session starts, before the first question.
 * - **input** (`amount`, `currency`, `installments`): asks (`ask`), reads the answer as
 *   `expecting`, and on a failed reading answers with `clarify` (unclear) or the reason's text
 *   from `reasons`, falling back to `retry` (invalid). `buttons` are shortcuts a front-end may
 *   show; on the `optional` step, empty input or a button means `installments.default`.
 * - **call** (`result`, `call: convert`): converts, then answers with `say_single`,
 *   `say_even` or `say_uneven`, plus `stale_note` for a stale rate; `upstream_error` ends the
 *   session on an upstream failure.
 */
final readonly class Step
{
    public const SAY = 'say';
    public const INPUT = 'input';
    public const CALL = 'call';

    /**
     * @param list<string> $buttons
     * @param array<string, Template> $reasons validation reason => text
     */
    public function __construct(
        public string $id,
        public string $kind,
        public ?Template $say = null,
        public ?string $expecting = null,
        public ?Template $ask = null,
        public ?Template $retry = null,
        public ?Template $clarify = null,
        public array $buttons = [],
        public bool $optional = false,
        public array $reasons = [],
        public ?Template $saySingle = null,
        public ?Template $sayEven = null,
        public ?Template $sayUneven = null,
        public ?Template $staleNote = null,
        public ?Template $upstreamError = null,
    ) {
    }

    public function isInput(): bool
    {
        return $this->kind === self::INPUT;
    }

    /**
     * The text for an invalid answer with this validation reason: the step's own text for the
     * reason when the flow gives one, otherwise `retry`.
     */
    public function invalidText(string $reason): Template
    {
        return $this->reasons[$reason] ?? self::need($this->retry, 'retry');
    }

    /**
     * The text for an unclear answer (a clarification).
     */
    public function unclearText(): Template
    {
        return self::need($this->clarify, 'clarify');
    }

    public function askText(): Template
    {
        return self::need($this->ask, 'ask');
    }

    private static function need(?Template $template, string $key): Template
    {
        if ($template === null) {
            throw new LogicException("This step has no {$key} text");
        }

        return $template;
    }
}
