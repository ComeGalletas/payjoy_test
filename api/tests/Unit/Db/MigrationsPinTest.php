<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Db;

use FxInstallments\Db\Migration;
use FxInstallments\Db\Migrations;
use PHPUnit\Framework\TestCase;

/**
 * Shipped migrations are append-only: once a version has shipped, its name and statements
 * never change, because databases already at that version would never see the edit. Each
 * shipped version is pinned here by a sha256; a change is a new migration, which is pinned in
 * turn once it ships.
 */
final class MigrationsPinTest extends TestCase
{
    /**
     * sha256 of json_encode([version, name, statements]) for every shipped migration.
     *
     * @var array<int, string>
     */
    private const SHIPPED = [
        1 => 'b36cb244a1dea8d221dc3f07415ef87110eb3870ac52f2e71ac0539f3b4ea3ef',
        2 => '5b9652f3c1dbd2b3793c93594b1a6ffb05c73e94b93670f933d3773f07ab17fc',
        3 => '726130bd45684f8a0a04679a2a5619abe7b893e578d6bdb6e20533552fa0fab5',
    ];

    public function testShippedMigrationsAreUnchanged(): void
    {
        $byVersion = [];
        foreach (Migrations::all() as $migration) {
            $byVersion[$migration->version] = $migration;
        }

        foreach (self::SHIPPED as $version => $pin) {
            self::assertArrayHasKey($version, $byVersion, "migration {$version} was removed: migrations are append-only; add a new one");
            self::assertSame(
                $pin,
                self::pin($byVersion[$version]),
                "migration {$version} ({$byVersion[$version]->name}) was edited: migrations are append-only; add a new one",
            );
        }
    }

    public function testThePinSeesAnyChangeToAStatementOrName(): void
    {
        $original = Migrations::all()[1];
        $statements = $original->statements;
        $statements[0] .= ' ';

        self::assertNotSame(self::pin($original), self::pin(new Migration(2, $original->name, $statements)));
        self::assertNotSame(self::pin($original), self::pin(new Migration(2, 'renamed', $original->statements)));
    }

    private static function pin(Migration $migration): string
    {
        return hash('sha256', json_encode(
            [$migration->version, $migration->name, $migration->statements],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}
