<?php

declare(strict_types=1);

namespace FxInstallments\Http;

use FxInstallments\Metrics\ErrorCodes;

/**
 * Guards admin routes with the admin token (docs/PLAN.md §3).
 *
 * - The token is read only from `Authorization: Bearer <token>`, never from the query string.
 * - It is compared with hash_equals, after a strict parse of the header (bearerToken()).
 * - Fails closed: when ADMIN_TOKEN is unset or empty, every request is refused with 401
 *   ADMIN_UNAUTHORIZED, including one that sends an empty Bearer value.
 */
final class AdminAuth implements Middleware
{
    public function __construct(private readonly ?string $adminToken)
    {
    }

    public function process(Request $request, callable $next): Response
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
     * The token from an `Authorization: Bearer <token>` header, or null when the header is
     * missing, uses another scheme or has an empty token.
     *
     * Strict (P1-07): the scheme (any case), one or more spaces, then a token of visible ASCII
     * characters (0x21-0x7E) up to the very end of the value. Anything before the scheme or
     * after the token — a trailing newline, CR, space or tab, a control or non-ASCII byte —
     * refuses the header (`\z`, not `$`, which would also match before a final newline).
     */
    private static function bearerToken(?string $header): ?string
    {
        if ($header === null || preg_match('/^Bearer +([\x21-\x7E]+)\z/i', $header, $match) !== 1) {
            return null;
        }

        return $match[1];
    }

    private static function unauthorized(): JsonResponse
    {
        return JsonResponse::error(ErrorCodes::ADMIN_UNAUTHORIZED)->withHeader('WWW-Authenticate', 'Bearer');
    }
}
