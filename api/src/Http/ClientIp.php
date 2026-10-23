<?php

declare(strict_types=1);

namespace FxInstallments\Http;

/**
 * The client address used for per-IP limits.
 *
 * It is REMOTE_ADDR, unless TRUSTED_PROXY is set and is the same address as REMOTE_ADDR: then
 * it is the last valid address in X-Forwarded-For, the one the trusted proxy appended. Earlier
 * entries are supplied by the client and can be forged, so they are never used.
 *
 * The two are compared as parsed addresses (L-04), so `::1` and `0:0:0:0:0:0:0:1` match; a
 * value that isn't a valid IP never matches anything, itself included.
 */
final class ClientIp
{
    public static function resolve(Request $request, ?string $trustedProxy): string
    {
        $peer = $request->remoteAddr();
        if ($trustedProxy === null || !self::sameAddress($trustedProxy, $peer)) {
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

    /**
     * Whether $a and $b are the same IP address, whatever the spelling (inet_pton). False when
     * either isn't a valid IPv4 or IPv6 address. An IPv4 address and its IPv4-mapped IPv6 form
     * are different addresses here, as they are to the web server.
     */
    public static function sameAddress(string $a, string $b): bool
    {
        if (filter_var($a, FILTER_VALIDATE_IP) === false || filter_var($b, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return inet_pton($a) === inet_pton($b);
    }
}
