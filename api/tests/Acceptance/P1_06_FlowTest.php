<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Acceptance;

use ArrayObject;
use Closure;
use FxInstallments\App;
use FxInstallments\Config\ConfigRepository;
use FxInstallments\Db\Connection;
use FxInstallments\Flow\BotCli;
use FxInstallments\Http\JsonResponse;
use FxInstallments\Http\Request;
use FxInstallments\Metrics\ErrorCodes;
use FxInstallments\Tests\Support\FakeClock;
use FxInstallments\Tests\Support\MockUpstream;
use FxInstallments\Tests\Support\TestEnvironment;
use GuzzleHttp\Exception\ConnectTimeoutException;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * P1-06 acceptance (docs/PLAN.md §10), on the real flow file, through App::kernel() for
 * `POST /bot/message` and BotCli (what `bin/bot.php` runs) for the CLI, with a mocked upstream
 * (ADR 0018):
 * - a scripted CLI session `200` → `brazil` → `3` ends with the uneven message and
 *   `bot.session_completed`;
 * - three unclear amounts → `bot.session_abandoned` with BOT_MAX_RETRIES at step `amount`;
 * - an expired session is recorded as BOT_SESSION_EXPIRED at its last step;
 * and the §9 engine rules: VALIDATION_INSTALLMENTS from the conversion re-asks installments with
 * the API's message_for_user and counts as a retry; an upstream error ends the session with the
 * API's message_for_user (503 text during an `unsupported-code` backoff); the stale note; the
 * three result templates; "Just one payment" and empty input; unknown and expired session ids.
 */
final class P1_06_FlowTest extends TestCase
{
    private const CLIENT = '203.0.113.7';

    private const GREETING = "Hi! Tell me what you paid in USD and I'll show your monthly installment in your local currency.\nWhat was the total price in USD?";

    private const ASK_CURRENCY = 'Which currency or country should I convert to?';

    private const ASK_INSTALLMENTS = 'How many monthly installments? (default 1)';

    private const CLARIFY_AMOUNT = "I couldn't read that amount. Please type it like 200, 200.50 or 1.000,50.";

    private const GIVE_UP = "Sorry, I couldn't follow that. Send me a new message whenever you'd like to start again.";

    private const UNEVEN_200_BRL_3 = '200.00 USD is about 1,045.82 BRL today (rate 5.2291, as of 2026-10-21 00:00 UTC).'
        . " That's 2 monthly payments of 348.60 BRL and a final payment of 348.62 BRL, so the total is exactly 1,045.82 BRL.";

    private FakeClock $clock;

    private string $path;

    private PDO $pdo;

    /** @var ArrayObject<int, string> */
    private ArrayObject $log;

    protected function setUp(): void
    {
        $this->clock = new FakeClock(MockUpstream::LAST_UPDATE + 3600);
        $this->path = TestEnvironment::freshDatabasePath();
        $this->pdo = TestEnvironment::connect($this->path);
        $this->log = new ArrayObject();
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        self::assertTrue(TestEnvironment::removeDatabase($this->path), 'the test database was released and removed');
    }

    public function testAScriptedCliSessionEndsWithTheUnevenMessage(): void
    {
        $upstream = new MockUpstream([MockUpstream::latest()]);
        $input = self::stream("200\nbrazil\n3\n");
        $output = self::stream('');

        $exit = (new BotCli($this->app($upstream)->flowEngine()))->run($input, $output);

        self::assertSame(0, $exit);
        rewind($output);
        self::assertSame(
            self::GREETING . "\n"
            . self::ASK_CURRENCY . "\n[BRL] [COP] [MXN] [EUR]\n"
            . self::ASK_INSTALLMENTS . "\n[Just one payment]\n"
            . self::UNEVEN_200_BRL_3 . "\n",
            stream_get_contents($output),
        );
        self::assertSame(1, $upstream->calls());

        $events = $this->botEvents();
        $session = $events[0]['session_id'];
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/D', $session);
        self::assertSame([
            ['bot.session_start', 'greet', 'ok', null, null, null, null, null, ['flow' => 'fx_installments']],
            ['bot.step', 'amount', 'ok', null, 20_000, null, null, null, ['outcome' => 'ok']],
            ['bot.step', 'currency', 'ok', null, 20_000, 'BRL', 'Brazil', null, ['outcome' => 'ok']],
            ['bot.step', 'installments', 'ok', null, 20_000, 'BRL', 'Brazil', 3, ['outcome' => 'ok']],
            ['bot.session_completed', 'result', 'ok', null, 20_000, 'BRL', 'Brazil', 3, ['template' => 'uneven', 'stale' => false]],
        ], array_map(self::summary(...), $events));
        foreach ($events as $event) {
            self::assertSame(['bot', 'cli', $session], [$event['source'], $event['channel'], $event['session_id']]);
        }
        self::assertSame(
            ['step' => 'result', 'status' => 'completed', 'channel' => 'cli', 'retries' => 0, 'collected' => '{"amount_usd_cents":20000,"currency":"BRL","country":"Brazil","installments":3}'],
            $this->sessionRow($session),
        );
        $this->assertNoTypedText(['brazil']);
    }

