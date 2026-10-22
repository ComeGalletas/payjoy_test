<?php

declare(strict_types=1);

namespace FxInstallments\Flow;

use Closure;
use FxInstallments\Convert\ConvertService;
use FxInstallments\Convert\MinorUnits;
use FxInstallments\Convert\UsdCents;
use FxInstallments\Metrics\ErrorCodes;
use FxInstallments\Metrics\Metrics;
use FxInstallments\Rates\UpstreamError;
use FxInstallments\Validation\AmountParser;
use FxInstallments\Validation\CurrencyResolver;
use FxInstallments\Validation\InstallmentsValidator;
use FxInstallments\Validation\Outcome;
use FxInstallments\Validation\TextNormalizer;
use FxInstallments\Validation\ValidationFailed;
use InvalidArgumentException;
use RuntimeException;

/**
 * The flow engine (CONTEXT "flow engine"; docs/PLAN.md §9): runs the static flow one message
 * at a time, for every channel. `POST /bot/message` and `bin/bot.php` both call handle().
 *
 * **The bot never computes money.** Answers are read by the P1-03 readers (AmountParser,
 * CurrencyResolver, InstallmentsValidator); the result comes from ConvertService, the same path
 * as `/convert`, and its texts show only the `display` strings, the currency code and the
 * installment count. Nothing here does arithmetic on amounts or rates.
 *
 * **One message.**
 * 1. Sweep: open sessions with `expires_at <= now` are closed as abandoned with
 *    BOT_SESSION_EXPIRED at their last step.
 * 2. No session id, or one that is malformed, unknown, closed, expired, or at a step the flow
 *    doesn't ask: a new session starts. Its first message is never read as an answer (an
 *    answer meant for an expired session, like `3`, must not become a 3 USD amount); the reply
 *    is the `say` steps plus the first question.
 * 3. Otherwise the text answers the session's step:
 *    - ok: the value is collected and the next question asked; once all three fields are
 *      collected the result step converts. On the optional step, empty input or a button text
 *      (folded) means `installments.default`.
 *    - unclear: the step's `clarify` text; invalid: the reason's text or `retry`. Both are a
 *      retry. When the step's retry count reaches `max_retries`, the session is abandoned with
 *      BOT_MAX_RETRIES at that step and the flow's `max_retries_reply`.
 * 4. Result: a VALIDATION_* from the conversion (`below_minor_unit`, `too_large`) re-asks the
 *    step of that field with the reason's text, which may show the API's `message_for_user`;
 *    it counts as a retry. An UpstreamError ends the session (`bot.session_abandoned` with the
 *    upstream code, at `result`) with `upstream_error`, which shows the API's
 *    `message_for_user`.
 *    Otherwise `say_single` (1 installment), `say_even` or `say_uneven`, plus `stale_note` for a
 *    stale rate, and the session is completed.
 *
 * **Retries** count failed answers at the current step: they start at 0 on each new question
 * and are kept when a failed conversion sends the user back to the step they just answered, so
 * a loop of conversions that fail is bounded by `max_retries` too.
 *
 * **Events** (source `bot`, with `channel` and `session_id`): `bot.session_start`, `bot.step`
 * (ok, or error with the VALIDATION_* code), `bot.session_completed`, `bot.session_abandoned`.
 * Columns hold only what the session collected (country only when a country was typed);
 * meta holds fixed words and numbers: `outcome`, `reason`, `retry`, `default`, `via`,
 * `template`, `stale`. Never the text the user typed.
 *
 * **Concurrency.** The new state is saved with SessionStore::save() (compare-and-set) and the
 * events are written after it succeeds. When another message for the same session won, this
 * one is handled again on the new state, up to MAX_ATTEMPTS times.
 */
final class FlowEngine
{
    public const SOURCE = 'bot';

