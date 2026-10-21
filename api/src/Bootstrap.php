<?php

declare(strict_types=1);

namespace FxInstallments;

use Dotenv\Dotenv;

/**
 * Process setup shared by public/index.php, the bin/ scripts and the test bootstrap.
 *
 * - `serialize_precision = -1`, so floats encode with the shortest exact digits (§3a).
 * - UTC as the default timezone (all stored times are UTC epoch integers).
 * - Loads the repository's `.env` through phpdotenv, without overriding variables that are
 *   already set, and skips it entirely when APP_ENV=test, so tests never see the real key.
 */
final class Bootstrap
{
    /**
     * @param string $apiRoot the api/ directory; `.env` is read from its parent
     */
    public static function init(string $apiRoot): void
    {
        ini_set('serialize_precision', '-1');
        date_default_timezone_set('UTC');
        self::loadDotenv(dirname($apiRoot));
    }

    /**
     * Loads `$directory/.env` if it exists. Returns false without touching the file when
     * APP_ENV=test.
     */
    public static function loadDotenv(string $directory): bool
    {
        if (Env::isTest()) {
            return false;
        }
        Dotenv::createImmutable($directory)->safeLoad();

        return true;
    }
}
