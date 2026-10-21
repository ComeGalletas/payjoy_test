<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Support;

use PDO;
use RuntimeException;

/**
 * The schema P1-01 shipped, kept verbatim in tests/Fixtures/p1_01_schema.sql (from commit
 * 42ed751), and the way P1-01's Schema::apply() split and ran it. Used to build the dev
 * databases P1-02 must upgrade.
 */
final class P1_01Schema
{
    public static function path(): string
    {
        return dirname(__DIR__) . '/Fixtures/p1_01_schema.sql';
    }

    /**
     * P1-01's statement splitter: `--` comment lines removed, split on a `;` that ends a line.
     *
     * @return list<string>
     */
    public static function statements(): array
    {
        $sql = file_get_contents(self::path());
        if ($sql === false) {
            throw new RuntimeException('Could not read the P1-01 schema fixture');
        }
        $lines = array_filter(
            preg_split('/\R/', $sql) ?: [],
            static fn (string $line): bool => !str_starts_with(ltrim($line), '--'),
        );
        $parts = preg_split('/;[ \t]*(?:\R|$)/', implode("\n", $lines)) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $s): bool => $s !== ''));
    }

    public static function apply(PDO $pdo): void
    {
        foreach (self::statements() as $statement) {
            $pdo->exec($statement);
        }
    }
}