    private const MAX_ATTEMPTS = 3;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param Closure(): ConvertService $convertService built on first use
     * @param (Closure(): int)|null $clock UTC epoch seconds; defaults to time()
     */
    public function __construct(
        private readonly FlowDefinition $flow,
        private readonly SessionStore $sessions,
        private readonly Metrics $metrics,
        private readonly AmountParser $amounts,
        private readonly CurrencyResolver $currencies,
        private readonly InstallmentsValidator $installments,
        private readonly Closure $convertService,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Handles one message on $channel (`static`, `cli`, `typebot`; an existing session keeps
     * the channel it started on).
     *
     * @throws InvalidArgumentException for an unknown channel
     * @throws RuntimeException when the session kept changing under concurrent messages
     */
    public function handle(?string $sessionId, string $text, string $channel): BotReply
    {
        if (!in_array($channel, Metrics::CHANNELS, true)) {
            throw new InvalidArgumentException('Unknown channel');
        }
        $now = ($this->clock)();
        $this->sweepExpired($now);

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $session = $sessionId === null ? null : $this->liveSession($sessionId, $now);
            if ($session === null) {
                return $this->start($channel, $now);
            }
            $transition = $this->advance($session, $text, $now);
            if ($this->sessions->save($session, $transition->session)) {
                foreach ($transition->events as [$type, $fields]) {
                    $this->record($transition->session, $type, $fields);
                }

                return $transition->reply;
            }
        }

        throw new RuntimeException('The session changed under concurrent messages');
    }

    /**
     * Closes expired open sessions as abandoned (BOT_SESSION_EXPIRED, at their last step) and
     * returns how many this call closed. handle() runs it on every message; reports run it
     * before they count (P1-08).
     */
    public function sweepExpired(?int $now = null): int
    {
        $closed = $this->sessions->sweepExpired($now ?? ($this->clock)());
        foreach ($closed as $session) {
            $this->recordExpired($session);
        }

        return count($closed);
    }

    /**
     * The open, live session for this id at a step the flow asks, or null. An expired session
     * the sweep didn't reach yet is closed here.
     */
    private function liveSession(string $id, int $now): ?Session
    {
        $session = $this->sessions->find($id);
        if ($session === null || !$session->isOpen()) {
            return null;
        }
        if ($session->isExpiredAt($now)) {
            if ($this->sessions->closeExpired($session->id, $now)) {
                $this->recordExpired($session);
            }

            return null;
        }
        $step = $this->flow->step($session->step);

        return $step !== null && $step->isInput() ? $session : null;
    }

    private function start(string $channel, int $now): BotReply
    {
        $first = $this->flow->nextInputStep(static fn (): bool => false);
        if ($first === null) {
            throw new RuntimeException('The flow has no input step');
        }
        $session = $this->sessions->create($channel, $first->id, $now);
        $this->record($session, 'bot.session_start', [
            'step' => $this->flow->firstStep()->id,
            'meta' => ['flow' => $this->flow->name],
        ]);

        $parts = [];
        foreach ($this->flow->saySteps() as $say) {
            $parts[] = $say->say?->render() ?? '';
        }
        $parts[] = $first->askText()->render();

        return self::waiting($session, $first, implode("\n", $parts));
    }

    private function advance(Session $session, string $text, int $now): Transition
    {
        $step = $this->flow->step($session->step);
        if ($step === null || !$step->isInput()) {
            throw new RuntimeException('liveSession() returned a session at a step the flow does not ask');
        }

        [$outcome, $collected, $error, $meta] = $this->read($step, $text, $session->collected);

        if ($outcome === Outcome::Ok && $collected !== null) {
            $events = [['bot.step', ['step' => $step->id, 'meta' => ['outcome' => Outcome::Ok->value] + $meta] + $collected->eventFields()]];
            $next = $this->flow->nextInputStep($collected->has(...));
            if ($next === null) {
                return $this->result($session, $step, $collected, $events, $now);
            }
            $new = $session->next($next->id, Session::OPEN, $collected, 0, $now, $this->sessions->ttlSeconds);

            return new Transition($new, self::waiting($new, $next, $next->askText()->render()), $events);
        }

        if ($error === null) {
            throw new RuntimeException('A failed reading without an error');
        }
        $retries = $session->retries + 1;
        $events = [['bot.step', [
            'step' => $step->id,
            'error_code' => $error->errorCode,
            'meta' => ['outcome' => $outcome->value, 'reason' => $error->reason, 'retry' => $retries],
        ] + $session->collected->eventFields()]];
        if ($retries >= $this->flow->maxRetries) {
            return $this->abandon($session, $step->id, $session->collected, $retries, ErrorCodes::BOT_MAX_RETRIES, ['retries' => $retries], $this->flow->maxRetriesReply->render(), $events, $now);
        }

        $template = $outcome === Outcome::Unclear ? $step->unclearText() : $step->invalidText($error->reason);
        $new = $session->next($step->id, Session::OPEN, $session->collected, $retries, $now, $this->sessions->ttlSeconds);

        return new Transition($new, self::waiting($new, $step, $template->render()), $events);
    }

