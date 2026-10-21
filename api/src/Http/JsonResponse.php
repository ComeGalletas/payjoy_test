<?php

declare(strict_types=1);

namespace FxInstallments\Http;

use FxInstallments\Metrics\ErrorCodes;
use InvalidArgumentException;
use LogicException;
use stdClass;

/**
 * A JSON response that always carries the envelope (docs/PLAN.md §3):
 * `{"ok": true, "data": {...}}` or `{"ok": false, "error": {"code", "message_for_user"}}`.
 *
 * The body is encoded when the response is built, with JSON_THROW_ON_ERROR, so an encoding
 * failure surfaces inside the handler and becomes an INTERNAL error instead of a broken body.
 * Instances are immutable; withHeader() returns a copy.
 */
final class JsonResponse
{
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * @param array<string, string> $headers keys are lowercase header names
     */
    private function __construct(
        private readonly int $status,
        private readonly string $body,
        private readonly ?string $errorCode,
        private array $headers,
    ) {
    }

    /**
     * A success envelope. `data` is always a JSON object; an empty array encodes as `{}`.
     *
     * @param array<string, mixed>|object $data
     */
    public static function ok(array|object $data = [], int $status = 200): self
    {
        if ($status < 200 || $status > 299) {
            throw new InvalidArgumentException("A success response needs a 2xx status, got {$status}");
        }
        if (is_array($data)) {
            if ($data === []) {
                $data = new stdClass();
            } elseif (array_is_list($data)) {
                throw new InvalidArgumentException('Envelope data must be a JSON object, not a list');
            }
        }

        return new self($status, json_encode(['ok' => true, 'data' => $data], self::JSON_FLAGS), null, []);
    }

    /**
     * An error envelope for a code from ErrorCodes, with that code's HTTP status. The message
     * defaults to the code's generic message_for_user.
     *
     * $status overrides the code's status for the one documented case where a code has two:
     * UPSTREAM_UNSUPPORTED_CODE during its backoff (ErrorCodes::unsupportedCodeDuringBackoff(),
     * UpstreamError::status()). It must be a 4xx or 5xx status.
     */
    public static function error(string $code, ?string $messageForUser = null, ?int $status = null): self
    {
        $codeStatus = ErrorCodes::httpStatus($code);
        $message = $messageForUser ?? ErrorCodes::messageForUser($code);
        if ($codeStatus === null || $message === null) {
            throw new LogicException("Error code {$code} is recorded as an event only and has no API response");
        }
        if ($status !== null && ($status < 400 || $status > 599)) {
            throw new InvalidArgumentException("An error response needs a 4xx or 5xx status, got {$status}");
        }
        $status ??= $codeStatus;
        $body = json_encode(
            ['ok' => false, 'error' => ['code' => $code, 'message_for_user' => $message]],
            self::JSON_FLAGS,
        );

        return new self($status, $body, $code, []);
    }

    public function withHeader(string $name, string $value): self
    {
        if (preg_match('/[\r\n]/', $name . $value) === 1) {
            throw new InvalidArgumentException('Header names and values must not contain line breaks');
        }
        $copy = clone $this;
        $copy->headers[strtolower($name)] = $value;

        return $copy;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function isOk(): bool
    {
        return $this->errorCode === null;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * Every header the response sends, including Content-Type, keyed by lowercase name.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return [
            'content-type' => 'application/json',
            'x-content-type-options' => 'nosniff',
        ] + $this->headers;
    }

    public function header(string $name): ?string
    {
        return $this->headers()[strtolower($name)] ?? null;
    }

    /**
     * Writes the status line, headers and body to the SAPI output.
     */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers() as $name => $value) {
                header(self::headerCase($name) . ': ' . $value);
            }
        }
        echo $this->body;
    }

    private static function headerCase(string $name): string
    {
        return implode('-', array_map(ucfirst(...), explode('-', $name)));
    }
}
