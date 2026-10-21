<?php

declare(strict_types=1);

namespace FxInstallments\Http;

/**
 * The client address used for per-IP limits.
 *
 * It is REMOTE_ADDR, unless TRUSTED_PROXY is set and equals REMOTE_ADDR: then it is the last
 * valid address in X-Forwarded-For, the one the trusted proxy appended. Earlier entries are
 * supplied by the client and can be forged, so they are never used.
 */
final class ClientIp
{
    public static function resolve(Request $request, ?string $trustedProxy): string
    {
        $peer = $request->remoteAddr();
        if ($trustedProxy === null || $trustedProxy === '' || $peer !== $trustedProxy) {
            return $peer;
        }

        $forwarded = $request->header('x-forwarded-for');
        if ($forwarded === null) {
            return $peer;
        }
        $hops = array_map('trim', explode(',', $forwarded));
        $last = end($hops);

        return is_string($last) && filter_var($last, FILTER_VALIDATE_IP) !== false ? $last : $peer;
    }
}
