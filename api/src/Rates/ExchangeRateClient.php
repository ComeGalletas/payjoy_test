<?php

declare(strict_types=1);

namespace FxInstallments\Rates;

use FxInstallments\Convert\ScaledRate;
use FxInstallments\Env;
use FxInstallments\Metrics\ErrorCodes;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use GuzzleHttp\Exception\TransferException;
use InvalidArgumentException;
use JsonException;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * The only code that talks to upstream, ExchangeRate-API (docs/PLAN.md §3b, ADR 0012).
 *
 * Three fixed endpoints, `GET /v6/{key}/latest/USD`, `/codes` and `/quota`. The URL is built
 * from constants and the key only; no method takes user input (INV-05). The Guzzle client is
 * injected, so tests pass one with a MockHandler (ADR 0018); fromEnvironment() builds the real
 * one with the key from EXCHANGERATE_API_KEY.
 *
 * Every request: 4 s total and connect timeout, no redirects, no exceptions for HTTP status
 * (the status is checked here), no transparent decompression (`Accept-Encoding: identity`; a
 * response with any other Content-Encoding is UPSTREAM_MALFORMED), and a body cap of
 * MAX_BODY_BYTES on the bytes that are decoded. The cap is checked on the Content-Length
 * header and while reading (the real guarantees), and also from curl's progress callback.
 * That progress abort is unit-tested only: ExchangeRateClientTest calls the `progress` option
 * directly, because Guzzle's MockHandler never calls `progress`, so no test drives it through
 * a transfer (P1-04 review).
 *
 * `/quota` is called only by QuotaCache, for the admin `/health` view, at most once per
 * `health.quota_cache_minutes`; `/codes` has no caller in Phase 1. Each call writes an
 * `upstream.call` event with its `meta.endpoint`, so reports count every real call (P1-08).
 *
 * The key is held in an ApiKey, so var_export(), `(array)` casts and serialize() of the client
 * never show it.
 *
 * Errors (§3b) come out as UpstreamError only:
 * - error-type `unsupported-code` → UPSTREAM_UNSUPPORTED_CODE; `quota-reached` →
 *   UPSTREAM_QUOTA; `invalid-key`, `inactive-account` → UPSTREAM_AUTH; any other error-type →
 *   UPSTREAM_MALFORMED;
 * - timeouts, connect and other transfer errors → UPSTREAM_TIMEOUT;
 * - a non-2xx status without a known error-type, bad JSON, wrong types or an oversized body →
 *   UPSTREAM_MALFORMED;
 * - a missing or malformed key → UPSTREAM_AUTH (reason `no_key`) without any request.
 *
 * Scrubbing (INV-03): every Throwable from Guzzle is caught here and only its class decides
 * the reason. It is never rethrown or chained, its message (which holds the URL with the key)
 * is never read, and the UpstreamError is created in a frame whose arguments hold neither the
 * key, the URL nor the body, so even a trace with arguments shows none of them.
 */
final class ExchangeRateClient
{
    public const BASE_URL = 'https://v6.exchangerate-api.com/v6/';
    public const TIMEOUT_SECONDS = 4;
    public const MAX_BODY_BYTES = 262_144;
    public const MAX_CURRENCIES = 500;

    /** Endpoint name => path after the key. The only paths ever requested. */
    public const ENDPOINTS = [
        'latest' => 'latest/USD',
        'codes' => 'codes',
        'quota' => 'quota',
    ];

    /** Upstream `error-type` => [error code, reason]. */
    private const ERROR_TYPES = [
        'unsupported-code' => [ErrorCodes::UPSTREAM_UNSUPPORTED_CODE, 'unsupported-code'],
        'quota-reached' => [ErrorCodes::UPSTREAM_QUOTA, 'quota-reached'],
        'invalid-key' => [ErrorCodes::UPSTREAM_AUTH, 'invalid-key'],
        'inactive-account' => [ErrorCodes::UPSTREAM_AUTH, 'inactive-account'],
    ];

