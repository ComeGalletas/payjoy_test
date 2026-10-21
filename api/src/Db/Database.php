<?php

declare(strict_types=1);

namespace FxInstallments\Db;

use FxInstallments\Env;
use PDO;

/**
 * The PDO factory (ADR 0010). Reads DB_DSN, DB_USER and DB_PASS; the default is the SQLite file
 * api/var/app.db, outside public/. SQLite connections run with WAL and a 5 s busy timeout.
 * Every connection uses exception error mode.
 */
final class Database
{
    public const BUSY_TIMEOUT_MS = 5000;

    /**
     * @param array<int, mixed> $options extra PDO options, for example SQLITE_ATTR_OPEN_FLAGS
     */
    public static function connect(string $dsn, ?string $user = null, ?string $password = null, array $options = []): PDO
    {
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ] + $options);

        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            // busy_timeout first, so setting WAL also waits for a lock instead of failing.
            $pdo->exec('PRAGMA busy_timeout = ' . self::BUSY_TIMEOUT_MS);
            $pdo->query('PRAGMA journal_mode = WAL')?->fetchAll();
        }

        return $pdo;
    }

    /**
     * Connects with DB_DSN, DB_USER and DB_PASS from the environment. A relative SQLite path in
     * DB_DSN is resolved against the repository root (the parent of $apiRoot).
     */
    public static function fromEnvironment(string $apiRoot): PDO
    {
        $dsn = self::dsnFromEnvironment($apiRoot);
        if ($dsn === self::defaultDsn($apiRoot) && !is_dir($apiRoot . '/var')) {
            mkdir($apiRoot . '/var', 0775, true);
        }

        return self::connect($dsn, Env::nonEmpty('DB_USER'), Env::nonEmpty('DB_PASS'));
    }

    /**
     * The DSN that fromEnvironment() connects to. It has no side effects.
     */
    public static function dsnFromEnvironment(string $apiRoot): string
    {
        $dsn = Env::nonEmpty('DB_DSN');
        if ($dsn === null) {
            return self::defaultDsn($apiRoot);
        }

        if (str_starts_with($dsn, 'sqlite:')) {
            $path = substr($dsn, strlen('sqlite:'));
            if ($path !== '' && $path !== ':memory:' && !self::isAbsolutePath($path)) {
                return 'sqlite:' . dirname($apiRoot) . '/' . $path;
            }
        }

        return $dsn;
    }

    /**
     * A float as a bind value that keeps every digit. PDO turns a PHP float parameter into a
     * string using the `precision` ini setting (14 significant digits by default), which would
     * silently round stored amounts and rates; 17 significant digits always round-trip.
     */
    public static function floatParam(float $value): string
    {
        if (!is_finite($value)) {
            throw new \InvalidArgumentException('Only finite floats can be stored');
        }

        return sprintf('%.17g', $value);
    }

    public static function defaultDsn(string $apiRoot): string
    {
        return 'sqlite:' . $apiRoot . '/var/app.db';
    }

    private static function isAbsolutePath(string $path): bool
    {
        return $path[0] === '/' || $path[0] === '\\' || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