    /**
     * Reads the answer to an input step.
     *
     * @return array{Outcome, ?Collected, ?ValidationFailed, array<string, bool>} the outcome, the
     *         collected values with the answer (ok only), the error (unclear or invalid), meta
     */
    private function read(Step $step, string $text, Collected $collected): array
    {
        switch ($step->expecting) {
            case 'amount':
                $reading = $this->amounts->read($text);

                return $reading->amount !== null
                    ? [Outcome::Ok, $collected->withAmount($reading->amount->cents), null, []]
                    : [$reading->outcome, null, $reading->error, []];

            case 'currency':
                $resolution = $this->currencies->resolve($text);

                return $resolution->currency !== null
                    ? [Outcome::Ok, $collected->withCurrency($resolution->currency, $resolution->country), null, []]
                    : [$resolution->outcome, null, $resolution->error, []];

            case 'installments':
                if ($step->optional && self::meansDefault($step, $text)) {
                    $default = $this->installments->validate($this->installments->defaultCount());

                    return [Outcome::Ok, $collected->withInstallments($default), null, ['default' => true]];
                }
                $reading = $this->installments->read($text);

                return $reading->count !== null
                    ? [Outcome::Ok, $collected->withInstallments($reading->count), null, []]
                    : [$reading->outcome, null, $reading->error, []];
        }

        throw new RuntimeException('Unknown expecting value');
    }

    /**
     * On the optional step: empty input, or one of the step's buttons (folded: case, accents,
     * spaces), means "use the default".
     */
    private static function meansDefault(Step $step, string $text): bool
    {
        $folded = TextNormalizer::fold($text);
        if ($folded === null) {
            return false;
        }
        if ($folded === '') {
            return true;
        }
        foreach ($step->buttons as $button) {
            if (TextNormalizer::fold($button) === $folded) {
                return true;
            }
        }

        return false;
    }

    /**
     * The result step: convert on the same path as `/convert` and answer from `display`.
     *
     * @param list<array{string, array<string, mixed>}> $events
     */
    private function result(Session $session, Step $answered, Collected $collected, array $events, int $now): Transition
    {
        $call = $this->flow->callStep();
        $cents = (int) $collected->amountUsdCents;
        $currency = (string) $collected->currency;
        $count = (int) $collected->installments;

        try {
            $service = ($this->convertService)();
            $conversion = $service->convert($service->amount(UsdCents::toCanonical($cents)), $currency, $count);
        } catch (ValidationFailed $error) {
            return $this->convertFailed($session, $answered, $collected, $error, $events, $now);
        } catch (UpstreamError $error) {
            return $this->abandon(
                $session,
                $call->id,
                $collected,
                $session->retries,
                $error->errorCode,
                ['reason' => $error->reason, 'http_status' => $error->status()],
                self::need($call->upstreamError)->render(['message_for_user' => $error->messageForUser()]),
                $events,
                $now,
            );
        }

        $display = $conversion->display;
        [$template, $kind] = match (true) {
            $conversion->installments === 1 => [$call->saySingle, 'single'],
            $display->even => [$call->sayEven, 'even'],
            default => [$call->sayUneven, 'uneven'],
        };
        $reply = self::need($template)->render([
            'display.amount_usd' => $display->amountUsd,
            'display.rate' => $display->rate,
            'display.converted' => $display->converted,
            'display.installment' => $display->installment,
            'display.last_installment' => $display->lastInstallment,
            'display.regular_count' => (string) $display->regularCount,
            'display.rate_as_of' => $display->rateAsOf,
            'currency' => $conversion->currency,
            'installments' => (string) $conversion->installments,
        ]);
        if ($conversion->stale) {
            $reply .= "\n" . self::need($call->staleNote)->render();
        }

        $meta = ['template' => $kind, 'stale' => $conversion->stale];
        if (!MinorUnits::isKnown($conversion->currency)) {
            $meta['minor_units_default'] = true;
        }
        $new = $session->next($call->id, Session::COMPLETED, $collected, $session->retries, $now, $this->sessions->ttlSeconds);
        $events[] = ['bot.session_completed', ['step' => $call->id, 'meta' => $meta] + $collected->eventFields()];

        return new Transition($new, new BotReply($new->id, $reply, [], $call->id, Session::COMPLETED), $events);
    }

