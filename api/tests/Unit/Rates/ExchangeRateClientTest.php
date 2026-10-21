<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Rates;

use FxInstallments\Metrics\ErrorCodes;
use FxInstallments\Rates\ApiKey;
use FxInstallments\Rates\ExchangeRateClient;
use FxInstallments\Rates\UpstreamError;
use FxInstallments\Tests\Support\MockUpstream;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use GuzzleHttp\Exception\TooManyRedirectsException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * ExchangeRateClient against a mocked handler (ADR 0018): fixed URLs (INV-05), every §3b error
 * mapping, transport failures, malformed bodies, per-currency drops, decimal-text scaling and
 * exception scrubbing (INV-03).
 */
final class ExchangeRateClientTest extends TestCase
{
    public function testLatestScalesEveryRateFromUpstreamsDecimalText(): void
    {
        $upstream = new MockUpstream([MockUpstream::latest()]);

        $latest = $upstream->client()->latest();

        self::assertSame(MockUpstream::LAST_UPDATE, $latest->lastUpdateUnix);
        self::assertSame(MockUpstream::NEXT_UPDATE, $latest->nextUpdateUnix);
        self::assertSame([], $latest->dropped);
        self::assertSame([
            'ALL' => 832_014_000_000,
            'BRL' => 52_291_000_000,
            'CLF' => 248_250_000,
            'COP' => 31_537_735_990_000,
            'EUR' => 8_594_000_000,
            'IRR' => 17_319_368_619_710_000,
            'JPY' => 1_514_680_000_000,
            'KWD' => 3_096_520_000,
            'TRY' => 419_532_000_000,
            'USD' => 10_000_000_000,
        ], $latest->rates);
    }

    public function testOnlyTheThreeFixedUrlsAreEverRequested(): void
    {
        $upstream = new MockUpstream([
            MockUpstream::latest(),
            MockUpstream::json(200, '{"result":"success","supported_codes":[["USD","United States Dollar"]]}'),
            MockUpstream::json(200, '{"result":"success","plan_quota":1500,"requests_remaining":1480,"refresh_day_of_month":17}'),
        ]);
        $client = $upstream->client();

        $client->latest();
        $client->codes();
        $client->quota();

        self::assertSame([
            'https://v6.exchangerate-api.com/v6/test-fake-key-not-real/latest/USD',
            'https://v6.exchangerate-api.com/v6/test-fake-key-not-real/codes',
            'https://v6.exchangerate-api.com/v6/test-fake-key-not-real/quota',
        ], $upstream->urls());
        foreach ($upstream->history as $entry) {
            self::assertSame('GET', $entry['request']->getMethod());
            self::assertSame('', (string) $entry['request']->getBody());
            self::assertSame('', $entry['request']->getUri()->getQuery());
        }
    }

    public function testNoPublicMethodTakesInputThatCouldReachTheUrl(): void
    {
        // INV-05: the only inputs are the injected client and the key.
        $reflection = new \ReflectionClass(ExchangeRateClient::class);
        foreach (['latest', 'codes', 'quota'] as $method) {
            self::assertSame(0, $reflection->getMethod($method)->getNumberOfParameters(), $method);
        }
    }

    public function testEveryRequestHasTheTimeoutAndNoRedirects(): void
    {
        $upstream = new MockUpstream([MockUpstream::latest()]);

        $upstream->client()->latest();
        $options = $upstream->handler->getLastOptions();

        self::assertSame(4, ExchangeRateClient::TIMEOUT_SECONDS);
        self::assertSame(4, $options['timeout']);
        self::assertSame(4, $options['connect_timeout']);
        self::assertFalse($options['http_errors']);
        self::assertFalse($options['allow_redirects']);
        self::assertFalse($options['decode_content']);
        self::assertSame('identity', $upstream->history[0]['request']->getHeaderLine('Accept-Encoding'));
        self::assertIsCallable($options['on_headers']);
        self::assertIsCallable($options['progress']);
    }

