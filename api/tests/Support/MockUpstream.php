<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Support;

use FxInstallments\Rates\ExchangeRateClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * A mocked upstream (ADR 0018): an ExchangeRateClient on a Guzzle client whose handler is a
 * MockHandler, with a history of every request it received. No test ever reaches the real
 * ExchangeRate-API.
 */
final class MockUpstream
{
    /** The suite's fake key (tests/bootstrap.php). */
    public const FAKE_KEY = 'test-fake-key-not-real';

    /** A distinctive fake key for leak tests: it must appear in no output (INV-03). */
    public const CANARY_KEY = 'LEAKCANARY7f3a9c2e5b1d48aa';

    /** 2026-10-21 00:00:01 UTC, upstream's usual update time, and the next one a day later. */
    public const LAST_UPDATE = 1792540801;
    public const NEXT_UPDATE = 1792627201;

    public readonly MockHandler $handler;

    /** @var array<int, array{request: RequestInterface, options: array<string, mixed>}> */
    public array $history = [];

    private readonly Client $http;

    /**
     * @param list<mixed> $queue responses, throwables or callables, in order
     */
    public function __construct(array $queue = [])
    {
        $this->handler = new MockHandler($queue);
        $stack = HandlerStack::create($this->handler);
        $stack->push(Middleware::history($this->history));
        $this->http = new Client(['handler' => $stack]);
    }

    public function client(?string $key = self::FAKE_KEY): ExchangeRateClient
    {
        return new ExchangeRateClient($this->http, $key);
    }

    public function append(mixed ...$values): void
    {
        $this->handler->append(...$values);
    }

    /**
     * How many requests reached the mocked upstream.
     */
    public function calls(): int
    {
        return count($this->history);
    }

    /**
     * @return list<string> every requested URL, in order
     */
    public function urls(): array
    {
        return array_values(array_map(static fn (array $entry): string => (string) $entry['request']->getUri(), $this->history));
    }

    /**
     * A `/latest/USD` success response.
     *
     * @param array<string, int|float|string> $rates code => rate; a string is sent as raw JSON
     *        number text (`'1731936.861971'`, `'1.5e-3'`)
     */
    public static function latest(array $rates = self::RATES, int $last = self::LAST_UPDATE, int $next = self::NEXT_UPDATE): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], self::latestBody($rates, $last, $next));
    }

    /**
     * The body of a `/latest/USD` success, shaped like upstream's.
     *
     * @param array<string, int|float|string> $rates
     */
    public static function latestBody(array $rates = self::RATES, int $last = self::LAST_UPDATE, int $next = self::NEXT_UPDATE): string
    {
        $members = [];
        foreach ($rates as $code => $rate) {
            $text = is_string($rate) ? $rate : json_encode($rate, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            $members[] = '  ' . json_encode((string) $code, JSON_THROW_ON_ERROR) . ':' . $text;
        }

        return "{\n"
            . " \"result\":\"success\",\n"
            . " \"documentation\":\"https://www.exchangerate-api.com/docs\",\n"
            . " \"terms_of_use\":\"https://www.exchangerate-api.com/terms\",\n"
            . " \"time_last_update_unix\":{$last},\n"
            . ' "time_last_update_utc":"' . gmdate('D, d M Y H:i:s +0000', $last) . "\",\n"
            . " \"time_next_update_unix\":{$next},\n"
            . ' "time_next_update_utc":"' . gmdate('D, d M Y H:i:s +0000', $next) . "\",\n"
            . " \"base_code\":\"USD\",\n"
            . " \"conversion_rates\":{\n" . implode(",\n", $members) . "\n }\n"
            . "}";
    }

    /**
     * An upstream error body (`{"result":"error","error-type":...}`).
     */
    public static function error(string $type, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode([
            'result' => 'error',
            'documentation' => 'https://www.exchangerate-api.com/docs',
            'terms-of-use' => 'https://www.exchangerate-api.com/terms',
            'error-type' => $type,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public static function json(int $status, string $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], $body);
    }

    /**
     * A request like the client's, for building transport exceptions whose message and request
     * carry the URL with the key, as Guzzle's do.
     */
    public static function request(string $key, string $path = 'latest/USD'): Request
    {
        return new Request('GET', ExchangeRateClient::BASE_URL . $key . '/' . $path);
    }

    /** A small table in upstream's real shape (at most 6 decimals, 7 integer digits). */
    public const RATES = [
        'USD' => 1,
        'BRL' => 5.2291,
        'COP' => 3153.773599,
        'EUR' => 0.8594,
        'JPY' => 151.468,
        'KWD' => 0.309652,
        'CLF' => 0.024825,
        'IRR' => '1731936.861971',
        'TRY' => 41.9532,
        'ALL' => 83.2014,
    ];
}
