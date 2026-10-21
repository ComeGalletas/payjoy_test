<?php

declare(strict_types=1);

namespace FxInstallments;

use Closure;
use FxInstallments\Config\ConfigRepository;
use FxInstallments\Db\Connection;
use FxInstallments\Db\Database;
use FxInstallments\Db\Migrator;
use FxInstallments\Http\AdminAuth;
use FxInstallments\Http\Cors;
use FxInstallments\Http\IpBlocking;
use FxInstallments\Http\IpBlocks;
use FxInstallments\Http\Kernel;
use FxInstallments\Http\RateLimiter;
use FxInstallments\Http\Router;
use FxInstallments\Metrics\Metrics;
use FxInstallments\Metrics\RequestLog;
use FxInstallments\Rates\ExchangeRateClient;
use FxInstallments\Rates\RateCache;
use FxInstallments\Rates\RateStore;
use FxInstallments\Rates\RateTableAllowList;
use FxInstallments\Rates\UpstreamBreaker;
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

    private ?RateCache $rates = null;

    /**
     * @param (Closure(): int)|null $clock UTC epoch seconds; defaults to time()
     * @param (Closure(string): void)|null $errorLog where Metrics and Kernel note failures;
     *        defaults to error_log()
     * @param ExchangeRateClient|null $rateClient the upstream client; defaults to
     *        ExchangeRateClient::fromEnvironment(), built on first use of rates(). Tests pass
     *        one with a mocked handler.
     */
    public function __construct(
        public readonly ConfigRepository $config,
        public readonly Connection $db,
        ?Closure $clock = null,
        private readonly ?Closure $errorLog = null,
        private ?ExchangeRateClient $rateClient = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
        $this->metrics = new Metrics($db, $this->clock, $errorLog);
    }

    /**
     * The app as configured by the environment: DB_DSN (default api/var/app.db).
     *
     * The app never changes the schema; `php bin/migrate.php` does, at install and on every
     * deploy. When the database is first opened, a version check (one indexed SELECT on
     * app_state) throws SchemaOutOfDate if the database is behind the code, and nothing runs
     * against it. public/index.php opens the database before routing, so every request then
     * fails closed with 500 INTERNAL.
     */
    public static function fromEnvironment(string $apiRoot): self
    {
        return new self(
            new ConfigRepository(),
            new Connection(static function () use ($apiRoot): PDO {
                $pdo = Database::fromEnvironment($apiRoot);
                (new Migrator($pdo))->assertCurrent();

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

    /**
     * The kernel with the request log and the block check (ADR 0016) always wired. Throws
     * InvalidArgumentException when a `block.*` setting is out of range.
     */
    public function kernel(?Router $router = null): Kernel
    {
        return new Kernel(
            $router ?? $this->router(),
            $this->metrics,
            $this->errorLog,
            requestLog: $this->requestLog(),
            blocking: $this->ipBlocking(),
            clock: $this->clock,
            trustedProxy: Env::nonEmpty('TRUSTED_PROXY'),
        );
    }

    public function requestLog(): RequestLog
    {
        return new RequestLog($this->db, $this->errorLog);
    }

    public function ipBlocks(): IpBlocks
    {
        return new IpBlocks($this->db);
    }

    /**
     * Automatic blocking from the `block.*` settings.
     */
    public function ipBlocking(): IpBlocking
    {
        return IpBlocking::fromConfig(
            $this->ipBlocks(),
            $this->requestLog(),
            $this->rateLimiter(),
            $this->metrics,
            $this->config,
            Env::nonEmpty('TRUSTED_PROXY'),
        );
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

    public function upstreamBreaker(): UpstreamBreaker
    {
        return new UpstreamBreaker($this->db, $this->clock);
    }

    /**
     * The rate cache (P1-04). The upstream client is built on first use.
     */
    public function rates(): RateCache
    {
        return $this->rates ??= new RateCache(
            new RateStore($this->db),
            $this->rateClient ??= ExchangeRateClient::fromEnvironment(),
            $this->upstreamBreaker(),
            $this->metrics,
            $this->config->int('rates.max_stale_hours'),
            $this->clock,
        );
    }

    /**
     * The real allow-list: the stored rate table's codes, or the fallback currency list.
     */
    public function currencyAllowList(): RateTableAllowList
    {
        return new RateTableAllowList(new RateStore($this->db));
    }
}
