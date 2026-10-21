<?php

declare(strict_types=1);

namespace FxInstallments\Db;

use InvalidArgumentException;

/**
 * One schema migration: a version number, a short name and the SQL statements that take the
 * database from the previous version to this one. Migrator runs the statements in one
 * transaction, together with the schema_version update.
 */
final class Migration
{
    /**
     * @param list<string> $statements portable SQL (ADR 0010), one statement per entry, no `;`
     */
    public function __construct(
        public readonly int $version,
        public readonly string $name,
        public readonly array $statements,
    ) {
        if ($version < 1 || preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1 || $statements === []) {
            throw new InvalidArgumentException('A migration needs a positive version, a snake_case name and statements');
        }
    }
}
