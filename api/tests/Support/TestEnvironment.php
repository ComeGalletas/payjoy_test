<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Support;

use ArrayObject;
use Closure;
use FxInstallments\App;
use FxInstallments\Config\ConfigRepository;
use FxInstallments\Db\Connection;
use FxInstallments\Db\Database;
use FxInstallments\Db\Schema;
use PDO;
use RuntimeException;

/**
 * The suite's scratch area: one temporary root per PHPUnit process holding DATA_ROOT,
 * BACKUP_ROOT and every test database. Nothing here ever touches api/var/app.db or the real
 * protected folders (GR-001). The root is removed when the process exits.
 */
final class TestEnvironment
{
    private const PREFIX = 'fx-installments-tests-';

    private static ?string $root = null;

    public static function root(): string
    {
        if (self::$root === null) {
            $root = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/' . self::PREFIX . bin2hex(random_bytes(6));
            if (!mkdir($root, 0777, true)) {
                throw new RuntimeException("Could not create the test root {$root}");
            }
            self::$root = $root;
            register_shutdown_function(static function () use ($root): void {
                self::removeTree($root);
            });
        }

        return self::$root;
    }

    /**
     * A new empty directory under the test root.
     */
    public static function tempDir(string $label = 'dir'): string
    {
        $dir = self::root() . '/' . $label . '-' . bin2hex(random_bytes(4));
        if (!mkdir($dir, 0777, true)) {
            throw new RuntimeException("Could not create {$dir}");
        }

        return $dir;
    }

    /**
     * A fresh SQLite file under the test root with the schema applied.
     */
    public static function freshDatabasePath(): string
    {
        $path = self::tempDir('db') . '/test.db';
        Schema::apply(Database::connect('sqlite:' . $path));

        return $path;
    }

    public static function connect(string $path): PDO
    {
        return Database::connect('sqlite:' . $path);
    }

    /**
     * Opens an existing database file read-only: every write through it fails.
     */
    public static function connectReadOnly(string $path): PDO
    {
        return Database::connect('sqlite:' . $path, null, null, [
            PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
        ]);
    }

    /**
     * An App on its own fresh database (or on $pdo when given), with an optional fixed clock and
     * config overrides. Its error-log notes go to $log (PHPUnit would report error_log() output
     * as unexpected test output).
     *
     * @param array<string, mixed> $config
     * @param ArrayObject<int, string>|null $log
     */
    public static function app(?FakeClock $clock = null, array $config = [], ?PDO $pdo = null, ?ArrayObject $log = null): App
    {
        $pdo ??= self::connect(self::freshDatabasePath());
        $log ??= new ArrayObject();

        return new App(
            new ConfigRepository($config),
            Connection::wrap($pdo),
            $clock === null ? null : Closure::fromCallable($clock),
            static function (string $line) use ($log): void {
                $log[] = $line;
            },
        );
    }

    /**
     * Deletes only paths under this suite's own temp root.
     */
    private static function removeTree(string $path): void
    {
        if (self::$root === null || !str_starts_with(str_replace('\\', '/', $path), self::$root)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                self::removeTree($path . '/' . $name);
            }
        }
        @rmdir($path);
    }
}
