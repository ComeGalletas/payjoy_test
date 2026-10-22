<?php

declare(strict_types=1);

namespace FxInstallments\Http;

use RuntimeException;

/**
 * The built-in chat widget (docs/PLAN.md §7, P1-07): three fixed files from
 * `api/resources/widget/`, each at its own route.
 *
 * - `GET /bot/widget`     → widget.html, `text/html; charset=utf-8`, with the CSP below
 * - `GET /bot/widget.js`  → widget.js,   `text/javascript; charset=utf-8`
 * - `GET /bot/widget.css` → widget.css,  `text/css; charset=utf-8`
 *
 * Every file is a TextResponse, so it carries `X-Content-Type-Options: nosniff`, plus
 * `Cache-Control: no-cache` (a browser revalidates, so a deploy is seen at once) and
 * `Referrer-Policy: no-referrer`. The route table is fixed: no part of the request path picks
 * a file. A missing or unreadable file throws, and the Kernel answers 500 INTERNAL.
 *
 * The page's Content-Security-Policy allows only this origin's files (no inline script or
 * style, no plugins, no `<base>`, no form posts) and lets the page be framed by itself and by
 * each well-formed origin in `cors.allowed_origins` (§7: an iframe embed needs the same
 * origins as a `<script>` embed). An origin that isn't a plain `scheme://host[:port]` is left
 * out of `frame-ancestors`, so config text can't add a CSP directive.
 *
 * Sessions the widget opens go through `POST /bot/message`, so their channel is `static`.
 */
final class WidgetEndpoint
{
    public const PAGE_PATH = '/bot/widget';
    public const SCRIPT_PATH = '/bot/widget.js';
    public const STYLE_PATH = '/bot/widget.css';

    /** path => [file name, Content-Type] */
    private const FILES = [
        self::PAGE_PATH => ['widget.html', 'text/html; charset=utf-8'],
        self::SCRIPT_PATH => ['widget.js', 'text/javascript; charset=utf-8'],
        self::STYLE_PATH => ['widget.css', 'text/css; charset=utf-8'],
    ];

    /** `http` or `https`, a DNS name or a bracketed IPv6 literal, an optional port; nothing else. */
    private const ORIGIN_PATTERN = '~^https?://(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*|\[[0-9A-Fa-f:.]+\])(?::[0-9]{1,5})?$~D';

    private readonly string $contentSecurityPolicy;

    /**
     * @param string $directory the folder holding widget.html, widget.js and widget.css
     * @param list<string> $frameAncestors `cors.allowed_origins`
     */
    public function __construct(private readonly string $directory, array $frameAncestors)
    {
        $this->contentSecurityPolicy = self::contentSecurityPolicy($frameAncestors);
    }

    /**
     * Registers the three widget routes, without middleware: the files are public, and
     * blocking still applies before routing (Kernel).
     */
    public function register(Router $router): void
    {
        foreach (array_keys(self::FILES) as $path) {
            $router->get($path, fn (): Response => $this->serve($path));
        }
    }

    /**
     * The response for one of the three widget paths.
     *
     * @throws RuntimeException when the path isn't a widget path or its file can't be read
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

        return $path === self::PAGE_PATH
            ? $response->withHeader('Content-Security-Policy', $this->contentSecurityPolicy)
            : $response;
    }

    /**
     * The page's CSP. `default-src 'self'` covers scripts, styles, `fetch` and images, and
     * blocks inline script and style because neither `'unsafe-inline'` nor a nonce is listed.
     *
     * @param list<string> $frameAncestors
     */
    public static function contentSecurityPolicy(array $frameAncestors): string
    {
        $ancestors = ["'self'"];
        foreach ($frameAncestors as $origin) {
            if (self::isPlainOrigin($origin) && !in_array($origin, $ancestors, true)) {
                $ancestors[] = $origin;
            }
        }

        return implode('; ', [
            "default-src 'self'",
            "object-src 'none'",
            "base-uri 'none'",
            "form-action 'none'",
            'frame-ancestors ' . implode(' ', $ancestors),
        ]);
    }

    public static function isPlainOrigin(string $origin): bool
    {
        if (preg_match(self::ORIGIN_PATTERN, $origin) !== 1) {
            return false;
        }
        $port = parse_url($origin, PHP_URL_PORT);

        return $port === null || ($port >= 1 && $port <= 65535);
    }
}