    /** Keys are letters, digits, `-` and `_`, so the key is one plain path segment. */
    private const KEY_PATTERN = '/^[A-Za-z0-9_-]{1,128}$/D';

    private const CODE_PATTERN = '/^[A-Z]{3}$/D';
    private const JSON_DEPTH = 16;
    private const MAX_NAME_BYTES = 100;

    private readonly ?ApiKey $apiKey;

    public function __construct(
        private readonly ClientInterface $http,
        #[\SensitiveParameter]
        ?string $apiKey,
    ) {
        $this->apiKey = $apiKey === null ? null : new ApiKey($apiKey);
    }

    /**
     * The production client: a real Guzzle handler and the key from EXCHANGERATE_API_KEY.
     * Tests never call this (the test bootstrap fails any test that builds a real handler).
     */
    public static function fromEnvironment(): self
    {
        return new self(new Client(), Env::nonEmpty('EXCHANGERATE_API_KEY'));
    }

    /**
     * The options sent with every request.
     *
     * @return array<string, mixed>
     */
    public static function requestOptions(): array
    {
        $tooLarge = static function (): void {
            throw new BodyTooLarge();
        };

        return [
            'timeout' => self::TIMEOUT_SECONDS,
            'connect_timeout' => self::TIMEOUT_SECONDS,
            'http_errors' => false,
            'allow_redirects' => false,
            // The body cap applies to the bytes decoded as JSON: no transparent decompression,
            // and only an identity-encoded answer is accepted (see fetch()).
            'decode_content' => false,
            'headers' => ['Accept' => 'application/json', 'Accept-Encoding' => 'identity'],
            'on_headers' => static function (ResponseInterface $response) use ($tooLarge): void {
                $length = $response->getHeaderLine('Content-Length');
                if ($length !== '' && (!ctype_digit($length) || strlen($length) > 12 || (int) $length > self::MAX_BODY_BYTES)) {
                    $tooLarge();
                }
            },
            'progress' => static function (int $downloadTotal, int $downloaded) use ($tooLarge): void {
                if ($downloaded > self::MAX_BODY_BYTES) {
                    $tooLarge();
                }
            },
        ];
    }

    /**
     * `GET /latest/USD`: every rate, scaled from upstream's decimal text.
     *
     * A currency whose code, value or text fails validation is dropped and listed in
     * LatestRates::$dropped; the call fails only when the response as a whole is wrong or no
     * currency is left.
     *
     * @throws UpstreamError
     */
    public function latest(): LatestRates
    {
        [$data, $body] = $this->fetch('latest');
        $latest = null;
        $reason = null;
        try {
            $latest = self::parseLatest($data, $body);
        } catch (InvalidShape $shape) {
            $reason = $shape->reason;
            unset($shape);
        }
        unset($body, $data);
        if ($latest === null) {
            throw new UpstreamError(ErrorCodes::UPSTREAM_MALFORMED, $reason ?? 'shape');
        }

        return $latest;
    }

    /**
     * `GET /codes`: code => English name, for the codes upstream supports. Entries that are
     * not a valid code and a short single-line name are skipped.
     *
     * @return array<string, string>
     *
     * @throws UpstreamError
     */
    public function codes(): array
    {
        [$data] = $this->fetch('codes');
        $codes = [];
        $list = $data['supported_codes'] ?? null;
        if (is_array($list) && array_is_list($list)) {
            foreach ($list as $pair) {
                if (!is_array($pair) || !array_is_list($pair) || count($pair) !== 2) {
                    continue;
                }
                [$code, $name] = $pair;
                if (is_string($code) && preg_match(self::CODE_PATTERN, $code) === 1
                    && is_string($name) && $name !== '' && strlen($name) <= self::MAX_NAME_BYTES
                    && preg_match('/^[^\p{Cc}]+$/Du', $name) === 1) {
                    $codes[$code] = $name;
                }
            }
        }
        unset($data, $list);
        if ($codes === []) {
            throw new UpstreamError(ErrorCodes::UPSTREAM_MALFORMED, 'shape');
        }

        return $codes;
    }

