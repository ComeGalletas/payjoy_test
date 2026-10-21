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
 * Schema policy: the schema is applied only by `php bin/migrate.php`, at install and on every
 * deploy. The app never migrates; on its first connection it calls assertCurrent(), one
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
     * The recorded schema version; 0 when app_state does not exist (an empty or P1-01 database).
     *
     * The probe runs outside any transaction: a failed statement would abort a Postgres one.
     */
    public function currentVersion(): int
    {
        try {
            $select = $this->pdo->prepare('SELECT value FROM app_state WHERE key = ?');
            $select->execute([self::VERSION_KEY]);
            $value = $select->fetchColumn();
        } catch (PDOException) {
            return 0;
        }

        return $value === false ? 0 : (int) $value;
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