    public function testThreeUnclearAmountsAbandonWithMaxRetriesAtAmount(): void
    {
        $upstream = new MockUpstream();
        $app = $this->app($upstream);
        $start = $this->say($app, null, '');
        $id = $start['session_id'];

        $first = $this->say($app, $id, '1.500');
        $second = $this->say($app, $id, '200 3');
        $third = $this->say($app, $id, '5$5');

        foreach ([$first, $second] as $reply) {
            self::assertSame(['session_id' => $id, 'reply' => self::CLARIFY_AMOUNT, 'step' => 'amount', 'done' => false], $reply);
        }
        self::assertSame(['session_id' => $id, 'reply' => self::GIVE_UP, 'step' => 'amount', 'done' => true], $third);
        self::assertSame(0, $upstream->calls());

        $events = array_slice(array_map(self::summary(...), $this->botEvents()), 1);
        $unclear = static fn (int $retry): array => ['bot.step', 'amount', 'error', 'VALIDATION_AMOUNT', null, null, null, null, ['outcome' => 'unclear', 'reason' => 'unclear', 'retry' => $retry]];
        self::assertSame([
            $unclear(1),
            $unclear(2),
            $unclear(3),
            ['bot.session_abandoned', 'amount', 'error', 'BOT_MAX_RETRIES', null, null, null, null, ['retries' => 3]],
        ], $events);
        self::assertSame(['step' => 'amount', 'status' => 'abandoned', 'channel' => 'static', 'retries' => 3, 'collected' => '{}'], $this->sessionRow($id));
        $this->assertNoTypedText(['1.500', '200 3', '5$5']);

        // A message after the end starts a new session.
        $again = $this->say($app, $id, '200');
        self::assertNotSame($id, $again['session_id']);
        self::assertSame(self::GREETING, $again['reply']);
    }

    public function testAnExpiredSessionIsRecordedAsSessionExpiredAtItsLastStep(): void
    {
        $app = $this->app(new MockUpstream());
        $id = $this->say($app, null, '')['session_id'];
        self::assertSame('currency', $this->say($app, $id, '200')['step']);

        // bot.session_ttl_minutes = 30, from the last message: live one second before.
        $this->clock->advance(30 * 60 - 1);
        $this->say($app, null, '');
        self::assertSame('open', $this->sessionRow($id)['status']);

        // Any message sweeps it at expires_at; the user's next message then starts afresh.
        $this->clock->advance(1);
        $other = $this->say($app, null, '');
        self::assertSame('abandoned', $this->sessionRow($id)['status']);
        self::assertSame('currency', $this->sessionRow($id)['step']);

        $expired = array_values(array_filter($this->botEvents(), static fn (array $event): bool => $event['event_type'] === 'bot.session_abandoned'));
        self::assertCount(1, $expired);
        self::assertSame($id, $expired[0]['session_id']);
        self::assertSame(
            ['bot.session_abandoned', 'currency', 'error', 'BOT_SESSION_EXPIRED', 20_000, null, null, null, ['expires_at' => $this->clock->now]],
            self::summary($expired[0]),
        );
        self::assertSame('static', $expired[0]['channel']);

        $afresh = $this->say($app, $id, 'brazil');
        self::assertNotSame($id, $afresh['session_id']);
        self::assertNotSame($other['session_id'], $afresh['session_id']);
        self::assertSame(['reply' => self::GREETING, 'step' => 'amount', 'done' => false], array_slice($afresh, 1));
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM events WHERE error_code = 'BOT_SESSION_EXPIRED'")->fetchColumn(), 'recorded once');
    }

