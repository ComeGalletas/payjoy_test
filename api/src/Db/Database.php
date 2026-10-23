<?php

declare(strict_types=1);

namespace FxInstallments\Db;

use Closure;
use FxInstallments\Env;
use PDO;
use RuntimeException;

/**
 * The PDO factory (ADR 0010). Reads DB_DSN, DB_USER and DB_PASS; the default is the SQLite file
 * api/var/app.db, outside public/. SQLite connections run with WAL and a 5 s busy timeout.
 * Every connection uses exception error mode.
 *
 * No float is ever bound (ADR 0017, INV-09): money is integer cents (UsdCents) and rates are
 * integers scaled by 10^10 (ScaledRate). Opening a connection never changes the schema; see
 * Migrator for when it is applied.
 *
 * **Who may create the database (L-04).** Only `php api/bin/migrate.php` does
 * (`fromEnvironment(create: true)`): it creates the folder of the SQLite file, whatever its
 * path, and the file. Every other caller (the app and the other CLIs) refuses a missing SQLite
 * file with DatabaseFileMissing before opening anything, and opens the file without SQLite's
 * create flag, so it can't create one in a race either. It then checks the schema version
 * (Migrator::assertCurrent()) before it sets WAL mode, so refusing an empty or old file writes
 * nothing to it.
 */
final class Database
{
    public const BUSY_TIMEOUT_MS = 5000;

    /**
     * @param array<int, mixed> $options extra PDO options, for example SQLITE_ATTR_OPEN_FLAGS
     * @param (Closure(PDO): void)|null $check runs after the busy timeout is set and before the
     *        journal mode is changed; whatever it throws reaches the caller
     */
    public static function connect(string $dsn, ?string $user = null, ?string $password = null, array $options = [], ?Closure $check = null): PDO
    {
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ] + $options);

        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            if ($check !== null) {
                $check($pdo);
            }

            return $pdo;
        }

        // busy_timeout first, so the check and setting WAL also wait for a lock instead of
        // failing. The check reads only, so a refused database is left exactly as it was:
        // setting WAL on an empty file would write its header.
        $pdo->exec('PRAGMA busy_timeout = ' . self::BUSY_TIMEOUT_MS);
        if ($check !== null) {
            $check($pdo);
        }
        $pdo->query('PRAGMA journal_mode = WAL')?->fetchAll();

        return $pdo;
    }

    /**
     * Connects with DB_DSN, DB_USER and DB_PASS from the environment. A relative SQLite path in
     * DB_DSN is resolved against the repository root (the parent of $apiRoot).
     *
     * - `create: false` (the app and every CLI but migrate): a missing SQLite file throws
     *   DatabaseFileMissing and nothing is created; the schema must be current
     *   (SchemaOutOfDate, SchemaCorrupt), checked before WAL mode is set.
     * - `create: true` (`migrate.php` only): the SQLite file's folder and the file are created
     *   when missing; the schema is not checked, since migrate applies it.
     *
     * @throws DatabaseFileMissing
     * @throws SchemaOutOfDate
     * @throws SchemaCorrupt
     */
    public static function fromEnvironment(string $apiRoot, bool $create = false): PDO
    {
        $dsn = self::dsnFromEnvironment($apiRoot);
        $file = self::sqliteFile($dsn);
        $options = [];
        if ($file !== null && $create) {
            $folder = dirname($file);
            if (!is_dir($folder) && !@mkdir($folder, 0775, true) && !is_dir($folder)) {
                throw new RuntimeException('Could not create the database folder');
            }
        } elseif ($file !== null) {
            if (!is_file($file)) {
                throw new DatabaseFileMissing();
            }
            // Without SQLITE_OPEN_CREATE: a file removed since the check is refused, not made.
            $options[PDO::SQLITE_ATTR_OPEN_FLAGS] = PDO::SQLITE_OPEN_READWRITE;
        }

        return self::connect(
            $dsn,
            Env::nonEmpty('DB_USER'),
            Env::nonEmpty('DB_PASS'),
            $options,
            $create ? null : static function (PDO $pdo): void {
                (new Migrator($pdo))->assertCurrent();
            },
        );
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

    public static function defaultDsn(string $apiRoot): string
    {
        return 'sqlite:' . $apiRoot . '/var/app.db';
    }

    /**
     * The file a SQLite DSN names, or null for another driver, an in-memory database or a
     * temporary one (`sqlite:`).
     */
    public static function sqliteFile(string $dsn): ?string
    {
        if (!str_starts_with($dsn, 'sqlite:')) {
            return null;
        }
        $path = substr($dsn, strlen('sqlite:'));

        return $path === '' || $path === ':memory:' ? null : $path;
    }

    private static function isAbsolutePath(string $path): bool
    {
        return $path[0] === '/' || $path[0] === '\\' || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
