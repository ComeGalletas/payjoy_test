<?php

declare(strict_types=1);

namespace FxInstallments\Http;

/**
 * CORS for routes that opt in (docs/PLAN.md §3: `/bot/message` only). Only origins listed in
 * `cors.allowed_origins` get Access-Control-* headers; the default empty list means
 * same-origin only. Matching is exact (scheme, host and port); there is no wildcard, and the
 * literal origin `null` is never allowed.
 *
 * A request from any other origin is still answered, just without the headers, so the browser
 * refuses to hand the response to the calling page.
 */
final class Cors implements Middleware
{
    private const MAX_AGE_SECONDS = '600';
    private const ALLOWED_HEADERS = 'Content-Type';

    /** @var list<string> */
    private readonly array $allowedOrigins;

    /**
     * @param list<string> $allowedOrigins
     */
    public function __construct(array $allowedOrigins)
    {
        $this->allowedOrigins = array_values(array_filter(
            $allowedOrigins,
            static fn (string $origin): bool => $origin !== '' && $origin !== 'null',
        ));
    }

    public function process(Request $request, callable $next): JsonResponse
    {
        $response = $next($request)->withHeader('Vary', 'Origin');
        $origin = $request->header('origin');

        return $this->isAllowed($origin)
            ? $response->withHeader('Access-Control-Allow-Origin', (string) $origin)
            : $response;
    }

    /**
     * Answers an OPTIONS preflight for a path served by the given methods. The body is the
     * usual success envelope; only the headers differ between allowed and refused origins.
     *
     * @param list<string> $methods
     */
    public function preflight(Request $request, array $methods): JsonResponse
    {
        $response = JsonResponse::ok()->withHeader('Vary', 'Origin');
        $origin = $request->header('origin');
        $requested = strtoupper(trim((string) $request->header('access-control-request-method')));

        if (!$this->isAllowed($origin) || !in_array($requested, $methods, true)) {
            return $response;
        }

        return $response
            ->withHeader('Access-Control-Allow-Origin', (string) $origin)
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $methods))
            ->withHeader('Access-Control-Allow-Headers', self::ALLOWED_HEADERS)
            ->withHeader('Access-Control-Max-Age', self::MAX_AGE_SECONDS);
    }

    private function isAllowed(?string $origin): bool
    {
        return $origin !== null && in_array($origin, $this->allowedOrigins, true);
    }
}