    /**
     * A VALIDATION_* from the conversion: re-ask the step of that field (§9), as a retry.
     *
     * @param list<array{string, array<string, mixed>}> $events
     */
    private function convertFailed(Session $session, Step $answered, Collected $collected, ValidationFailed $error, array $events, int $now): Transition
    {
        $target = $this->flow->inputStepFor(match ($error->errorCode) {
            ErrorCodes::VALIDATION_AMOUNT => 'amount',
            ErrorCodes::VALIDATION_INSTALLMENTS => 'installments',
            default => 'currency',
        });
        // Back to the step just answered: its count goes on, so failing conversions are
        // bounded by max_retries too. Back to an earlier step: its first retry.
        $retries = $target->id === $answered->id ? $session->retries + 1 : 1;
        $events[] = ['bot.step', [
            'step' => $target->id,
            'error_code' => $error->errorCode,
            'meta' => ['outcome' => Outcome::Invalid->value, 'reason' => $error->reason, 'retry' => $retries, 'via' => 'convert'],
        ] + $collected->eventFields()];
        $remaining = $collected->without((string) $target->expecting);

        if ($retries >= $this->flow->maxRetries) {
            return $this->abandon($session, $target->id, $remaining, $retries, ErrorCodes::BOT_MAX_RETRIES, ['retries' => $retries], $this->flow->maxRetriesReply->render(), $events, $now);
        }

        $text = $target->invalidText($error->reason)->render([
            'message_for_user' => $error->messageForUser(),
            'currency' => (string) $collected->currency,
            'installments' => (string) $collected->installments,
        ]);
        $new = $session->next($target->id, Session::OPEN, $remaining, $retries, $now, $this->sessions->ttlSeconds);

        return new Transition($new, self::waiting($new, $target, $text), $events);
    }

    /**
     * @param array<string, int|string> $meta
     * @param list<array{string, array<string, mixed>}> $events
     */
    private function abandon(Session $session, string $stepId, Collected $collected, int $retries, string $errorCode, array $meta, string $text, array $events, int $now): Transition
    {
        $new = $session->next($stepId, Session::ABANDONED, $collected, $retries, $now, $this->sessions->ttlSeconds);
        $events[] = ['bot.session_abandoned', ['step' => $stepId, 'error_code' => $errorCode, 'meta' => $meta] + $collected->eventFields()];

        return new Transition($new, new BotReply($new->id, $text, [], $stepId, Session::ABANDONED), $events);
    }

    private function recordExpired(Session $session): void
    {
        $this->record($session, 'bot.session_abandoned', [
            'step' => $session->step,
            'error_code' => ErrorCodes::BOT_SESSION_EXPIRED,
            'meta' => ['expires_at' => $session->expiresAt],
        ] + $session->collected->eventFields());
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function record(Session $session, string $type, array $fields): void
    {
        $this->metrics->record($type, ['source' => self::SOURCE, 'channel' => $session->channel, 'session_id' => $session->id] + $fields);
    }

    private static function waiting(Session $session, Step $step, string $text): BotReply
    {
        return new BotReply($session->id, $text, $step->buttons, $step->id, Session::OPEN);
    }

    private static function need(?Template $template): Template
    {
        if ($template === null) {
            throw new RuntimeException('The result step is missing a text');
        }

        return $template;
    }
}
