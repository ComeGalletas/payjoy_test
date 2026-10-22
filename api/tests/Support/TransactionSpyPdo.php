<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Support;

use FxInstallments\Db\Database;
use PDO;

/**
 * A PDO opened like Database::connect() that counts beginTransaction(), commit() and
 * rollBack() calls. Used to check that `/health`'s write probe always rolls back
 * (HealthEndpointTest).
 */
final class TransactionSpyPdo extends PDO
{
    /** @var array{begin: int, commit: int, rollBack: int} */
    public array $calls = ['begin' => 0, 'commit' => 0, 'rollBack' => 0];

    public static function open(string $path): self
    {
        $pdo = new self('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA busy_timeout = ' . Database::BUSY_TIMEOUT_MS);
        $pdo->query('PRAGMA journal_mode = WAL')?->fetchAll();

        return $pdo;
    }

    public function beginTransaction(): bool
    {
        $this->calls['begin']++;

        return parent::beginTransaction();
    }

    public function commit(): bool
    {
        $this->calls['commit']++;

        return parent::commit();
    }

    public function rollBack(): bool
    {
        $this->calls['rollBack']++;

        return parent::rollBack();
    }
}
