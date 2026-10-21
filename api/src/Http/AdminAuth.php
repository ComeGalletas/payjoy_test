<?php

declare(strict_types=1);

namespace FxInstallments\Http;

use FxInstallments\Metrics\ErrorCodes;

/**
 * Guards admin routes with the admin token (docs/PLAN.md §3).
 *
 * - The token is read only from `Authorization: Bearer <token>`, never from the query string.
 * - It is compared with hash_equals.
 * - Fails closed: when ADMIN_TOKEN is unset or empty, every request is refused with 401
 *   ADMIN_UNAUTHORIZED, including one that sends an empty Bearer value.
 */
final class AdminAuth implements Middleware
{
    public function __construct(private readonly ?string $adminToken)
    {
    }

    public function process(Request $request, callable $next): JsonResponse
    {
        if ($this->adminToken === null || $this->adminToken === '') {
            return self::unauthorized();
        }

        $presented = self::bearerToken($request->header('authorization'));
        if ($presented === null || !hash_equals($this->adminToken, $presented)) {
            return self::unauthorized();
        }

        return $next($request);
    }

    /**
     * The token from an `Authorization: Bearer <token>` header (scheme case-insensitive), or
     * null when the header is missing, uses another scheme or has an empty token.
     */
    private static function bearerToken(?string $header): ?string
    {
        if ($header === null || preg_match('/^Bearer[ ]+(\S+)[ ]*$/i', $header, $match) !== 1) {
            return null;
        }

        return $match[1];
    }

    private static function unauthorized(): JsonResponse
    {
        return JsonResponse::error(ErrorCodes::ADMIN_UNAUTHORIZED)->withHeader('WWW-Authenticate', 'Bearer');
    }
}
