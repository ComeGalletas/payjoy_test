<?php

declare(strict_types=1);

namespace FxInstallments\Db;

use RuntimeException;

/**
 * The database schema is behind the code: run `php api/bin/migrate.php`. The app fails closed
 * on it (500 INTERNAL) instead of running against a schema it doesn't know. The hint names the
 * script from the repository root, as the docs do (L-04).
 */
final class SchemaOutOfDate extends RuntimeException
{
    public function __construct(public readonly int $current, public readonly int $required)
    {
        parent::__construct(sprintf(
            'Database schema is at version %d, the code needs %d: run php api/bin/migrate.php',
            $current,
            $required,
        ));
    }
}
