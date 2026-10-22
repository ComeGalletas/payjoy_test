<?php

declare(strict_types=1);

namespace FxInstallments\Http;

/**
 * A 204 with no body and so no Content-Type: the answer to `GET /favicon.ico` (P1-07). It still
 * sends `X-Content-Type-Options: nosniff`, like every response. Not an envelope (INV-04
 * exception, with the widget files).
 */
final class NoContentResponse implements Response
{
    use SendsResponse;

    /**
     * @param array<string, string> $headers keys are lowercase header names
     */
    private function __construct(private array $headers)
    {
    }

    public static function create(): self
    {
        return new self([]);
    }

    public function status(): int
    {
        return 204;
    }

    public function body(): string
    {
        return '';
    }

    public function errorCode(): ?string
    {
        return null;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return ['x-content-type-options' => 'nosniff'] + array_diff_key($this->headers, ['content-type' => true]);
    }
}
