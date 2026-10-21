<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Support;

use Closure;
use FxInstallments\Db\Database;
use PDO;
use PDOStatement;

/**
 * Connection A of the rate-limiter race (RateLimiterRaceTest). Opened like
 * Database::connect(). Its first UPDATE first runs $beforeFirstUpdate (connection B's whole
 * request) and then matches nothing, as on Postgres when A's UPDATE started before B
 * committed. It records the statements run through it and the SQLSTATEs they failed with.
 */
final class RacingPdo extends PDO
{
    /** @var list<string> */
    public array $statements = [];

    /** @var list<string> */
    public array $failures = [];

    private ?Closure $beforeFirstUpdate = null;

    public static function open(string $path, Closure $beforeFirstUpdate): self
    {
        $pdo = new self('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STATEMENT_CLASS => [RacingStatement::class, []],
        ]);
        $pdo->exec('PRAGMA busy_timeout = ' . Database::BUSY_TIMEOUT_MS);
        $pdo->query('PRAGMA journal_mode = WAL')?->fetchAll();
        $pdo->beforeFirstUpdate = $beforeFirstUpdate;

        return $pdo;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $verb = (string) strtok($query, ' ');
        if ($verb === 'UPDATE' && $this->beforeFirstUpdate !== null) {
            $race = $this->beforeFirstUpdate;
            $this->beforeFirstUpdate = null;
            // A has begun its transaction but holds no lock yet, so B can write and commit.
            $race();
            $this->statements[] = 'UPDATE (stale)';
            $statement = parent::prepare($query . ' AND 1 = 0', $options);
        } else {
            $this->statements[] = $verb;
            $statement = parent::prepare($query, $options);
        }
        if ($statement instanceof RacingStatement) {
            $statement->owner = $this;
        }

        return $statement;
    }
}
