<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Http;

use FxInstallments\App;
use FxInstallments\Http\BotMessageEndpoint;
use FxInstallments\Http\JsonResponse;
use FxInstallments\Http\Request;
use FxInstallments\Tests\Support\FakeClock;
use FxInstallments\Tests\Support\MockUpstream;
use FxInstallments\Tests\Support\TestEnvironment;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `POST /bot/message` through App::kernel() and the production route table.
 */
final class BotMessageEndpointTest extends TestCase
{
    private const ORIGIN = 'https://shop.example';

    private string $path;

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->path = TestEnvironment::freshDatabasePath();
        $this->pdo = TestEnvironment::connect($this->path);
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        self::assertTrue(TestEnvironment::removeDatabase($this->path));
    }

    public function testAMessageAnswersTheEnvelope(): void
    {
        $response = $this->post($this->app(), '{"text":""}');

        self::assertSame(200, $response->status());
        self::assertSame('application/json', $response->header('content-type'));
        $body = json_decode($response->body(), true, 8, JSON_THROW_ON_ERROR);
        self::assertTrue($body['ok']);
        self::assertSame(['session_id', 'reply', 'step', 'done'], array_keys($body['data']));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/D', $body['data']['session_id']);
        self::assertSame(['amount', false], [$body['data']['step'], $body['data']['done']]);
        self::assertSame("Hi! Tell me what you paid in USD and I'll show your monthly installment in your local currency.\nWhat was the total price in USD?", $body['data']['reply']);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM sessions WHERE channel = 'static'")->fetchColumn());
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function badRequests(): iterable
    {
        yield 'no content type' => ['{"text":"200"}', null];
        yield 'text/plain (a cross-origin simple request)' => ['{"text":"200"}', 'text/plain'];
        yield 'form encoding' => ['text=200', 'application/x-www-form-urlencoded'];
        yield 'not JSON' => ['hello', 'application/json'];
        yield 'empty body' => ['', 'application/json'];
        yield 'a JSON list' => ['["200"]', 'application/json'];
        yield 'a JSON string' => ['"200"', 'application/json'];
        yield 'no text' => ['{"session_id":null}', 'application/json'];
        yield 'empty object' => ['{}', 'application/json'];
        yield 'text as a number' => ['{"text":200}', 'application/json'];
        yield 'text as null' => ['{"text":null}', 'application/json'];
        yield 'text as an object' => ['{"text":{"amount":200}}', 'application/json'];
        yield 'session_id as a number' => ['{"session_id":1,"text":"200"}', 'application/json'];
        yield 'invalid UTF-8' => ["{\"text\":\"caf\xE9\"}", 'application/json'];
        yield 'too large' => ['{"text":"' . str_repeat('9', BotMessageEndpoint::MAX_BODY_BYTES) . '"}', 'application/json'];
    }

    #[DataProvider('badRequests')]
    public function testABodyThatCantBeReadIsABadRequest(string $body, ?string $type): void
    {
        $response = $this->post($this->app(), $body, $type === null ? [] : ['Content-Type' => $type]);

        self::assertSame(400, $response->status());
        self::assertSame(
            ['ok' => false, 'error' => ['code' => 'BAD_REQUEST', 'message_for_user' => 'Sorry, that message could not be read. Please try again.']],
            json_decode($response->body(), true, 8, JSON_THROW_ON_ERROR),
        );
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn());
        self::assertSame(['error', 'BAD_REQUEST'], $this->pdo->query('SELECT event_type, error_code FROM events')->fetch(PDO::FETCH_NUM));
    }

    public function testCharsetNullSessionAndExtraKeysAreAccepted(): void
    {
        $app = $this->app();

        $response = $this->post($app, '{"session_id":null,"text":"","channel":"typebot","extra":[1,2]}', ['Content-Type' => 'Application/JSON; charset=utf-8']);

        self::assertSame(200, $response->status(), $response->body());
        self::assertSame('static', $this->pdo->query('SELECT channel FROM sessions')->fetchColumn(), 'the body cannot pick the channel');
    }

    public function testOnlyPostIsServed(): void
    {
        $response = $this->app()->kernel()->handle(new Request('GET', '/bot/message', ['text' => '200']));

        self::assertSame(404, $response->status());
    }

    public function testAnAllowedOriginGetsCorsHeadersAndAPreflight(): void
    {
        $app = $this->app(['cors.allowed_origins' => [self::ORIGIN]]);

        $allowed = $this->post($app, '{"text":""}', ['Content-Type' => 'application/json', 'Origin' => self::ORIGIN]);
        $other = $this->post($app, '{"text":""}', ['Content-Type' => 'application/json', 'Origin' => 'https://evil.example']);
        $preflight = $app->kernel()->handle(new Request('OPTIONS', '/bot/message', [], [
            'Origin' => self::ORIGIN,
            'Access-Control-Request-Method' => 'POST',
        ]));

        self::assertSame([self::ORIGIN, 'Origin'], [$allowed->header('access-control-allow-origin'), $allowed->header('vary')]);
        self::assertNull($other->header('access-control-allow-origin'));
        self::assertSame(200, $preflight->status());
        self::assertSame(['POST', 'Content-Type'], [$preflight->header('access-control-allow-methods'), $preflight->header('access-control-allow-headers')]);
    }

    /**
     * One global `ratelimit.per_minute` for `/convert` and `/bot/message` (ADR 0016 second
     * amendment); the rate-limited answer still carries CORS headers for an allowed origin.
     */
    public function testTheRateLimitIsSharedWithConvert(): void
    {
        $app = $this->app(['ratelimit.per_minute' => 3, 'cors.allowed_origins' => [self::ORIGIN]]);
        $headers = ['Content-Type' => 'application/json', 'Origin' => self::ORIGIN];

        $statuses = [
            $app->kernel()->handle(new Request('GET', '/convert', ['amount' => 'x', 'cy' => 'BRL'], remoteAddr: '198.51.100.4'))->status(),
            $this->post($app, '{"text":""}', $headers)->status(),
            $this->post($app, '{"text":""}', $headers)->status(),
        ];
        $limited = $this->post($app, '{"text":""}', $headers);

        self::assertSame([400, 200, 200], $statuses);
        self::assertSame(429, $limited->status());
        self::assertSame(self::ORIGIN, $limited->header('access-control-allow-origin'));
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn());
    }

    public function testTheTypedTextIsNeverStored(): void
    {
        $app = $this->app();
        $id = json_decode($this->post($app, '{"text":"secret-start"}')->body(), true, 8, JSON_THROW_ON_ERROR)['data']['session_id'];

        $this->post($app, json_encode(['session_id' => $id, 'text' => 'my card 4111 1111'], JSON_THROW_ON_ERROR));
        $this->post($app, '{"text":');

        $stored = json_encode([
            $this->pdo->query('SELECT * FROM events')->fetchAll(PDO::FETCH_ASSOC),
            $this->pdo->query('SELECT * FROM requests')->fetchAll(PDO::FETCH_ASSOC),
            $this->pdo->query('SELECT * FROM sessions')->fetchAll(PDO::FETCH_ASSOC),
        ], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('secret-start', $stored);
        self::assertStringNotContainsString('4111', $stored);
        self::assertSame(['/bot/message'], array_values(array_unique($this->pdo->query('SELECT path FROM requests')->fetchAll(PDO::FETCH_COLUMN))));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function app(array $config = []): App
    {
        return TestEnvironment::app(new FakeClock(MockUpstream::LAST_UPDATE + 3600), $config, $this->pdo, upstream: new MockUpstream());
    }

    /**
     * @param array<string, string> $headers
     */
    private function post(App $app, string $body, array $headers = ['Content-Type' => 'application/json']): JsonResponse
    {
        return $app->kernel()->handle(new Request('POST', '/bot/message', [], $headers, $body, '198.51.100.4'));
    }
}
