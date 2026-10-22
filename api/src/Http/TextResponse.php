<?php

declare(strict_types=1);

namespace FxInstallments\Http;

use InvalidArgumentException;

/**
 * A 200 response with a text body of a given media type: the widget's HTML page, script and
 * style sheet (P1-07), and CSV reports (P1-08). Not an envelope (INV-04 exception). Like
 * JsonResponse it always sends
 * `X-Content-Type-Options: nosniff`, so a browser never runs or styles a body under a type it
 * guessed. Errors are never TextResponses: they stay JSON envelopes.
 */
final class TextResponse implements Response
{
    use SendsResponse;

    /**
     * @param array<string, string> $headers keys are lowercase header names
     */
    private function __construct(
        private readonly string $body,
        private readonly string $contentType,
        private array $headers,
    ) {
    }

    /**
     * @param string $contentType the full Content-Type value, e.g. `text/html; charset=utf-8`
     */
    public static function ok(string $body, string $contentType): self
    {
        if (preg_match('#^[a-z]+/[a-z0-9.+-]+(; charset=[a-z0-9-]+)?$#D', $contentType) !== 1) {
            throw new InvalidArgumentException("Not a plain Content-Type value: {$contentType}");
        }

        return new self($body, $contentType, []);
    }

    public function status(): int
    {
        return 200;
    }

    public function body(): string
    {
        return $this->body;
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
        return [
            'content-type' => $this->contentType,
            'x-content-type-options' => 'nosniff',
        ] + $this->headers;
    }
}
