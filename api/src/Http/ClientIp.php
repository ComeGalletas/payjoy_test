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
 * The two are compared as parsed addresses (L-04), so `::1` and `0:0:0:0:0:0:0:1` match, and
 * an IPv4-mapped address matches its IPv4 form (`::ffff:10.0.0.2` and `10.0.0.2`, cleanup B); a
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
     * Whether $a and $b are the same IP address, whatever the spelling (inet_pton). An
     * IPv4-mapped address (`::ffff:a.b.c.d`, in any spelling) is unmapped to its IPv4 address
     * first, in either argument, as IpBlocking::isLoopback does, so a proxy reached over a
     * dual-stack socket is still the proxy (owner decision, ADR 0016 fifth amendment). False
     * when either isn't a valid IPv4 or IPv6 address: a zone index, brackets or a port never
     * match.
     */
    public static function sameAddress(string $a, string $b): bool
    {
        $packedA = self::unmapped($a);

        return $packedA !== null && $packedA === self::unmapped($b);
    }

    /**
     * $ip's bytes (inet_pton), the 4 IPv4 bytes for an IPv4-mapped address; null when $ip isn't
     * a valid IPv4 or IPv6 address.
     */
    private static function unmapped(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $packed = inet_pton($ip);
        if ($packed === false) {
            return null;
        }
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xFF\xFF")) {
            return substr($packed, 12);
        }

        return $packed;
    }
}
