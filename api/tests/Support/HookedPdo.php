<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Support;

use Closure;
use FxInstallments\Db\Database;
use PDO;
use PDOStatement;

/**
 * A PDO opened like Database::connect() that records, for every statement it prepares, its
 * first word and whether a transaction was open, and can run a hook (once) just before it
 * prepares the first statement starting with a given prefix. Used to check that a
 * select-then-write really runs inside one transaction (IpBlocksTest).
 */
final class HookedPdo extends PDO
{
    /** @var list<array{string, bool}> first word, transaction open */
    public array $prepared = [];

    /** @var list<string> the full SQL of every statement prepared */
    public array $sql = [];

    private ?string $hookPrefix = null;
    private ?Closure $hook = null;

    public static function open(string $path): self
    {
        $pdo = new self('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA busy_timeout = ' . Database::BUSY_TIMEOUT_MS);
        $pdo->query('PRAGMA journal_mode = WAL')?->fetchAll();
        $pdo->prepared = [];
        $pdo->sql = [];

        return $pdo;
    }

    public function before(string $prefix, Closure $hook): void
    {
        $this->hookPrefix = $prefix;
        $this->hook = $hook;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($this->hook !== null && $this->hookPrefix !== null && str_starts_with($query, $this->hookPrefix)) {
            $hook = $this->hook;
            $this->hook = null;
            $hook();
        }
        $this->prepared[] = [(string) strtok($query, ' '), $this->inTransaction()];
        $this->sql[] = $query;

        return parent::prepare($query, $options);
    }
}
