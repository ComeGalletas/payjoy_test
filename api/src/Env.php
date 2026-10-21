<?php

declare(strict_types=1);

namespace FxInstallments;

/**
 * Reads environment variables the same way everywhere: $_ENV, then $_SERVER, then getenv().
 * phpdotenv fills $_ENV and $_SERVER; the process environment (php -S, CI, the test bootstrap)
 * is visible through getenv().
 */
final class Env
{
    public static function get(string $name): ?string
    {
        foreach ([$_ENV[$name] ?? null, $_SERVER[$name] ?? null] as $value) {
            if (is_string($value)) {
                return $value;
            }
        }
        $value = getenv($name);

        return is_string($value) ? $value : null;
    }

    /**
     * The value, or null when it is unset or empty.
     */
    public static function nonEmpty(string $name): ?string
    {
        $value = self::get($name);

        return $value === null || $value === '' ? null : $value;
    }

    public static function isTest(): bool
    {
        return self::get('APP_ENV') === 'test';
    }
}