    /**
     * @return iterable<string, array{string, int, string, string}>
     */
    public static function errorTypes(): iterable
    {
        yield 'unsupported-code' => ['unsupported-code', 404, ErrorCodes::UPSTREAM_UNSUPPORTED_CODE, 'unsupported-code'];
        yield 'quota-reached' => ['quota-reached', 429, ErrorCodes::UPSTREAM_QUOTA, 'quota-reached'];
        yield 'quota-reached with 200' => ['quota-reached', 200, ErrorCodes::UPSTREAM_QUOTA, 'quota-reached'];
        yield 'invalid-key' => ['invalid-key', 403, ErrorCodes::UPSTREAM_AUTH, 'invalid-key'];
        yield 'inactive-account' => ['inactive-account', 403, ErrorCodes::UPSTREAM_AUTH, 'inactive-account'];
        yield 'malformed-request' => ['malformed-request', 400, ErrorCodes::UPSTREAM_MALFORMED, 'http_status'];
        yield 'an unknown type with 200' => ['base-code-only-on-pro', 200, ErrorCodes::UPSTREAM_MALFORMED, 'error_type'];
    }

    #[DataProvider('errorTypes')]
    public function testUpstreamErrorTypesMapToErrorCodes(string $type, int $status, string $code, string $reason): void
    {
        foreach (['latest', 'codes', 'quota'] as $endpoint) {
            $upstream = new MockUpstream([MockUpstream::error($type, $status)]);
            $error = self::failure(static fn () => $upstream->client()->{$endpoint}());

            self::assertSame($code, $error->errorCode, $endpoint);
            self::assertSame($reason, $error->reason, $endpoint);
            self::assertSame($status, $error->httpStatus, $endpoint);
        }
    }

