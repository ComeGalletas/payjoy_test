<?php

declare(strict_types=1);

namespace FxInstallments\Db;

use RuntimeException;

/**
 * The schema version record is damaged: app_state exists but schema_version is missing or not
 * a non-negative integer. Neither the app nor bin/migrate.php guesses a version; both fail
 * closed until an operator repairs the row.
 */
final class SchemaCorrupt extends RuntimeException
{
}
