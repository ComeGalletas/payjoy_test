<?php

declare(strict_types=1);

namespace FxInstallments\Db;

use RuntimeException;

/**
 * The database schema is behind the code: run `php bin/migrate.php`. The app fails closed on
 * it (500 INTERNAL) instead of running against a schema it doesn't know.
 */
final class SchemaOutOfDate extends RuntimeException
{
    public function __construct(public readonly int $current, public readonly int $required)
    {
        parent::__construct(sprintf(
            'Database schema is at version %d, the code needs %d: run php bin/migrate.php',
            $current,
            $required,
        ));
    }
}
