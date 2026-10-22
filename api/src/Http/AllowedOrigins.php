<?php

declare(strict_types=1);

namespace FxInstallments\Http;

/**
 * `cors.allowed_origins`, checked once when the app is wired (App::allowedOrigins()). It feeds
 * both Cors (who may call `/bot/message`) and the widget's `frame-ancestors` (who may frame
 * `/bot/widget`).
 *
 * An entry is kept only when it is exactly what a browser sends in its `Origin` header for that
 * site: `http` or `https`, `://`, a lowercase DNS name or bracketed IPv6 literal, and an optional
 * port 1-65535 that isn't the scheme's default; no path, no trailing slash, nothing else. Any
 * other entry (`*`, `null`, uppercase, a trailing slash, `:443` on https, text with `;` or
 * spaces) could never match an `Origin` header, or could add a CSP directive, so it is dropped,
 * and the app writes one error-log line with the number of dropped entries.
 */
final class AllowedOrigins
{
    private const PATTERN = '~^(https?)://(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*|\[[0-9a-f:.]+\])(?::([0-9]{1,5}))?$~D';

    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    /**
     * @param list<string> $origins
     */
    private function __construct(public readonly array $origins, public readonly int $dropped)
    {
    }

    /**
     * @param list<string> $configured
     */
    public static function fromConfig(array $configured): self
    {
        $kept = [];
        $dropped = 0;
        foreach ($configured as $origin) {
            if (!self::isValid($origin)) {
                $dropped++;
            } elseif (!in_array($origin, $kept, true)) {
                $kept[] = $origin;
            }
        }

        return new self($kept, $dropped);
    }

    public static function isValid(string $origin): bool
    {
        if (preg_match(self::PATTERN, $origin, $match) !== 1) {
            return false;
        }
        if (!isset($match[2])) {
            return true;
        }
        $port = (int) $match[2];

        return $port >= 1 && $port <= 65535 && $port !== self::DEFAULT_PORTS[$match[1]] && $match[2][0] !== '0';
    }
}
