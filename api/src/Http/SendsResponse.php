<?php

declare(strict_types=1);

namespace FxInstallments\Http;

use InvalidArgumentException;

/**
 * The header handling every Response shares: a guarded withHeader() and send().
 *
 * The class keeps its extra headers in `$headers` (lowercase names) and implements status(),
 * headers() and body().
 */
trait SendsResponse
{
    abstract public function status(): int;

    /**
     * @return array<string, string>
     */
    abstract public function headers(): array;

    abstract public function body(): string;

    public function withHeader(string $name, string $value): self
    {
        if (preg_match('/[\r\n]/', $name . $value) === 1) {
            throw new InvalidArgumentException('Header names and values must not contain line breaks');
        }
        $copy = clone $this;
        $copy->headers[strtolower($name)] = $value;

        return $copy;
    }

    public function header(string $name): ?string
    {
        return $this->headers()[strtolower($name)] ?? null;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            if (!array_key_exists('content-type', $this->headers())) {
                // A response without a body (the 204) gets no Content-Type: without this PHP adds
                // its default `text/html; charset=UTF-8`.
                ini_set('default_mimetype', '');
            }
            http_response_code($this->status());
            foreach ($this->headers() as $name => $value) {
                header(self::headerCase($name) . ': ' . $value);
            }
        }
        echo $this->body();
    }

    private static function headerCase(string $name): string
    {
        return implode('-', array_map(ucfirst(...), explode('-', $name)));
    }
}
