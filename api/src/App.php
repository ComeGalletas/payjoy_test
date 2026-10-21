<?php

declare(strict_types=1);

namespace FxInstallments;

use Closure;
use FxInstallments\Config\ConfigRepository;
use FxInstallments\Db\Connection;
use FxInstallments\Db\Database;
use FxInstallments\Db\Schema;
use FxInstallments\Http\AdminAuth;
use FxInstallments\Http\Cors;
use FxInstallments\Http\Kernel;
use FxInstallments\Http\RateLimiter;
use FxInstallments\Http\Router;
use FxInstallments\Metrics\Metrics;
use PDO;

/**
 * Wires the shared services (config, database, metrics, middleware) and the production route
 * table. public/index.php uses it after Bootstrap::init(); tests build it with their own
 * database and clock.
 */
final class App
{
    public readonly Metrics $metrics;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param (Closure(): int)|null $clock UTC epoch seconds; defaults to time()
     * @param (Closure(string): void)|null $errorLog where Metrics and Kernel note failures;
     *        defaults to error_log()
     */
    public function __construct(
        public readonly ConfigRepository $config,
        public readonly Connection $db,
        ?Closure $clock = null,
        private readonly ?Closure $errorLog = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
        $this->metrics = new Metrics($db, $this->clock, $errorLog);
    }

    /**
     * The app as configured by the environment: DB_DSN (default api/var/app.db), with the schema
     * applied when the database is first opened.
     */
    public static function fromEnvironment(string $apiRoot): self
    {
        return new self(
            new ConfigRepository(),
            new Connection(static function () use ($apiRoot): PDO {
                $pdo = Database::fromEnvironment($apiRoot);
                Schema::apply($pdo);

                return $pdo;
            }),
        );
    }

    /**
     * The production route table. P1-01 ships none: every path is NOT_FOUND until the endpoint
     * tasks (P1-05 onwards) register theirs here.
     */
    public function router(): Router
    {
        return new Router();
    }

    public function kernel(?Router $router = null): Kernel
    {
        return new Kernel($router ?? $this->router(), $this->metrics, $this->errorLog);
    }

    /**
     * Admin-token middleware for ADMIN_TOKEN as currently set; fails closed when it is empty.
     */
    public function adminAuth(): AdminAuth
    {
        return new AdminAuth(Env::nonEmpty('ADMIN_TOKEN'));
    }

    public function rateLimiter(): RateLimiter
    {
        return new RateLimiter(
            $this->db,
            $this->config->int('ratelimit.per_minute'),
            Env::nonEmpty('TRUSTED_PROXY'),
            $this->clock,
        );
    }

    public function cors(): Cors
    {
        return new Cors($this->config->stringList('cors.allowed_origins'));
    }
}