    /**
     * `GET /quota`: the plan's monthly quota and what is left.
     *
     * @throws UpstreamError
     */
    public function quota(): Quota
    {
        [$data] = $this->fetch('quota');
        $plan = $data['plan_quota'] ?? null;
        $remaining = $data['requests_remaining'] ?? null;
        $day = $data['refresh_day_of_month'] ?? null;
        unset($data);
        if (!is_int($plan) || $plan < 0 || !is_int($remaining) || !is_int($day) || $day < 1 || $day > 31) {
            throw new UpstreamError(ErrorCodes::UPSTREAM_MALFORMED, 'shape');
        }

        return new Quota($plan, $remaining, $day);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['apiKey' => $this->apiKey === null ? null : '[redacted]'];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('The upstream client is never serialized');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('The upstream client is never unserialized');
    }

    /**
     * One request to a fixed endpoint. Returns the decoded success body and its raw text.
     *
     * @param key-of<self::ENDPOINTS> $endpoint
     * @return array{array<array-key, mixed>, string}
     *
     * @throws UpstreamError
     */
    private function fetch(string $endpoint): array
    {
        if (!isset(self::ENDPOINTS[$endpoint])) {
            throw new InvalidArgumentException('Unknown upstream endpoint');
        }
        if ($this->apiKey === null || preg_match(self::KEY_PATTERN, $this->apiKey->reveal()) !== 1) {
            throw new UpstreamError(ErrorCodes::UPSTREAM_AUTH, 'no_key');
        }

        $failure = null;
        $status = 0;
        $body = null;
        $encoded = false;
        try {
            $response = $this->http->request(
                'GET',
                self::BASE_URL . $this->apiKey->reveal() . '/' . self::ENDPOINTS[$endpoint],
                self::requestOptions(),
            );
            $status = $response->getStatusCode();
            $encoding = strtolower(trim($response->getHeaderLine('Content-Encoding')));
            $encoded = $encoding !== '' && $encoding !== 'identity';
            $body = $encoded ? '' : self::readBody($response->getBody());
            unset($response, $encoding);
        } catch (Throwable $e) {
            $failure = self::transportFailure($e);
            unset($e);
        }
        if ($failure !== null) {
            throw new UpstreamError($failure[0], $failure[1]);
        }
        if ($encoded) {
            throw new UpstreamError(ErrorCodes::UPSTREAM_MALFORMED, 'content_encoding', $status);
        }
        if ($body === null) {
            throw new UpstreamError(ErrorCodes::UPSTREAM_MALFORMED, 'too_large');
        }

        try {
            $data = json_decode($body, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $data = null;
        }
        $isObject = is_array($data) && ($data === [] || !array_is_list($data));
        $result = $isObject ? ($data['result'] ?? null) : null;

        if ($result === 'error') {
            $type = $data['error-type'] ?? null;
            $mapped = is_string($type) ? (self::ERROR_TYPES[$type] ?? null) : null;
            unset($data, $body, $type);
            if ($mapped !== null) {
                throw new UpstreamError($mapped[0], $mapped[1], $status);
            }
            if ($status < 200 || $status > 299) {
                throw new UpstreamError(ErrorCodes::UPSTREAM_MALFORMED, 'http_status', $status);
            }
            throw new UpstreamError(ErrorCodes::UPSTREAM_MALFORMED, 'error_type', $status);
        }
        if ($status < 200 || $status > 299) {
            unset($data, $body);
            throw new UpstreamError(ErrorCodes::UPSTREAM_MALFORMED, 'http_status', $status);
        }
        if (!$isObject) {
            unset($data, $body);
            throw new UpstreamError(ErrorCodes::UPSTREAM_MALFORMED, 'json');
        }
        if ($result !== 'success') {
            unset($data, $body);
            throw new UpstreamError(ErrorCodes::UPSTREAM_MALFORMED, 'shape');
        }

        /** @var array<array-key, mixed> $data */
        return [$data, $body];
    }

    /**
     * The body text, or null when it is larger than MAX_BODY_BYTES.
     */
    private static function readBody(StreamInterface $stream): ?string
    {
        $size = $stream->getSize();
        if ($size !== null && $size > self::MAX_BODY_BYTES) {
            return null;
        }
        $text = '';
        while (!$stream->eof()) {
            $chunk = $stream->read(65_536);
            if ($chunk === '') {
                break;
            }
            $text .= $chunk;
            if (strlen($text) > self::MAX_BODY_BYTES) {
                return null;
            }
        }

        return $text;
    }

    /**
     * Error code and reason for a Throwable from the transfer, decided by its class only.
     *
     * @return array{string, string}
     */
    private static function transportFailure(Throwable $e): array
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof BodyTooLarge) {
                return [ErrorCodes::UPSTREAM_MALFORMED, 'too_large'];
            }
        }
        if ($e instanceof ConnectTimeoutException || $e instanceof NetworkTimeoutException
            || $e instanceof ResponseTimeoutException) {
            return [ErrorCodes::UPSTREAM_TIMEOUT, 'timeout'];
        }
        if ($e instanceof ConnectException) {
            return [ErrorCodes::UPSTREAM_TIMEOUT, 'connect'];
        }
        if ($e instanceof NetworkException) {
            return [ErrorCodes::UPSTREAM_TIMEOUT, 'network'];
        }
        if ($e instanceof TransferException) {
            return [ErrorCodes::UPSTREAM_TIMEOUT, 'transfer'];
        }

        return [ErrorCodes::UPSTREAM_TIMEOUT, 'transport'];
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws InvalidShape
     */
    private static function parseLatest(array $data, string $body): LatestRates
    {
        $last = $data['time_last_update_unix'] ?? null;
        $next = $data['time_next_update_unix'] ?? null;
        if (($data['base_code'] ?? null) !== 'USD') {
            throw new InvalidShape('base_code');
        }
        if (!is_int($last) || $last <= 0 || !is_int($next) || $next <= $last) {
            throw new InvalidShape('update_times');
        }
        $decoded = $data[ConversionRatesScanner::MEMBER] ?? null;
        if (!is_array($decoded) || $decoded === [] || array_is_list($decoded)) {
            throw new InvalidShape('shape');
        }
        if (count($decoded) > self::MAX_CURRENCIES) {
            throw new InvalidShape('too_many');
        }
        $scanned = ConversionRatesScanner::scan($body);
        if ($scanned === null) {
            throw new InvalidShape('scan');
        }

        $rates = [];
        $dropped = [];
        foreach ($decoded as $key => $value) {
            $code = (string) $key;
            if (preg_match(self::CODE_PATTERN, $code) !== 1) {
                $dropped[] = ['currency' => null, 'reason' => 'code'];
                continue;
            }
            $scaled = self::scaledRate($value, $scanned['rates'][$key] ?? null, isset($scanned['duplicates'][$key]));
            if (is_string($scaled)) {
                $dropped[] = ['currency' => $code, 'reason' => $scaled];
                continue;
            }
            $rates[$code] = $scaled;
        }
        if ($rates === []) {
            throw new InvalidShape('no_rates');
        }
        ksort($rates, SORT_STRING);

        return new LatestRates($last, $next, $rates, $dropped);
    }

    /**
     * One rate's scaled value, built from its decimal text, or the reason it can't be stored.
     */
    private static function scaledRate(mixed $value, ?string $text, bool $duplicate): int|string
    {
        if ($duplicate) {
            return 'duplicate';
        }
        if (!is_int($value) && !is_float($value)) {
            return 'type';
        }
        if (!is_finite((float) $value) || $value <= 0) {
            return 'not_positive';
        }
        if ($text === null) {
            return 'scan';
        }
        // The scanned text must be the number json_decode read.
        if ((float) $text !== (float) $value) {
            return 'mismatch';
        }
        try {
            return ScaledRate::fromDecimalString($text);
        } catch (InvalidArgumentException) {
            return 'range';
        }
    }
}