    public function testAConversionBelowOneMinorUnitReasksInstallmentsAsARetry(): void
    {
        $app = $this->app(new MockUpstream([MockUpstream::latest()]));
        $id = $this->talk($app, ['0.50', 'USD']);

        $message = ErrorCodes::messageForUser(ErrorCodes::VALIDATION_INSTALLMENTS)
            . ' 60 payments would each be less than the smallest unit of USD. Please choose fewer installments.';
        $first = $this->say($app, $id, '60');
        self::assertSame(['session_id' => $id, 'reply' => $message, 'buttons' => ['Just one payment'], 'step' => 'installments', 'done' => false], $first);
        self::assertStringStartsWith('Please choose a valid number of installments.', $first['reply'], "the API's message_for_user, verbatim");

        $second = $this->say($app, $id, '60');
        self::assertSame($message, $second['reply']);
        self::assertFalse($second['done']);

        $third = $this->say($app, $id, '60');
        self::assertSame(['session_id' => $id, 'reply' => self::GIVE_UP, 'step' => 'installments', 'done' => true], $third);

        $failures = array_values(array_filter(array_map(self::summary(...), $this->botEvents()), static fn (array $event): bool => $event[2] === 'error'));
        $retry = static fn (int $n): array => ['bot.step', 'installments', 'error', 'VALIDATION_INSTALLMENTS', 50, 'USD', null, 60, ['outcome' => 'invalid', 'reason' => 'below_minor_unit', 'retry' => $n, 'via' => 'convert']];
        self::assertSame([
            $retry(1),
            $retry(2),
            $retry(3),
            ['bot.session_abandoned', 'installments', 'error', 'BOT_MAX_RETRIES', 50, 'USD', null, null, ['retries' => 3]],
        ], $failures);
    }

    public function testAfterABelowMinorUnitRetryFewerInstallmentsComplete(): void
    {
        $app = $this->app(new MockUpstream([MockUpstream::latest()]));
        $id = $this->talk($app, ['0.50', 'USD', '60']);

        $done = $this->say($app, $id, '5');

        self::assertSame(
            "0.50 USD is about 0.50 USD today (rate 1, as of 2026-10-21 00:00 UTC). That's 5 monthly payments of 0.10 USD.",
            $done['reply'],
        );
        self::assertTrue($done['done']);
        self::assertSame(['step' => 'result', 'status' => 'completed', 'channel' => 'static', 'retries' => 1, 'collected' => '{"amount_usd_cents":50,"currency":"USD","installments":5}'], $this->sessionRow($id));
    }

    public function testAnUpstreamErrorEndsTheSessionWithTheApiMessage(): void
    {
        $upstream = new MockUpstream([new ConnectTimeoutException('timed out', MockUpstream::request(MockUpstream::FAKE_KEY))]);
        $app = $this->app($upstream);
        $id = $this->talk($app, ['200', 'brazil']);

        $end = $this->say($app, $id, '3');

        self::assertSame(
            ['session_id' => $id, 'reply' => ErrorCodes::messageForUser(ErrorCodes::UPSTREAM_TIMEOUT), 'step' => 'result', 'done' => true],
            $end,
        );
        $last = $this->botEvents();
        self::assertSame(
            ['bot.session_abandoned', 'result', 'error', 'UPSTREAM_TIMEOUT', 20_000, 'BRL', 'Brazil', 3, ['reason' => 'timeout', 'http_status' => 503]],
            self::summary($last[count($last) - 1]),
        );
        self::assertSame('abandoned', $this->sessionRow($id)['status']);
    }

