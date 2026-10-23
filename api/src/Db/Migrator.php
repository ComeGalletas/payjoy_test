<?php

declare(strict_types=1);

namespace FxInstallments\Db;

use Closure;
use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;

/**
 * Applies the migration list (Migrations) and reports the schema version.
 *
 * The applied version lives in app_state under the key `schema_version`. A database without
 * app_state is at version 0: it is empty or was created by P1-01, and migration 1 (idempotent)
 * runs as a no-op on the second kind. Each migration runs in its own transaction, together with
 * its schema_version update, so a failed migration leaves the database at the previous version.
 *
 * Schema policy: the schema is applied only by `php api/bin/migrate.php`, at install and on
 * every deploy. The app never migrates; on its first connection it calls assertCurrent(), one
 * indexed SELECT, and fails closed when the database is behind the code.
 *
 * Run one migrate at a time: two concurrent runs are not coordinated.
 */
final class Migrator
{
    public const VERSION_KEY = 'schema_version';

    /** @var Closure(): int */
    private readonly Closure $clock;

    /** @var list<Migration> */
    private readonly array $migrations;

    /**
     * @param list<Migration>|null $migrations defaults to Migrations::all()
     * @param (Closure(): int)|null $clock UTC epoch seconds for app_state.updated_at
     */
    public function __construct(private readonly PDO $pdo, ?array $migrations = null, ?Closure $clock = null)
    {
        $this->migrations = $migrations ?? Migrations::all();
        foreach ($this->migrations as $index => $migration) {
            if ($migration->version !== $index + 1) {
                throw new InvalidArgumentException('Migration versions must run 1, 2, 3, ... with no gaps');
            }
        }
        if ($this->migrations === []) {
            throw new InvalidArgumentException('The migration list is empty');
        }
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Applies every migration newer than the current version, in order.
     *
     * @return list<Migration> the migrations applied, empty when the schema was current
     */
    public function migrate(): array
    {
        $current = $this->currentVersion();
        $applied = [];
        foreach ($this->migrations as $migration) {
            if ($migration->version <= $current) {
                continue;
            }
            $this->apply($migration);
            $applied[] = $migration;
        }

        return $applied;
    }

    /**
     * The recorded schema version; 0 only when app_state does not exist (an empty or P1-01
     * database). Every other database error (locked, not a database, I/O) propagates.
     *
     * @throws SchemaCorrupt when app_state exists but schema_version is missing or not a
     *         non-negative integer: the database fails closed instead of counting as version 0
     */
    public function currentVersion(): int
    {
        if (!$this->appStateExists()) {
            return 0;
        }
        $select = $this->pdo->prepare('SELECT value FROM app_state WHERE key = ?');
        $select->execute([self::VERSION_KEY]);
        $value = $select->fetchColumn();
        if ($value === false) {
            throw new SchemaCorrupt('app_state exists but has no schema_version row');
        }
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value) || preg_match('/^(0|[1-9]\d{0,8})$/D', $value) !== 1) {
            throw new SchemaCorrupt('app_state.schema_version is not a non-negative integer');
        }

        return (int) $value;
    }

    /**
     * Whether app_state exists. SQLite: asked of sqlite_master. Other drivers (Postgres, L-03):
     * a probe outside any transaction, where only "undefined table" (SQLSTATE 42P01) means no;
     * every other error is rethrown.
     */
    private function appStateExists(): bool
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $select = $this->pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
            $select->execute(['app_state']);

            return (int) $select->fetchColumn() > 0;
        }

        try {
            $this->pdo->query('SELECT 1 FROM app_state WHERE 1 = 0');
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '42P01') {
                return false;
            }
            throw $e;
        }

        return true;
    }

    public function latestVersion(): int
    {
        return $this->migrations[count($this->migrations) - 1]->version;
    }

    public function isCurrent(): bool
    {
        return $this->currentVersion() >= $this->latestVersion();
    }

    /**
     * Throws SchemaOutOfDate when the database is behind the code. A database ahead of the code
     * (newer migrations, older code) is allowed.
     */
    public function assertCurrent(): void
    {
        $current = $this->currentVersion();
        $latest = $this->latestVersion();
        if ($current < $latest) {
            throw new SchemaOutOfDate($current, $latest);
        }
    }

    private function apply(Migration $migration): void
    {
        $this->pdo->beginTransaction();
        try {
            foreach ($migration->statements as $statement) {
                $this->pdo->exec($statement);
            }
            if ($this->hasAppStateAfter($migration)) {
                $this->recordVersion($migration->version);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Whether app_state exists once $applied has run: some migration up to it creates the
     * table. Decided from the list rather than by probing, because a failed probe would abort
     * the transaction on Postgres.
     */
    private function hasAppStateAfter(Migration $applied): bool
    {
        foreach ($this->migrations as $migration) {
            if ($migration->version > $applied->version) {
                break;
            }
            foreach ($migration->statements as $statement) {
                if (preg_match('/^CREATE TABLE (IF NOT EXISTS )?app_state\b/', $statement) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Select-then-write upsert (ADR 0010), inside the migration's transaction.
     */
    private function recordVersion(int $version): void
    {
        $now = ($this->clock)();
        $update = $this->pdo->prepare('UPDATE app_state SET value = ?, updated_at = ? WHERE key = ?');
        $update->execute([(string) $version, $now, self::VERSION_KEY]);
        if ($update->rowCount() === 0) {
            $this->pdo->prepare('INSERT INTO app_state (key, value, updated_at) VALUES (?, ?, ?)')
                ->execute([self::VERSION_KEY, (string) $version, $now]);
        }
    }
}