    public function testTheErrorCodesHaveTheirHttpStatusAndGenericMessage(): void
    {
        $quota = new UpstreamError(ErrorCodes::UPSTREAM_QUOTA, 'quota-reached');

        self::assertSame(503, $quota->status());
        self::assertSame(ErrorCodes::messageForUser(ErrorCodes::UPSTREAM_QUOTA), $quota->messageForUser());
        self::assertSame('UPSTREAM_QUOTA (quota-reached)', $quota->getMessage());
        self::assertSame(400, (new UpstreamError(ErrorCodes::UPSTREAM_UNSUPPORTED_CODE, 'x'))->status());
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function non2xxResponses(): iterable
    {
        yield '500 with HTML' => [500, '<html>Internal Server Error</html>'];
        yield '502 empty' => [502, ''];
        yield '503 JSON without error-type' => [503, '{"result":"error"}'];
        yield '404 JSON success' => [404, MockUpstream::latestBody()];
        yield '301 redirect' => [301, ''];
        yield '429 plain text' => [429, 'Too Many Requests'];
    }

    #[DataProvider('non2xxResponses')]
    public function testNon2xxWithoutAKnownErrorTypeIsMalformed(int $status, string $body): void
    {
        $upstream = new MockUpstream([MockUpstream::json($status, $body)]);

        $error = self::failure(static fn () => $upstream->client()->latest());

        self::assertSame(ErrorCodes::UPSTREAM_MALFORMED, $error->errorCode);
        self::assertSame('http_status', $error->reason);
        self::assertSame($status, $error->httpStatus);
    }

    public function testAny2xxStatusIsAccepted(): void
    {
        $upstream = new MockUpstream([MockUpstream::json(203, MockUpstream::latestBody())]);

        self::assertCount(count(MockUpstream::RATES), $upstream->client()->latest()->rates);
    }

    /**
     * @return iterable<string, array{\Closure(string): Throwable, string, string}>
     */
    public static function transportFailures(): iterable
    {
        $message = static fn (string $key): string => 'cURL error 28: Operation timed out for '
            . ExchangeRateClient::BASE_URL . $key . '/latest/USD';
        yield 'connect timeout' => [
            static fn (string $key): Throwable => new ConnectTimeoutException($message($key), MockUpstream::request($key)),
            ErrorCodes::UPSTREAM_TIMEOUT, 'timeout',
        ];
        yield 'network timeout' => [
            static fn (string $key): Throwable => new NetworkTimeoutException($message($key), MockUpstream::request($key)),
            ErrorCodes::UPSTREAM_TIMEOUT, 'timeout',
        ];
        yield 'response timeout' => [
            static fn (string $key): Throwable => new ResponseTimeoutException($message($key), MockUpstream::request($key), new Response(200)),
            ErrorCodes::UPSTREAM_TIMEOUT, 'timeout',
        ];
        yield 'connect refused' => [
            static fn (string $key): Throwable => new ConnectException('cURL error 7: Failed to connect ' . $key, MockUpstream::request($key)),
            ErrorCodes::UPSTREAM_TIMEOUT, 'connect',
        ];
        yield 'DNS or other network error' => [
            static fn (string $key): Throwable => new NetworkException('cURL error 6: Could not resolve host ' . $key, MockUpstream::request($key)),
            ErrorCodes::UPSTREAM_TIMEOUT, 'network',
        ];
        yield 'TLS error' => [
            static fn (string $key): Throwable => new RequestException('cURL error 60: SSL certificate problem ' . $key, MockUpstream::request($key)),
            ErrorCodes::UPSTREAM_TIMEOUT, 'transfer',
        ];
        yield 'transfer error' => [
            static fn (string $key): Throwable => new TransferException('transfer failed ' . $key, MockUpstream::request($key)),
            ErrorCodes::UPSTREAM_TIMEOUT, 'transfer',
        ];
        yield 'too many redirects' => [
            static fn (string $key): Throwable => new TooManyRedirectsException('redirects ' . $key, MockUpstream::request($key), new Response(302)),
            ErrorCodes::UPSTREAM_TIMEOUT, 'transfer',
        ];
        yield 'any other throwable' => [
            static fn (string $key): Throwable => new RuntimeException('handler broke for ' . $key, 0, new RuntimeException($key)),
            ErrorCodes::UPSTREAM_TIMEOUT, 'transport',
        ];
    }

    /**
     * @param \Closure(string): Throwable $make
     */
    #[DataProvider('transportFailures')]
    public function testTransportFailuresAreMappedAndScrubbed(\Closure $make, string $code, string $reason): void
    {
        $key = MockUpstream::CANARY_KEY;
        $upstream = new MockUpstream([$make($key)]);

        // Traces keep their arguments here even where php.ini drops them.
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $error = self::failure(static fn () => $upstream->client($key)->latest());
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }
        self::assertNotSame([], array_filter(
            $error->getTrace(),
            static fn (array $frame): bool => array_key_exists('args', $frame),
        ), 'the trace has arguments to check');

        self::assertSame($code, $error->errorCode);
        self::assertSame($reason, $error->reason);
        self::assertNull($error->getPrevious(), 'the Guzzle exception is never chained');
        self::assertNull($error->httpStatus);
        self::assertKeyNotIn($key, $error);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformedBodies(): iterable
    {
        $valid = json_decode(MockUpstream::latestBody(), true, 16, JSON_THROW_ON_ERROR);
        $with = static function (array $changes) use ($valid): string {
            return json_encode(array_merge($valid, $changes), JSON_THROW_ON_ERROR);
        };
        $without = static function (string $key) use ($valid): string {
            unset($valid[$key]);

            return json_encode($valid, JSON_THROW_ON_ERROR);
        };

        yield 'not JSON' => ['<html>hello</html>', 'json'];
        yield 'empty body' => ['', 'json'];
        yield 'truncated JSON' => [substr(MockUpstream::latestBody(), 0, 120), 'json'];
        yield 'a JSON list' => ['[1,2,3]', 'json'];
        yield 'a JSON string' => ['"success"', 'json'];
        yield 'nested deeper than 16' => [str_repeat('[', 40) . str_repeat(']', 40), 'json'];
        yield 'BOM before JSON' => ["\xEF\xBB\xBF" . MockUpstream::latestBody(), 'json'];
        yield 'no result' => [$without('result'), 'shape'];
        yield 'result is not success' => [$with(['result' => 'ok']), 'shape'];
        yield 'result is true' => [$with(['result' => true]), 'shape'];
        yield 'no base_code' => [$without('base_code'), 'base_code'];
        yield 'base_code EUR' => [$with(['base_code' => 'EUR']), 'base_code'];
        yield 'no time_last_update_unix' => [$without('time_last_update_unix'), 'update_times'];
        yield 'time_last_update_unix as text' => [$with(['time_last_update_unix' => '1792540801']), 'update_times'];
        yield 'time_next_update_unix as float' => [$with(['time_next_update_unix' => 1792627201.5]), 'update_times'];
        yield 'next update before last' => [$with(['time_next_update_unix' => MockUpstream::LAST_UPDATE - 1]), 'update_times'];
        yield 'next update equal to last' => [$with(['time_next_update_unix' => MockUpstream::LAST_UPDATE]), 'update_times'];
        yield 'zero last update' => [$with(['time_last_update_unix' => 0]), 'update_times'];
        yield 'no conversion_rates' => [$without('conversion_rates'), 'shape'];
        yield 'conversion_rates is a list' => [$with(['conversion_rates' => [1, 2]]), 'shape'];
        yield 'conversion_rates is empty' => [str_replace('"conversion_rates":{', '"conversion_rates":{}, "x":{', $with([])), 'shape'];
        yield 'conversion_rates is a string' => [$with(['conversion_rates' => 'USD=1']), 'shape'];
        yield 'conversion_rates is null' => [$with(['conversion_rates' => null]), 'shape'];
        yield 'too many currencies' => [MockUpstream::latestBody(self::manyRates(ExchangeRateClient::MAX_CURRENCIES + 1)), 'too_many'];
        yield 'conversion_rates twice' => [
            str_replace('"base_code":"USD",', '"base_code":"USD","conversion_rates":{"BRL":5},', MockUpstream::latestBody()),
            'scan',
        ];
        yield 'every rate invalid' => [MockUpstream::latestBody(['BRL' => -1, 'usd' => 1, 'EUR' => '"0.9"']), 'no_rates'];
    }

    #[DataProvider('malformedBodies')]
    public function testMalformedBodiesAreRejected(string $body, string $reason): void
    {
        $upstream = new MockUpstream([MockUpstream::json(200, $body)]);

        $error = self::failure(static fn () => $upstream->client()->latest());

        self::assertSame(ErrorCodes::UPSTREAM_MALFORMED, $error->errorCode);
        self::assertSame($reason, $error->reason);
    }

    public function testExactlyMaxCurrenciesIsAccepted(): void
    {
        $upstream = new MockUpstream([MockUpstream::latest(self::manyRates(ExchangeRateClient::MAX_CURRENCIES))]);

        self::assertCount(ExchangeRateClient::MAX_CURRENCIES, $upstream->client()->latest()->rates);
    }

    public function testAHugeBodyOfKnownSizeIsRejectedWithoutBeingRead(): void
    {
        $body = MockUpstream::latestBody() . str_repeat(' ', ExchangeRateClient::MAX_BODY_BYTES);
        $upstream = new MockUpstream([new Response(200, [], $body)]);

        $error = self::failure(static fn () => $upstream->client()->latest());

        self::assertSame(ErrorCodes::UPSTREAM_MALFORMED, $error->errorCode);
        self::assertSame('too_large', $error->reason);
    }

    public function testAHugeBodyOfUnknownSizeIsCutOffWhileReading(): void
    {
        // No size and no Content-Length: the read loop stops after the cap, not at the end.
        $produced = 0;
        $stream = new PumpStream(static function (int $length) use (&$produced): string {
            $produced += 65_536;

            return str_repeat(' ', 65_536);
        });
        $upstream = new MockUpstream([new Response(200, [], $stream)]);

        $error = self::failure(static fn () => $upstream->client()->latest());

        self::assertNull($stream->getSize());
        self::assertSame('too_large', $error->reason);
        self::assertLessThan(2 * ExchangeRateClient::MAX_BODY_BYTES, $produced);
    }

    public function testAContentLengthOverTheCapIsRejectedBeforeTheBody(): void
    {
        $upstream = new MockUpstream([
            new Response(200, ['Content-Length' => (string) (ExchangeRateClient::MAX_BODY_BYTES + 1)], '{}'),
        ]);

        $error = self::failure(static fn () => $upstream->client()->latest());

        self::assertSame(ErrorCodes::UPSTREAM_MALFORMED, $error->errorCode);
        self::assertSame('too_large', $error->reason);
        self::assertNull($error->getPrevious());
    }

    public function testTheDownloadProgressCallbackStopsAnOversizedTransfer(): void
    {
        $progress = ExchangeRateClient::requestOptions()['progress'];
        $progress(0, ExchangeRateClient::MAX_BODY_BYTES, 0, 0);

        $this->expectException(\FxInstallments\Rates\BodyTooLarge::class);
        $progress(0, ExchangeRateClient::MAX_BODY_BYTES + 1, 0, 0);
    }

    public function testABodyExactlyAtTheCapIsRead(): void
    {
        $body = MockUpstream::latestBody();
        $body .= str_repeat(' ', ExchangeRateClient::MAX_BODY_BYTES - strlen($body));
        $upstream = new MockUpstream([new Response(200, ['Content-Length' => (string) strlen($body)], $body)]);

        self::assertCount(count(MockUpstream::RATES), $upstream->client()->latest()->rates);
    }

    /**
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function badCurrencies(): iterable
    {
        yield 'negative rate' => ['"BRL":-5.2291', 'not_positive', 'BRL'];
        yield 'zero rate' => ['"BRL":0', 'not_positive', 'BRL'];
        yield 'zero as float' => ['"BRL":0.0', 'not_positive', 'BRL'];
        yield 'rate as text' => ['"BRL":"5.2291"', 'type', 'BRL'];
        yield 'null rate' => ['"BRL":null', 'type', 'BRL'];
        yield 'boolean rate' => ['"BRL":true', 'type', 'BRL'];
        yield 'object rate' => ['"BRL":{"rate":5}', 'type', 'BRL'];
        yield 'list rate' => ['"BRL":[5]', 'type', 'BRL'];
        yield 'rate too large for BIGINT' => ['"BRL":900000000', 'range', 'BRL'];
        yield 'rate that rounds to zero' => ['"BRL":1e-12', 'range', 'BRL'];
        yield 'infinite rate' => ['"BRL":1e400', 'not_positive', 'BRL'];
        yield 'lowercase code' => ['"brl":5.2291', 'code', null];
        yield 'four-letter code' => ['"BRLX":5.2291', 'code', null];
        yield 'two-letter code' => ['"BR":5.2291', 'code', null];
        yield 'numeric code' => ['"123":5.2291', 'code', null];
        yield 'code with markup' => ['"<script>":5.2291', 'code', null];
        yield 'empty code' => ['"":5.2291', 'code', null];
        yield 'duplicated code' => ['"BRL":5.1,"BRL":5.2291', 'duplicate', 'BRL'];
    }

    #[DataProvider('badCurrencies')]
    public function testOneBadCurrencyIsDroppedAndTheOthersKept(string $member, string $reason, ?string $currency): void
    {
        $body = str_replace('"conversion_rates":{', '"conversion_rates":{' . $member . ',', MockUpstream::latestBody(['USD' => 1, 'EUR' => 0.8594]));
        $upstream = new MockUpstream([MockUpstream::json(200, $body)]);

        $latest = $upstream->client()->latest();

        self::assertSame(['EUR' => 8_594_000_000, 'USD' => 10_000_000_000], $latest->rates);
        self::assertSame([['currency' => $currency, 'reason' => $reason]], $latest->dropped);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function decimalTexts(): iterable
    {
        yield 'IRR, the largest live rate' => ['1731936.861971', 17_319_368_619_710_000];
        yield '7 integer digits, 6 decimals' => ['9999999.999999', 99_999_999_999_990_000];
        yield 'LBP' => ['89500', 895_000_000_000_000];
        yield 'one decimal' => ['5.2', 52_000_000_000];
        yield 'exponent' => ['1.5e-3', 15_000_000];
        yield 'capital exponent with sign' => ['2E+2', 2_000_000_000_000];
        yield 'exponent without fraction' => ['5e-11', 1];
        yield 'zero fraction' => ['1.0', 10_000_000_000];
        // 17 significant digits: the float's shortest text would be 0.12345678901234566; the
        // text itself is scaled, rounded half away from zero at the eleventh decimal.
        yield '17 significant digits' => ['0.12345678901234567', 1_234_567_890];
        yield 'eleventh decimal 5 rounds up' => ['0.00000000005', 1];
        yield 'ten decimals kept' => ['0.1234567891', 1_234_567_891];
        // The float route gets these wrong: the nearest double's shortest text ends in ...5 at
        // the eleventh decimal, so fromRate() rounds up (see the next test).
        yield 'float route rounds up: 0.123456789049999999' => ['0.123456789049999999', 1_234_567_890];
        yield 'float route rounds up: 1.00000000004999999' => ['1.00000000004999999', 10_000_000_000];
        yield 'float route rounds up: 3153.77359900004999999' => ['3153.77359900004999999', 31_537_735_990_000];
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function floatRouteMistakes(): iterable
    {
        yield '0.123456789049999999' => ['0.123456789049999999', 1_234_567_890];
        yield '1.00000000004999999' => ['1.00000000004999999', 10_000_000_000];
        yield '3153.77359900004999999' => ['3153.77359900004999999', 31_537_735_990_000];
    }

    /**
     * Why the client scales upstream's text: for these numbers the float route
     * (`ScaledRate::fromRate((float) $value)`) gives a different scaled rate, while the text
     * route gives the exact one, end to end from the raw body.
     */
    #[DataProvider('floatRouteMistakes')]
    public function testTheTextRouteIsExactWhereTheFloatRouteIsNot(string $text, int $exact): void
    {
        $decoded = json_decode('{"r":' . $text . '}', true, 4, JSON_THROW_ON_ERROR)['r'];
        self::assertIsFloat($decoded);
        self::assertNotSame($exact, \FxInstallments\Convert\ScaledRate::fromRate($decoded), 'the float route is wrong here');
        self::assertSame($exact, \FxInstallments\Convert\ScaledRate::fromDecimalString($text));

        $upstream = new MockUpstream([MockUpstream::latest(['USD' => 1, 'BRL' => $text])]);
        self::assertSame($exact, $upstream->client()->latest()->rates['BRL']);
    }

    #[DataProvider('decimalTexts')]
    public function testRatesAreScaledFromTheirDecimalText(string $text, int $scaled): void
    {
        $upstream = new MockUpstream([MockUpstream::latest(['USD' => 1, 'XXA' => $text])]);
        // XXA is not a real code; the client validates the shape only.

        self::assertSame($scaled, $upstream->client()->latest()->rates['XXA']);
    }

    public function testAnEscapedKeyIsReadAsJsonDecodeReadsIt(): void
    {
        $upstream = new MockUpstream([MockUpstream::json(200, str_replace('"BRL"', '"BRL"', MockUpstream::latestBody()))]);

        $latest = $upstream->client()->latest();

        self::assertSame(52_291_000_000, $latest->rates['BRL']);
        self::assertSame([], $latest->dropped);
    }

    public function testCompactAndPrettyBodiesGiveTheSameTable(): void
    {
        $pretty = MockUpstream::latestBody();
        $compact = json_encode(json_decode($pretty, false, 16, JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR);
        $upstream = new MockUpstream([MockUpstream::json(200, $pretty), MockUpstream::json(200, $compact)]);
        $client = $upstream->client();

        self::assertSame($client->latest()->rates, $client->latest()->rates);
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function badKeys(): iterable
    {
        yield 'unset' => [null];
        yield 'empty' => [''];
        yield 'with a slash' => ['abc/../codes'];
        yield 'with a query' => ['abc?x=1'];
        yield 'with a space' => ['abc def'];
        yield 'with a newline' => ["abc\n"];
        yield 'too long' => [str_repeat('a', 129)];
    }

    #[DataProvider('badKeys')]
    public function testAMissingOrMalformedKeyFailsWithoutARequest(?string $key): void
    {
        $upstream = new MockUpstream([MockUpstream::latest()]);

        $error = self::failure(static fn () => $upstream->client($key)->latest());

        self::assertSame(ErrorCodes::UPSTREAM_AUTH, $error->errorCode);
        self::assertSame('no_key', $error->reason);
        self::assertSame(0, $upstream->calls());
    }

    public function testCodesReturnsValidPairsOnly(): void
    {
        $upstream = new MockUpstream([MockUpstream::json(200, json_encode(['result' => 'success', 'supported_codes' => [
            ['USD', 'United States Dollar'],
            ['BRL', 'Brazilian Real'],
            ['brl', 'lowercase'],
            ['EUR'],
            ['JPY', ''],
            ['GBP', "Pound\nSterling"],
            ['CHF', str_repeat('x', 101)],
            'COP',
            ['KWD', 5],
        ]], JSON_THROW_ON_ERROR))]);

        self::assertSame(['USD' => 'United States Dollar', 'BRL' => 'Brazilian Real'], $upstream->client()->codes());
    }

    public function testCodesWithNoValidPairIsMalformed(): void
    {
        $upstream = new MockUpstream([MockUpstream::json(200, '{"result":"success","supported_codes":[]}')]);

        self::assertSame('shape', self::failure(static fn () => $upstream->client()->codes())->reason);
    }

    public function testQuotaIsRead(): void
    {
        $upstream = new MockUpstream([
            MockUpstream::json(200, '{"result":"success","plan_quota":1500,"requests_remaining":1480,"refresh_day_of_month":17}'),
        ]);

        $quota = $upstream->client()->quota();

        self::assertSame(1500, $quota->planQuota);
        self::assertSame(1480, $quota->requestsRemaining);
        self::assertSame(17, $quota->refreshDayOfMonth);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badQuotas(): iterable
    {
        yield 'missing fields' => ['{"result":"success"}'];
        yield 'quota as text' => ['{"result":"success","plan_quota":"1500","requests_remaining":1,"refresh_day_of_month":1}'];
        yield 'day 0' => ['{"result":"success","plan_quota":1500,"requests_remaining":1,"refresh_day_of_month":0}'];
        yield 'day 32' => ['{"result":"success","plan_quota":1500,"requests_remaining":1,"refresh_day_of_month":32}'];
        yield 'negative plan' => ['{"result":"success","plan_quota":-1,"requests_remaining":1,"refresh_day_of_month":1}'];
    }

    #[DataProvider('badQuotas')]
    public function testABadQuotaIsMalformed(string $body): void
    {
        $upstream = new MockUpstream([MockUpstream::json(200, $body)]);

        $error = self::failure(static fn () => $upstream->client()->quota());

        self::assertSame(ErrorCodes::UPSTREAM_MALFORMED, $error->errorCode);
        self::assertSame('shape', $error->reason);
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function encodedResponses(): iterable
    {
        yield 'gzip' => ['gzip', (string) gzencode(MockUpstream::latestBody())];
        yield 'deflate' => ['deflate', (string) gzdeflate(MockUpstream::latestBody())];
        yield 'br' => ['br', 'not really brotli'];
        yield 'gzip with spaces and capitals' => [' GZIP ', (string) gzencode(MockUpstream::latestBody())];
        yield 'gzip, even over the cap when decoded' => ['gzip', (string) gzencode(str_repeat(' ', 4 * ExchangeRateClient::MAX_BODY_BYTES))];
    }

    #[DataProvider('encodedResponses')]
    public function testAnEncodedResponseIsRejectedNotDecoded(string $encoding, string $body): void
    {
        $upstream = new MockUpstream([new Response(200, ['Content-Encoding' => $encoding], $body)]);

        $error = self::failure(static fn () => $upstream->client()->latest());

        self::assertSame(ErrorCodes::UPSTREAM_MALFORMED, $error->errorCode);
        self::assertSame('content_encoding', $error->reason);
        self::assertSame(200, $error->httpStatus);
    }

    public function testGzipBytesWithoutAContentEncodingAreNotJson(): void
    {
        $upstream = new MockUpstream([new Response(200, [], (string) gzencode(MockUpstream::latestBody()))]);

        self::assertSame('json', self::failure(static fn () => $upstream->client()->latest())->reason);
    }

    public function testAnIdentityEncodingIsAccepted(): void
    {
        $upstream = new MockUpstream([
            new Response(200, ['Content-Encoding' => 'identity'], MockUpstream::latestBody()),
            new Response(200, ['Content-Encoding' => ' Identity '], MockUpstream::latestBody()),
        ]);
        $client = $upstream->client();

        self::assertCount(count(MockUpstream::RATES), $client->latest()->rates);
        self::assertCount(count(MockUpstream::RATES), $client->latest()->rates);
    }

    public function testNoDumpOrExportOfTheClientShowsTheKey(): void
    {
        $client = (new MockUpstream())->client(MockUpstream::CANARY_KEY);

        ob_start();
        var_dump($client);
        $dump = (string) ob_get_clean();
        $texts = [
            'var_dump' => $dump,
            'print_r' => print_r($client, true),
            'var_export' => var_export($client, true),
            '(array) cast' => print_r((array) $client, true),
            'get_object_vars' => print_r(get_object_vars($client), true),
            'json_encode' => (string) json_encode((array) $client, JSON_PARTIAL_OUTPUT_ON_ERROR),
            'the key property, cast' => var_export((array) ((array) $client)["\0FxInstallments\\Rates\\ExchangeRateClient\0apiKey"], true),
        ];

        foreach ($texts as $how => $text) {
            self::assertStringNotContainsString(MockUpstream::CANARY_KEY, $text, $how);
        }
        self::assertStringContainsString('[redacted]', $dump);
    }

    public function testTheClientAndItsKeyCanNotBeSerializedOrCloned(): void
    {
        $client = (new MockUpstream())->client(MockUpstream::CANARY_KEY);
        $key = new ApiKey(MockUpstream::CANARY_KEY);

        foreach ([static fn () => serialize($client), static fn () => serialize($key), static fn () => clone $key,
            static fn () => ApiKey::__set_state([])] as $i => $call) {
            try {
                $call();
                self::fail("call {$i} should have thrown");
            } catch (\LogicException $e) {
                self::assertStringNotContainsString(MockUpstream::CANARY_KEY, $e->getMessage() . $e);
            }
        }
        self::assertSame(MockUpstream::CANARY_KEY, $key->reveal());
        self::assertSame([], (array) $key);
        self::assertSame('\\' . ApiKey::class . '::__set_state(array(' . "\n" . '))', var_export($key, true));
    }

    /**
     * @param \Closure(): mixed $call
     */
    private static function failure(\Closure $call): UpstreamError
    {
        try {
            $call();
        } catch (UpstreamError $error) {
            return $error;
        }
        self::fail('Expected an UpstreamError');
    }

    /**
     * The message, the string form with its trace, and the full arguments of every app frame
     * in the trace (test frames hold the mock, whose history has the URL, by design).
     */
    private static function assertKeyNotIn(string $key, Throwable $error): void
    {
        $text = $error->getMessage() . "\n" . $error;
        foreach ($error->getTrace() as $frame) {
            if (str_starts_with($frame['class'] ?? '', 'FxInstallments\\Rates\\')) {
                $text .= "\n" . print_r($frame['args'] ?? [], true);
            }
        }
        self::assertStringNotContainsString($key, $text);
        self::assertStringNotContainsString('exchangerate-api.com', $text);
    }

    /**
     * @return array<string, float>
     */
    private static function manyRates(int $count): array
    {
        $rates = [];
        for ($i = 0; count($rates) < $count; $i++) {
            $code = chr(65 + intdiv($i, 676) % 26) . chr(65 + intdiv($i, 26) % 26) . chr(65 + $i % 26);
            $rates[$code] = 1.5;
        }

        return $rates;
    }
}
