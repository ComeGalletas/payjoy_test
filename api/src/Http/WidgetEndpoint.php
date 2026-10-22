<?php

declare(strict_types=1);

namespace FxInstallments\Http;

use RuntimeException;

/**
 * The built-in chat widget (docs/PLAN.md §7, P1-07): four fixed files from
 * `api/resources/widget/`, each at its own route, plus a 204 for `/favicon.ico`.
 *
 * - `GET /bot/widget`     → widget.html, `text/html; charset=utf-8`, with the page CSP below
 * - `GET /bot/widget.js`  → widget.js,   `text/javascript; charset=utf-8`
 * - `GET /bot/widget.css` → widget.css,  `text/css; charset=utf-8`
 * - `GET /bot/widget.svg` → widget.svg,  `image/svg+xml`, with `default-src 'none'` so the icon
 *   can't run anything even when opened on its own
 * - `GET /favicon.ico`    → 204 with no body (owner decision 2026-10-21). A browser asks for it
 *   on any top-level page it shows from this origin, the JSON endpoints included; a 404 there
 *   would count toward `block.error_responses` (ADR 0016) and block a busy shared IP.
 *
 * Every response carries `X-Content-Type-Options: nosniff`; the files also carry
 * `Cache-Control: no-cache` (a browser revalidates, so a deploy is seen at once) and
 * `Referrer-Policy: no-referrer`. The route table is fixed: no part of the request path picks a
 * file. A missing or unreadable file throws, and the Kernel answers 500 INTERNAL. The routes
 * have no rate limit (owner decision: until P3-01's nginx serves or limits them); blocking
 * still applies before routing (Kernel).
 *
 * The page's Content-Security-Policy allows only this origin's script, style sheet, icon and
 * `fetch`, nothing inline, no plugins, no `<base>`, no form posts, Trusted Types with no policy
 * (so no HTML or script sink can be used at all), and framing by itself and each valid origin
 * in `cors.allowed_origins` (§7). Origins are checked with AllowedOrigins::isValid() here too,
 * so config text can't add a CSP directive even if the list wasn't filtered first.
 *
 * Sessions the widget opens go through `POST /bot/message`, so their channel is `static`.
 */
final class WidgetEndpoint
{
    public const PAGE_PATH = '/bot/widget';
    public const SCRIPT_PATH = '/bot/widget.js';
    public const STYLE_PATH = '/bot/widget.css';
    public const ICON_PATH = '/bot/widget.svg';
    public const FAVICON_PATH = '/favicon.ico';

    /** path => [file name, Content-Type] */
    private const FILES = [
        self::PAGE_PATH => ['widget.html', 'text/html; charset=utf-8'],
        self::SCRIPT_PATH => ['widget.js', 'text/javascript; charset=utf-8'],
        self::STYLE_PATH => ['widget.css', 'text/css; charset=utf-8'],
        self::ICON_PATH => ['widget.svg', 'image/svg+xml'],
    ];

    private const ICON_POLICY = "default-src 'none'";

    private readonly string $contentSecurityPolicy;

    /**
     * @param string $directory the folder holding the widget files
     * @param list<string> $frameAncestors the allowed origins (App::allowedOrigins())
     */
    public function __construct(private readonly string $directory, array $frameAncestors)
    {
        $this->contentSecurityPolicy = self::contentSecurityPolicy($frameAncestors);
    }

    /**
     * @return list<string> every path register() adds, all GET
     */
    public static function paths(): array
    {
        return [...array_keys(self::FILES), self::FAVICON_PATH];
    }

    /**
     * Registers the widget routes and the favicon route, without middleware.
     */
    public function register(Router $router): void
    {
        foreach (array_keys(self::FILES) as $path) {
            $router->get($path, fn (): Response => $this->serve($path));
        }
        $router->get(self::FAVICON_PATH, static fn (): Response => self::favicon());
    }

    /**
     * The response for one of the widget file paths.
     *
     * @throws RuntimeException when the path isn't a widget file path or its file can't be read
     */
    public function serve(string $path): TextResponse
    {
        if (!isset(self::FILES[$path])) {
            throw new RuntimeException("Not a widget path: {$path}");
        }
        [$name, $contentType] = self::FILES[$path];
        $file = $this->directory . '/' . $name;
        $body = is_file($file) ? file_get_contents($file) : false;
        if ($body === false) {
            throw new RuntimeException("Widget file missing or unreadable: {$name}");
        }

        $response = TextResponse::ok($body, $contentType)
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('Referrer-Policy', 'no-referrer');

        return match ($path) {
            self::PAGE_PATH => $response->withHeader('Content-Security-Policy', $this->contentSecurityPolicy),
            self::ICON_PATH => $response->withHeader('Content-Security-Policy', self::ICON_POLICY),
            default => $response,
        };
    }

    /**
     * 204 for `/favicon.ico`, cacheable for a day so a browser doesn't ask on every page.
     */
    public static function favicon(): NoContentResponse
    {
        return NoContentResponse::create()->withHeader('Cache-Control', 'max-age=86400');
    }

    /**
     * The page's CSP. Nothing is allowed by default; script, style, icon and `fetch` only from
     * this origin. With no `'unsafe-inline'` and no nonce, inline script, `<style>` and style
     * attributes are blocked.
     *
     * @param list<string> $frameAncestors
     */
    public static function contentSecurityPolicy(array $frameAncestors): string
    {
        $ancestors = ["'self'"];
        foreach ($frameAncestors as $origin) {
            if (AllowedOrigins::isValid($origin) && !in_array($origin, $ancestors, true)) {
                $ancestors[] = $origin;
            }
        }

        return implode('; ', [
            "default-src 'none'",
            "script-src 'self'",
            "style-src 'self'",
            "connect-src 'self'",
            "img-src 'self'",
            "object-src 'none'",
            "base-uri 'none'",
            "form-action 'none'",
            'frame-ancestors ' . implode(' ', $ancestors),
            "require-trusted-types-for 'script'",
            "trusted-types 'none'",
        ]);
    }
}
