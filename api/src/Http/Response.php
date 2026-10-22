<?php

declare(strict_types=1);

namespace FxInstallments\Http;

/**
 * What a route handler, a middleware and the Kernel return.
 *
 * - JsonResponse: every JSON endpoint, always the envelope (INV-04).
 * - TextResponse: the widget's page, script and style sheet (P1-07), the only non-envelope
 *   responses in Phase 1 besides the CSV reports of P1-08.
 *
 * Both always send `Content-Type` and `X-Content-Type-Options: nosniff`, and are immutable:
 * withHeader() returns a copy.
 */
interface Response
{
    public function status(): int;

    public function body(): string;

    /**
     * The error code of an error envelope, or null for any other response. The Kernel records
     * an `error` event for every response with a code.
     */
    public function errorCode(): ?string;

    public function withHeader(string $name, string $value): self;

    /**
     * Every header the response sends, including Content-Type, keyed by lowercase name.
     *
     * @return array<string, string>
     */
    public function headers(): array;

    public function header(string $name): ?string;

    /**
     * Writes the status line, headers and body to the SAPI output.
     */
    public function send(): void;
}