    public function testDuringAnUnsupportedCodeBackoffTheSessionEndsWithThe503Message(): void
    {
        $upstream = new MockUpstream([MockUpstream::error('unsupported-code', 404)]);
        $app = $this->app($upstream);

        $replies = [];
        foreach (['BRL', 'EUR'] as $currency) {
            $id = $this->talk($app, ['200', $currency]);
            $replies[] = $this->say($app, $id, '');
        }

        foreach ($replies as $reply) {
            self::assertSame('Exchange rates are temporarily unavailable. Please try again later.', $reply['reply']);
            self::assertSame(['result', true], [$reply['step'], $reply['done']]);
        }
        self::assertSame(1, $upstream->calls(), 'the backoff stops further calls');
        $abandoned = array_values(array_filter($this->botEvents(), static fn (array $event): bool => $event['event_type'] === 'bot.session_abandoned'));
        self::assertSame(
            [['UPSTREAM_UNSUPPORTED_CODE', 'unsupported-code', 503], ['UPSTREAM_UNSUPPORTED_CODE', 'blocked_backoff', 503]],
            array_map(static fn (array $event): array => [$event['error_code'], $event['meta']['reason'], $event['meta']['http_status']], $abandoned),
        );
    }

    public function testAStaleRateAddsTheStaleNote(): void
    {
        $upstream = new MockUpstream([
            MockUpstream::latest(),
            new ConnectTimeoutException('timed out', MockUpstream::request(MockUpstream::FAKE_KEY)),
        ]);
        $app = $this->app($upstream);
        $fresh = $this->say($app, $this->talk($app, ['200', 'brazil']), '3');

        $this->clock->now = MockUpstream::NEXT_UPDATE + 60;
        $stale = $this->say($app, $this->talk($app, ['200', 'brazil']), '3');

        self::assertSame(self::UNEVEN_200_BRL_3, $fresh['reply']);
        self::assertSame(self::UNEVEN_200_BRL_3 . "\nRates couldn't be refreshed just now, so this uses the last known rate.", $stale['reply']);
        $completed = array_values(array_filter($this->botEvents(), static fn (array $event): bool => $event['event_type'] === 'bot.session_completed'));
        self::assertSame([false, true], array_map(static fn (array $event): bool => $event['meta']['stale'], $completed));
    }

    public function testTheResultTemplateFollowsTheSplit(): void
    {
        $app = $this->app(new MockUpstream([MockUpstream::latest()]));

        $single = $this->say($app, $this->talk($app, ['100', 'usd']), '1');
        $even = $this->say($app, $this->talk($app, ['300', 'usd']), '3');
        $uneven = $this->say($app, $this->talk($app, ['100', 'usd']), '3');

        self::assertSame("100.00 USD is about 100.00 USD today (rate 1, as of 2026-10-21 00:00 UTC). That's a single payment of 100.00 USD.", $single['reply']);
        self::assertSame("300.00 USD is about 300.00 USD today (rate 1, as of 2026-10-21 00:00 UTC). That's 3 monthly payments of 100.00 USD.", $even['reply']);
        self::assertSame(
            "100.00 USD is about 100.00 USD today (rate 1, as of 2026-10-21 00:00 UTC). That's 2 monthly payments of 33.33 USD and a final payment of 33.34 USD, so the total is exactly 100.00 USD.",
            $uneven['reply'],
        );
        $completed = array_values(array_filter($this->botEvents(), static fn (array $event): bool => $event['event_type'] === 'bot.session_completed'));
        self::assertSame(['single', 'even', 'uneven'], array_map(static fn (array $event): string => $event['meta']['template'], $completed));
    }

    public function testJustOnePaymentAndEmptyInputUseTheDefault(): void
    {
        $app = $this->app(new MockUpstream([MockUpstream::latest()]));
        $single = "1,000,000.00 USD is about 859,400.00 EUR today (rate 0.8594, as of 2026-10-21 00:00 UTC). That's a single payment of 859,400.00 EUR.";

        foreach (['Just one payment', '  just ONE payment ', '', '   '] as $answer) {
            $done = $this->say($app, $this->talk($app, ['1.000.000', 'euros']), $answer);
            self::assertSame($single, $done['reply'], var_export($answer, true));
            self::assertTrue($done['done']);
        }

        $defaults = array_values(array_filter($this->botEvents(), static fn (array $event): bool => $event['event_type'] === 'bot.step' && $event['step'] === 'installments'));
        self::assertCount(4, $defaults);
        foreach ($defaults as $event) {
            self::assertSame(['installments' => 1, 'meta' => ['outcome' => 'ok', 'default' => true]], ['installments' => $event['installments'], 'meta' => $event['meta']]);
        }
    }

