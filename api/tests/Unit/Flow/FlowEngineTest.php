<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Flow;

use ArrayObject;
use Closure;
use FxInstallments\App;
use FxInstallments\Config\ConfigRepository;
use FxInstallments\Db\Connection;
use FxInstallments\Flow\BotReply;
use FxInstallments\Flow\FlowEngine;
use FxInstallments\Tests\Support\FakeClock;
use FxInstallments\Tests\Support\HookedPdo;
use FxInstallments\Tests\Support\MockUpstream;
use FxInstallments\Tests\Support\TestEnvironment;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Engine transitions, retries and clarifications on the real flow file (docs/PLAN.md §9).
 */
final class FlowEngineTest extends TestCase
{
    private const RETRY_CURRENCY = "I don't know that currency. Try a code like BRL or a country like Brazil.";

    private FakeClock $clock;

    private string $path;

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->clock = new FakeClock(MockUpstream::LAST_UPDATE + 3600);
        $this->path = TestEnvironment::freshDatabasePath();
        $this->pdo = TestEnvironment::connect($this->path);
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        self::assertTrue(TestEnvironment::removeDatabase($this->path));
    }

    public function testEachStepAsksTheNextQuestionWithItsButtons(): void
    {
        $engine = $this->engine();

        $start = $engine->handle(null, 'hello', 'static');
        $amount = $engine->handle($start->sessionId, '1.000,50', 'static');
        $currency = $engine->handle($start->sessionId, 'México', 'static');

        self::assertSame(['amount', [], false], [$start->step, $start->buttons, $start->done()]);
        self::assertSame(['currency', ['BRL', 'COP', 'MXN', 'EUR'], 'Which currency or country should I convert to?'], [$amount->step, $amount->buttons, $amount->reply]);
        self::assertSame(['installments', ['Just one payment'], 'How many monthly installments? (default 1)'], [$currency->step, $currency->buttons, $currency->reply]);
        self::assertSame('{"amount_usd_cents":100050,"currency":"MXN","country":"Mexico"}', $this->column($start->sessionId, 'collected'));
        self::assertSame(['session_id' => $start->sessionId, 'reply' => $currency->reply, 'buttons' => ['Just one payment'], 'step' => 'installments', 'done' => false], $currency->toArray());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function amountAnswers(): iterable
    {
        $clarify = "I couldn't read that amount. Please type it like 200, 200.50 or 1.000,50.";
        yield 'unclear: three decimals after a dot' => ['200.505', $clarify];
        yield 'unclear: exponent' => ['1e3', $clarify];
        yield 'unclear: words' => ['two hundred', $clarify];
        yield 'unclear: empty' => ['', $clarify];
        yield 'invalid: zero' => ['0', 'The total must be more than 0. Please type the total in USD, for example 200 or 200.50.'];
        yield 'invalid: over amount.max' => ['1.000.001', "That's more than I can convert. Please type a smaller total in USD."];
        yield 'invalid: over 7 digits' => ['12.345.678', "That's more than I can convert. Please type a smaller total in USD."];
    }

    #[DataProvider('amountAnswers')]
    public function testUnclearAndInvalidAmountsGetTheirOwnWording(string $text, string $reply): void
    {
        $engine = $this->engine();
        $id = $engine->handle(null, '', 'static')->sessionId;

        $answer = $engine->handle($id, $text, 'static');

        self::assertSame([$reply, 'amount', false], [$answer->reply, $answer->step, $answer->done()]);
        self::assertSame('1', $this->column($id, 'retries'));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function currencyAnswers(): iterable
    {
        $clarify = 'Several currencies use that name or symbol. Please type the currency code, like MXN or COP.';
        yield 'ambiguous symbol' => ['$', $clarify, 'ambiguous'];
        yield 'ambiguous name' => ['pesos', $clarify, 'ambiguous'];
        yield 'ambiguous won (P1-06)' => ['won', $clarify, 'ambiguous'];
        yield 'ambiguous lira (P1-06)' => ['Lira', $clarify, 'ambiguous'];
        yield 'code word in lowercase' => ['try', $clarify, 'ambiguous'];
        yield 'unknown' => ['bitcoin', self::RETRY_CURRENCY, 'unknown'];
        yield 'empty' => ['', self::RETRY_CURRENCY, 'format'];
        yield 'not on the allow-list' => ['XAU', "I can't convert to that currency right now. Try a code like BRL or a country like Brazil.", 'not_allowed'];
    }

    #[DataProvider('currencyAnswers')]
    public function testUnclearAndInvalidCurrenciesGetTheirOwnWording(string $text, string $reply, string $reason): void
    {
        $engine = $this->engine();
        $id = $engine->handle(null, '', 'static')->sessionId;
        $engine->handle($id, '200', 'static');

        $answer = $engine->handle($id, $text, 'static');

        self::assertSame([$reply, 'currency', ['BRL', 'COP', 'MXN', 'EUR']], [$answer->reply, $answer->step, $answer->buttons], 'buttons stay on a retry');
        $event = $this->events('bot.step')[1];
        self::assertSame(['currency', 'VALIDATION_CURRENCY', $reason], [$event['step'], $event['error_code'], $event['meta']['reason']]);
        self::assertSame($reason === 'ambiguous' ? 'unclear' : 'invalid', $event['meta']['outcome']);
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function countries(): iterable
    {
        yield 'a country is recorded' => ['Bolivia', 'Bolivia'];
        yield 'a Spanish name is recorded in English' => ['República Dominicana', 'Dominican Republic'];
        yield 'a code is not a country' => ['BRL', null];
        yield 'a synonym is not a country' => ['yuan', null];
        yield 'bob is the code (P1-06)' => ['bob', null];
    }

    #[DataProvider('countries')]
    public function testACountryIsRecordedOnlyWhenTyped(string $text, ?string $country): void
    {
        $engine = $this->engine();
        $id = $engine->handle(null, '', 'static')->sessionId;
        $engine->handle($id, '200', 'static');

        self::assertSame('installments', $engine->handle($id, $text, 'static')->step);
        self::assertSame($country, $this->events('bot.step')[1]['country']);
    }

    public function testRetriesStartAgainAtEachStep(): void
    {
        $engine = $this->engine();
        $id = $engine->handle(null, '', 'static')->sessionId;

        $engine->handle($id, '1.500', 'static');
        $engine->handle($id, '1,500', 'static');
        $engine->handle($id, 'Atlantis', 'static');
        $second = $engine->handle($id, 'Narnia', 'static');

        // Three failures in the session, but at most two at one step: still open.
        self::assertSame(['currency', false, self::RETRY_CURRENCY], [$second->step, $second->done(), $second->reply]);
        self::assertSame('2', $this->column($id, 'retries'));

        $third = $engine->handle($id, 'Oz', 'static');
        self::assertSame(['currency', true], [$third->step, $third->done()]);
        $abandoned = $this->events('bot.session_abandoned');
        self::assertSame(['currency', 'BOT_MAX_RETRIES', 150_000], [$abandoned[0]['step'], $abandoned[0]['error_code'], $abandoned[0]['amount_usd_cents']]);
    }

    public function testInstallmentsAnswers(): void
    {
        $engine = $this->engine(new MockUpstream([MockUpstream::latest()]));
        $id = $this->answered($engine, ['200', 'usd']);

        $letters = $engine->handle($id, 'three', 'static');
        $tooMany = $engine->handle($id, '61', 'static');
        self::assertSame(['Please type a whole number from 1 to 60.', 'installments'], [$letters->reply, $letters->step]);
        self::assertSame($letters->reply, $tooMany->reply);

        $done = $engine->handle($id, '03', 'static');
        self::assertTrue($done->done());
        self::assertStringEndsWith("That's 2 monthly payments of 66.66 USD and a final payment of 66.68 USD, so the total is exactly 200.00 USD.", $done->reply);
        self::assertSame(['format', 'out_of_range'], array_map(static fn (array $event): string => $event['meta']['reason'], array_slice($this->events('bot.step'), 2, 2)));
    }

    /**
     * A total of 2^53 minor units or more (`too_large`, ConvertService) re-asks the amount with
     * the flow's text, keeping the currency and installments.
     */
    public function testATooLargeTotalReasksTheAmount(): void
    {
        $engine = $this->engine(new MockUpstream([MockUpstream::latest(['USD' => 1, 'KWD' => '9007199.254740992'])]));
        $id = $this->answered($engine, ['1000000', 'KWD']);

        $again = $engine->handle($id, '2', 'static');

        self::assertSame(['That total is too large to convert to KWD. Please type a smaller total in USD.', 'amount', false, []], [$again->reply, $again->step, $again->done(), $again->buttons]);
        self::assertSame('{"currency":"KWD","installments":2}', $this->column($id, 'collected'));
        self::assertSame('1', $this->column($id, 'retries'));
        $event = $this->events('bot.step')[3];
        self::assertSame(['amount', 'VALIDATION_AMOUNT', 'too_large', 'convert'], [$event['step'], $event['error_code'], $event['meta']['reason'], $event['meta']['via']]);

        $done = $engine->handle($id, '100', 'static');
        self::assertTrue($done->done());
        self::assertSame('100.00 USD is about 900,719,925.474 KWD today (rate 9,007,199.254741, as of 2026-10-21 00:00 UTC). That\'s 2 monthly payments of 450,359,962.737 KWD.', $done->reply);
    }

    /**
     * Owner decision 2026-10-21: a message that loses the save to another message for the same
     * session, which moved it to another step, is never read against a question the user
     * didn't see; it gets the current question, with no event and no retry.
     */
    public function testALosingMessageAfterTheStepMovedGetsTheCurrentQuestion(): void
    {
        [$engine, $other, $hooked] = $this->racingEngines();
        $id = $engine->handle(null, '', 'static')->sessionId;

        $hooked->before('UPDATE sessions SET step', static function () use ($other, $id): void {
            self::assertSame('currency', $other->handle($id, '300', 'static')->step);
        });
        $reply = $engine->handle($id, '200', 'static');

        self::assertSame(['Which currency or country should I convert to?', 'currency', ['BRL', 'COP', 'MXN', 'EUR'], false], [$reply->reply, $reply->step, $reply->buttons, $reply->done()]);
        self::assertSame(['{"amount_usd_cents":30000}', '0'], [$this->column($id, 'collected'), $this->column($id, 'retries')]);
        self::assertSame([['amount', 'ok', 30_000]], $this->stepEvents(), 'the losing message wrote no event');
    }

    public function testADuplicateCurrencyDoesNotCostAnInstallmentsRetry(): void
    {
        [$engine, $other, $hooked] = $this->racingEngines();
        $id = $this->answered($engine, ['200']);

        $hooked->before('UPDATE sessions SET step', static function () use ($other, $id): void {
            self::assertSame('installments', $other->handle($id, 'BRL', 'static')->step);
        });
        $duplicate = $engine->handle($id, 'BRL', 'static');

        self::assertSame(['How many monthly installments? (default 1)', 'installments', ['Just one payment']], [$duplicate->reply, $duplicate->step, $duplicate->buttons]);
        self::assertSame('0', $this->column($id, 'retries'));
        self::assertSame([['amount', 'ok', 20_000], ['currency', 'ok', 20_000]], $this->stepEvents());
    }

    public function testADuplicateFinalAnswerAfterCompletionIsDoneWithoutANewSession(): void
    {
        [$engine, $other, $hooked] = $this->racingEngines(new MockUpstream([MockUpstream::latest()]));
        $id = $this->answered($engine, ['200', 'BRL']);

        $hooked->before('UPDATE sessions SET step', static function () use ($other, $id): void {
            self::assertTrue($other->handle($id, '3', 'static')->done());
        });
        $duplicate = $engine->handle($id, '3', 'static');

        self::assertSame(['session_id' => $id, 'reply' => '', 'step' => 'result', 'done' => true], $duplicate->toArray());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn());
        self::assertCount(1, $this->events('bot.session_start'));
        self::assertCount(1, $this->events('bot.session_completed'));
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function sweepEventWrites(): iterable
    {
        yield 'the sweep records the expiry' => [false];
        yield 'the sweep\'s event write fails' => [true];
    }

    /**
     * L-04 (ADR 0021's known limit): a message read against a live session, whose session
     * another process's sweep closed as expired before the save, is answered like an expired
     * id: a new session and the greeting, not `done: true` with an empty reply.
     */
    #[DataProvider('sweepEventWrites')]
    public function testAMessageInFlightWhenExpiryClosesItsSessionStartsANewOne(bool $sweepEventFails): void
    {
        [$engine, , $hooked] = $this->racingEngines();
        $id = $this->answered($engine, ['200']);
        $greeting = $engine->handle(null, '', 'cli')->reply;
        if ($sweepEventFails) {
            // INV-07: the sweep's event write can fail; the case is told from the session row.
            $this->pdo->exec("CREATE TRIGGER l04_no_expiry_event BEFORE INSERT ON events WHEN NEW.error_code = 'BOT_SESSION_EXPIRED' BEGIN SELECT RAISE(ABORT, 'l-04 test'); END");
        }

        // Another process, whose clock is past the session's expiry, sweeps just before the save.
        $later = new FakeClock($this->clock->now + 1800);
        $sweeper = TestEnvironment::app($later, pdo: TestEnvironment::connect($this->path))->sessionSweeper();
        $hooked->before('UPDATE sessions SET step', static function () use ($sweeper): void {
            self::assertSame(2, $sweeper->sweep());
        });
        $reply = $engine->handle($id, 'BRL', 'static');

        self::assertNotSame($id, $reply->sessionId);
        self::assertSame(['reply' => $greeting, 'step' => 'amount', 'done' => false], array_diff_key($reply->toArray(), ['session_id' => true]));
        self::assertStringContainsString("\n", $greeting, 'the greeting and the first question');
        self::assertSame('abandoned', $this->column($id, 'status'));
        self::assertSame('open', $this->column($reply->sessionId, 'status'));
        self::assertSame('static', $this->column($reply->sessionId, 'channel'));
        // The sweep wrote the expiry (unless its write failed); the losing message wrote only
        // its new session's start.
        self::assertSame($sweepEventFails ? [] : ['BOT_SESSION_EXPIRED', 'BOT_SESSION_EXPIRED'], array_column($this->events('bot.session_abandoned'), 'error_code'));
        self::assertSame([['amount', 'ok', 20_000]], $this->stepEvents(), 'the losing message wrote no bot.step');
        self::assertCount(3, $this->events('bot.session_start'));
    }

    /**
     * The other closed cases keep ADR 0021 item 6: a session the winning message closed is
     * `done` with an empty reply, even when its row shows an expiry in the past.
     */
    public function testASessionAbandonedByTheWinnerIsStillDone(): void
    {
        [$engine, $other, $hooked] = $this->racingEngines(config: ['bot.max_retries' => 1]);
        $id = $engine->handle(null, '', 'static')->sessionId;

        $hooked->before('UPDATE sessions SET step', static function () use ($other, $id): void {
            self::assertTrue($other->handle($id, 'abc', 'static')->done());
        });
        $duplicate = $engine->handle($id, 'abc', 'static');

        self::assertSame(['session_id' => $id, 'reply' => '', 'step' => 'amount', 'done' => true], $duplicate->toArray());
        self::assertCount(1, $this->events('bot.session_start'));
        self::assertSame(['BOT_MAX_RETRIES'], array_column($this->events('bot.session_abandoned'), 'error_code'));
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function expiriesAtClose(): iterable
    {
        yield 'expires_at moved to now: a message closed it' => [true];
        yield 'expires_at as this message read it: the sweep closed it' => [false];
    }

    /**
     * L-04 cleanup B (mutation M13): the sweep's close is told from a message's by `expires_at`
     * alone too. The other process here closes the row as abandoned and leaves the step, the
     * retries and the collected values as this message read them; only `expires_at` tells a
     * message's close (it sets `expires_at` to now) from the sweep's (it leaves it). The first
     * is item 6's `done` with an empty reply, the second a new session (ADR 0021 second
     * amendment).
     */
    #[DataProvider('expiriesAtClose')]
    public function testOnlyExpiresAtTellsAMessagesCloseFromTheSweeps(bool $expiryMoved): void
    {
        [$engine, , $hooked] = $this->racingEngines();
        $id = $this->answered($engine, ['200']);
        $now = $this->clock->now;
        self::assertNotSame($now, (int) $this->column($id, 'expires_at'), 'the session expires after now');

        $close = $expiryMoved
            ? "UPDATE sessions SET status = 'abandoned', updated_at = {$now}, expires_at = {$now} WHERE session_id = '{$id}'"
            : "UPDATE sessions SET status = 'abandoned', updated_at = {$now} WHERE session_id = '{$id}'";
        $pdo = TestEnvironment::connect($this->path);
        $hooked->before('UPDATE sessions SET step', static function () use ($pdo, $close): void {
            self::assertSame(1, $pdo->exec($close));
        });
        $reply = $engine->handle($id, 'BRL', 'static');

        if ($expiryMoved) {
            self::assertSame(['session_id' => $id, 'reply' => '', 'step' => 'currency', 'done' => true], $reply->toArray());
            self::assertCount(1, $this->events('bot.session_start'));
        } else {
            self::assertNotSame($id, $reply->sessionId);
            self::assertSame(['amount', false], [$reply->step, $reply->done()]);
            self::assertCount(2, $this->events('bot.session_start'));
        }
        self::assertSame([['amount', 'ok', 20_000]], $this->stepEvents(), 'the losing message wrote no bot.step');
    }

    public function testALosingMessageOnTheSameStepIsReadAgain(): void
    {
        [$engine, $other, $hooked] = $this->racingEngines();
        $id = $engine->handle(null, '', 'static')->sessionId;

        // The winner was a retry at the same step, so this message still answers that question.
        $hooked->before('UPDATE sessions SET step', static function () use ($other, $id): void {
            self::assertSame('amount', $other->handle($id, 'abc', 'static')->step);
        });
        $reply = $engine->handle($id, '200', 'static');

        self::assertSame('currency', $reply->step);
        self::assertSame(['{"amount_usd_cents":20000}', '0'], [$this->column($id, 'collected'), $this->column($id, 'retries')]);
        self::assertSame([['amount', 'error', 0], ['amount', 'ok', 20_000]], $this->stepEvents());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function maxRetries(): iterable
    {
        yield 'bot.max_retries = 2' => [2];
        yield 'bot.max_retries = 5' => [5];
    }

    #[DataProvider('maxRetries')]
    public function testTheSessionIsAbandonedAtBotMaxRetries(int $max): void
    {
        $engine = $this->engine(config: ['bot.max_retries' => $max]);
        $id = $engine->handle(null, '', 'static')->sessionId;

        for ($failure = 1; $failure < $max; $failure++) {
            self::assertFalse($engine->handle($id, 'x', 'static')->done(), "failure {$failure} of {$max}");
        }
        $last = $engine->handle($id, 'x', 'static');

        self::assertSame(['amount', true], [$last->step, $last->done()]);
        self::assertSame([['BOT_MAX_RETRIES', '{"retries":' . $max . '}']], array_map(static fn (array $event): array => [$event['error_code'], json_encode($event['meta'])], $this->events('bot.session_abandoned')));
    }

    public function testAnUnexpectedConversionErrorReasksNoStep(): void
    {
        $reask = new \ReflectionMethod(FlowEngine::class, 'stepToReask');

        self::assertSame('currency', $reask->invoke($this->engine(), 'VALIDATION_CURRENCY')->id);
        self::assertSame('currency', $reask->invoke($this->engine(), 'UPSTREAM_UNSUPPORTED_CODE')->id);
        $this->expectException(\LogicException::class);
        $reask->invoke($this->engine(), 'UPSTREAM_TIMEOUT');
    }

    public function testASessionAtAStepTheFlowNoLongerAsksStartsAgain(): void
    {
        $engine = $this->engine();
        $id = $engine->handle(null, '', 'static')->sessionId;
        $this->pdo->exec("UPDATE sessions SET step = 'country' WHERE session_id = '{$id}'");

        $reply = $engine->handle($id, '200', 'static');

        self::assertNotSame($id, $reply->sessionId);
        self::assertSame('amount', $reply->step);
    }

    public function testASessionKeepsTheChannelItStartedOn(): void
    {
        $engine = $this->engine();
        $id = $engine->handle(null, '', 'cli')->sessionId;
        $engine->handle($id, '200', 'static');

        self::assertSame(['cli', 'cli'], array_column($this->events(), 'channel'));
    }

    public function testAnUnknownChannelIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->engine()->handle(null, '', 'telegram');
    }

    public function testEveryMessageSweeps(): void
    {
        $engine = $this->engine();
        $engine->handle(null, '', 'static');
        $this->clock->advance(1800);

        $engine->handle(null, '', 'static');

        self::assertSame(['BOT_SESSION_EXPIRED'], array_column($this->events('bot.session_abandoned'), 'error_code'));
    }

    /**
     * Two engines on one database file: the first on a HookedPdo whose hook runs the second's
     * message just before the first saves.
     *
     * @param array<string, mixed> $config
     * @return array{FlowEngine, FlowEngine, HookedPdo}
     */
    private function racingEngines(?MockUpstream $upstream = null, array $config = []): array
    {
        $hooked = HookedPdo::open($this->path);

        return [$this->engine($upstream, $hooked, $config), $this->engine(pdo: TestEnvironment::connect($this->path), config: $config), $hooked];
    }

    /**
     * @return list<array{string, string, int}> step, status, cents of each bot.step event
     */
    private function stepEvents(): array
    {
        return array_map(static fn (array $event): array => [$event['step'], $event['status'], (int) $event['amount_usd_cents']], $this->events('bot.step'));
    }

    /**
     * @param list<string> $answers
     */
    private function answered(FlowEngine $engine, array $answers): string
    {
        $id = $engine->handle(null, '', 'static')->sessionId;
        foreach ($answers as $answer) {
            self::assertInstanceOf(BotReply::class, $engine->handle($id, $answer, 'static'));
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function engine(?MockUpstream $upstream = null, ?PDO $pdo = null, array $config = []): FlowEngine
    {
        $log = new ArrayObject();

        return (new App(
            new ConfigRepository($config),
            Connection::wrap($pdo ?? $this->pdo),
            Closure::fromCallable($this->clock),
            static function (string $line) use ($log): void {
                $log[] = $line;
            },
            ($upstream ?? new MockUpstream())->client(),
        ))->flowEngine();
    }

    private function column(string $id, string $column): string
    {
        $statement = $this->pdo->prepare("SELECT {$column} FROM sessions WHERE session_id = ?");
        $statement->execute([$id]);

        return (string) $statement->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function events(?string $type = null): array
    {
        $rows = $this->pdo->query("SELECT * FROM events WHERE source = 'bot' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $rows = array_map(static function (array $row): array {
            $row['meta'] = $row['meta'] === null ? null : json_decode((string) $row['meta'], true, 8, JSON_THROW_ON_ERROR);

            return $row;
        }, $rows);

        return array_values(array_filter($rows, static fn (array $row): bool => $type === null || $row['event_type'] === $type));
    }
}
