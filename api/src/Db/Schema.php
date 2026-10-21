<?php

declare(strict_types=1);

namespace FxInstallments\Db;

use PDO;
use RuntimeException;

/**
 * Applies api/db/schema.sql. Every statement is CREATE ... IF NOT EXISTS, so applying it again
 * is a no-op. P1-02 replaces this with bin/migrate.php and the full Phase 1 schema.
 */
final class Schema
{
    public static function path(): string
    {
        return dirname(__DIR__, 2) . '/db/schema.sql';
    }

    public static function apply(PDO $pdo): void
    {
        foreach (self::statements() as $statement) {
            $pdo->exec($statement);
        }
    }

    /**
     * The statements of schema.sql: `--` comment lines removed, split on a `;` that ends a line.
     *
     * @return list<string>
     */
    public static function statements(): array
    {
        $sql = file_get_contents(self::path());
        if ($sql === false) {
            throw new RuntimeException('Could not read db/schema.sql');
        }
        $lines = array_filter(
            preg_split('/\R/', $sql) ?: [],
            static fn (string $line): bool => !str_starts_with(ltrim($line), '--'),
        );
        $parts = preg_split('/;[ \t]*(?:\R|$)/', implode("\n", $lines)) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $s): bool => $s !== ''));
    }
}
