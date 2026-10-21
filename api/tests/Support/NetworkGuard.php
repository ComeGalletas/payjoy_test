<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Support;

use LogicException;

/**
 * No-network guard for the test suite (docs/GUARDRAILS.md INV-06).
 *
 * Replaces PHP's http, https, ftp and ftps stream wrappers with BlockedStreamWrapper, so
 * fopen(), file_get_contents() and friends on a network URL throw NetworkAccessForbidden. Every
 * attempt is also recorded; if any attempt is still recorded when the PHPUnit process ends
 * (for example because code under test caught the exception), the process prints the attempts
 * and exits 1, so a swallowed attempt still fails the run.
 *
 * Guzzle (P1-04, ADR 0018): Guzzle's three real transport handlers (CurlHandler,
 * CurlMultiHandler, StreamHandler) are replaced in the test process by stubs
 * (guzzle_handler_stubs.php), loaded by an autoloader registered ahead of Composer's. Building
 * one, which is what `new Client()` or `HandlerStack::create()` without an injected handler
 * does, records an attempt and throws NetworkAccessForbidden; tests inject a MockHandler. The
 * Composer gate scripts also start PHPUnit with `-d disable_functions=curl_exec,curl_multi_exec`
 * (and `allow_url_fopen=0`), so there Guzzle finds no transport and refuses to build a default
 * handler at all.
 *
 * Not covered: raw socket transports (tcp://, ssl://, fsockopen) and curl functions called
 * directly other than curl_exec and curl_multi_exec; review is the backstop.
 */
final class NetworkGuard
{
    public const SCHEMES = ['http', 'https', 'ftp', 'ftps'];

    /** Guzzle's real transport handlers, replaced by stubs in the test process. */
    public const GUZZLE_HANDLERS = [
        'GuzzleHttp\\Handler\\CurlHandler',
        'GuzzleHttp\\Handler\\CurlMultiHandler',
        'GuzzleHttp\\Handler\\StreamHandler',
    ];

    /** @var list<string> */
    private static array $attempts = [];

    private static bool $installed = false;

    public static function install(): void
    {
        if (self::$installed) {
            return;
        }
        self::$installed = true;

        $registered = stream_get_wrappers();
        foreach (self::SCHEMES as $scheme) {
            if (in_array($scheme, $registered, true)) {
                stream_wrapper_unregister($scheme);
            }
            stream_wrapper_register($scheme, BlockedStreamWrapper::class);
        }

        foreach (self::GUZZLE_HANDLERS as $class) {
            if (class_exists($class, false)) {
                throw new LogicException("INV-06: {$class} was loaded before the network guard; install the guard first");
            }
        }
        spl_autoload_register(static function (string $class): void {
            if (in_array($class, self::GUZZLE_HANDLERS, true)) {
                require_once __DIR__ . '/guzzle_handler_stubs.php';
            }
        }, true, true);

        register_shutdown_function(static function (): void {
            if (self::$attempts === []) {
                return;
            }
            fwrite(STDERR, PHP_EOL . 'INV-06: tests attempted network access: '
                . implode(', ', array_unique(self::$attempts)) . PHP_EOL);
            exit(1);
        });
    }

    public static function isInstalled(): bool
    {
        return self::$installed;
    }

    /**
     * Records an attempt, keeping only scheme and host (never a path that might hold a key).
     */
    public static function recordAttempt(string $url): string
    {
        $parts = parse_url($url);
        $target = (is_array($parts) ? ($parts['scheme'] ?? '?') . '://' . ($parts['host'] ?? '?') : '?');
        self::$attempts[] = $target;

        return $target;
    }

    /**
     * Called by the stub handlers: records the attempt and throws.
     *
     * @throws NetworkAccessForbidden always
     */
    public static function refuseRealHandler(string $class): never
    {
        $short = substr($class, (int) strrpos($class, '\\') + 1);
        self::$attempts[] = 'guzzle://' . $short;

        throw new NetworkAccessForbidden(
            "INV-06: tests must not reach the network: a real Guzzle handler ({$short}) was built. "
            . 'Inject a client with a MockHandler instead.',
        );
    }

    /**
     * Returns and clears the recorded attempts; for the guard's own tests.
     *
     * @return list<string>
     */
    public static function takeAttempts(): array
    {
        $attempts = self::$attempts;
        self::$attempts = [];

        return $attempts;
    }
}