    public function testUnknownAndMalformedSessionIdsStartANewSession(): void
    {
        $app = $this->app(new MockUpstream());

        foreach ([str_repeat('f', 32), 'not-a-session', str_repeat('A', 32), ''] as $id) {
            $reply = $this->say($app, $id, '200');
            self::assertNotSame($id, $reply['session_id']);
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/D', $reply['session_id']);
            self::assertSame(['reply' => self::GREETING, 'step' => 'amount', 'done' => false], array_slice($reply, 1), 'the first message of a session is not read as an answer');
        }
        self::assertSame(4, (int) $this->pdo->query("SELECT COUNT(*) FROM sessions WHERE status = 'open' AND step = 'amount'")->fetchColumn());
        self::assertSame(4, (int) $this->pdo->query("SELECT COUNT(*) FROM events WHERE event_type = 'bot.session_start' AND channel = 'static'")->fetchColumn());
    }

    /**
     * @param list<string> $answers
     *
     * @return string the session id, after answering $answers in a new session
     */
    private function talk(App $app, array $answers): string
    {
        $id = $this->say($app, null, '')['session_id'];
        foreach ($answers as $answer) {
            $reply = $this->say($app, $id, $answer);
            self::assertFalse($reply['done'], $answer);
        }

        return $id;
    }

    /**
     * @return array<string, mixed> the response data
     */
    private function say(App $app, ?string $sessionId, string $text): array
    {
        $body = ['text' => $text];
        if ($sessionId !== null) {
            $body = ['session_id' => $sessionId] + $body;
        }
        $response = $app->kernel()->handle(new Request(
            'POST',
            '/bot/message',
            [],
            ['Content-Type' => 'application/json'],
            json_encode($body, JSON_THROW_ON_ERROR),
            self::CLIENT,
        ));

        return self::data($response);
    }

    private function app(MockUpstream $upstream): App
    {
        $log = $this->log;

        return new App(
            new ConfigRepository(['ratelimit.per_minute' => 1000]),
            Connection::wrap($this->pdo),
            Closure::fromCallable($this->clock),
            static function (string $line) use ($log): void {
                $log[] = $line;
            },
            $upstream->client(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function data(JsonResponse $response): array
    {
        self::assertSame(200, $response->status(), $response->body());
        $body = json_decode($response->body(), true, 8, JSON_THROW_ON_ERROR);
        self::assertTrue($body['ok'], $response->body());

        return $body['data'];
    }

    /**
     * @return list<array<string, mixed>> bot events in order, integers cast and meta decoded
     */
    private function botEvents(): array
    {
        $rows = $this->pdo->query(
            "SELECT event_type, source, channel, session_id, step, status, error_code, amount_usd_cents, currency, country, installments, meta FROM events WHERE source = 'bot' ORDER BY id",
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static function (array $row): array {
            foreach (['amount_usd_cents', 'installments'] as $column) {
                $row[$column] = $row[$column] === null ? null : (int) $row[$column];
            }
            $row['meta'] = $row['meta'] === null ? null : json_decode((string) $row['meta'], true, 8, JSON_THROW_ON_ERROR);

            return $row;
        }, $rows);
    }

    /**
     * @param array<string, mixed> $event
     * @return list<mixed> type, step, status, error code, cents, currency, country, installments, meta
     */
    private static function summary(array $event): array
    {
        return [
            $event['event_type'], $event['step'], $event['status'], $event['error_code'], $event['amount_usd_cents'],
            $event['currency'], $event['country'], $event['installments'], $event['meta'],
        ];
    }

    /**
     * @return array{step: string, status: string, channel: string, retries: int, collected: string}
     */
    private function sessionRow(string $id): array
    {
        $statement = $this->pdo->prepare('SELECT step, status, channel, retries, collected FROM sessions WHERE session_id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        $row['retries'] = (int) $row['retries'];

        return $row;
    }

    /**
     * What the user typed never reaches `events` (only validated values and fixed words do).
     *
     * @param list<string> $typed
     */
    private function assertNoTypedText(array $typed): void
    {
        $all = json_encode($this->pdo->query('SELECT * FROM events')->fetchAll(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        foreach ($typed as $text) {
            self::assertStringNotContainsString($text, $all);
        }
        self::assertStringNotContainsString(self::CLIENT, $all);
    }

    /**
     * @return resource
     */
    private static function stream(string $contents)
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }
}
