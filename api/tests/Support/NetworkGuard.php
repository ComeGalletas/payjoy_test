<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Support;

/**
 * No-network guard for the test suite (docs/GUARDRAILS.md INV-06).
 *
 * Replaces PHP's http, https, ftp and ftps stream wrappers with BlockedStreamWrapper, so
 * fopen(), file_get_contents() and friends on a network URL throw NetworkAccessForbidden. Every
 * attempt is also recorded; if any attempt is still recorded when the PHPUnit process ends
 * (for example because code under test caught the exception), the process prints the attempts
 * and exits 1, so a swallowed attempt still fails the run.
 *
 * Not covered: the curl extension and raw socket transports (tcp://, ssl://). Guzzle arrives in
 * P1-04, which must extend this guard to Guzzle handlers.
 */
final class NetworkGuard
{
    public const SCHEMES = ['http', 'https', 'ftp', 'ftps'];

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
