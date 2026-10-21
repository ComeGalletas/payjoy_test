<?php

declare(strict_types=1);

namespace FxInstallments\Db;

use Closure;
use PDO;

/**
 * Opens the database on first use and then reuses it. If opening fails, the exception reaches
 * the caller and the next call tries again, so a broken database fails only the code that
 * needs it (Metrics swallows its own failures; INV-07).
 */
final class Connection
{
    private ?PDO $pdo = null;

    /**
     * @param Closure(): PDO $factory
     */
    public function __construct(private readonly Closure $factory)
    {
    }

    public static function wrap(PDO $pdo): self
    {
        return new self(static fn (): PDO => $pdo);
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= ($this->factory)();
    }
}
