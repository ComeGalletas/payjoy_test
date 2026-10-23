<?php

declare(strict_types=1);

namespace FxInstallments\Db;

use RuntimeException;

/**
 * DB_DSN names a SQLite file that doesn't exist (L-04). Only `php api/bin/migrate.php` creates
 * the database (Database::fromEnvironment() with `create: true`); the app and every other CLI
 * refuse a missing file instead of creating an empty one. The message is fixed text, without
 * the path, so a CLI may print it as it is.
 */
final class DatabaseFileMissing extends RuntimeException
{
    public const MESSAGE = 'no database file (run php api/bin/migrate.php first)';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
