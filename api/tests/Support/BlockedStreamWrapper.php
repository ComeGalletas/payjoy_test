<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Support;

/**
 * Stream wrapper that NetworkGuard registers for network schemes. Opening or stat-ing a URL
 * records the attempt and throws.
 */
final class BlockedStreamWrapper
{
    /** @var resource|null set by PHP */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        throw self::forbidden($path);
    }

    /**
     * @return array<int|string, int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        throw self::forbidden($path);
    }

    public function dir_opendir(string $path, int $options): bool
    {
        throw self::forbidden($path);
    }

    private static function forbidden(string $path): NetworkAccessForbidden
    {
        $target = NetworkGuard::recordAttempt($path);

        return new NetworkAccessForbidden("INV-06: tests must not reach the network (tried {$target})");
    }
}
