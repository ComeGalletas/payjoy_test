<?php

declare(strict_types=1);

namespace FxInstallments;

use Closure;
use FxInstallments\Config\ConfigRepository;
use FxInstallments\Convert\ConvertService;
use FxInstallments\Db\Connection;
use FxInstallments\Db\Database;
use FxInstallments\Db\Migrator;
use FxInstallments\Flow\FlowDefinition;
use FxInstallments\Flow\FlowEngine;
use FxInstallments\Flow\SessionStore;
use FxInstallments\Flow\SessionSweeper;
use FxInstallments\Http\AdminAuth;
use FxInstallments\Http\BotMessageEndpoint;
use FxInstallments\Http\ConvertEndpoint;
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
use FxInstallments\Validation\AmountParser;
use FxInstallments\Validation\AmountValidator;
use FxInstallments\Validation\CurrencyResolver;
use FxInstallments\Validation\CurrencyValidator;
use FxInstallments\Validation\InstallmentsValidator;
use PDO;

/**
 * Wires the shared services (config, database, metrics, middleware) and the production route
 * table. public/index.php uses it after Bootstrap::init(); tests build it with their own
 * database and clock.
 */
final class App
{
    /** The flow file (docs/PLAN.md §4, §9). */
    public const DEFAULT_FLOW = __DIR__ . '/../flows/default.yaml';

    public readonly Metrics $metrics;

    /** @var Closure(): int */
    private readonly Closure $clock;

    private ?RateCache $rates = null;

    /**
     * @param (Closure(): int)|null $clock UTC epoch seconds; defaults to time()
     * @param (Closure(string): void)|null $errorLog where Metrics and Kernel note failures;
     *        defaults to error_log()
     * @param ExchangeRateClient|null $rateClient the upstream client; defaults to
     *        ExchangeRateClient::fromEnvironment(), built on the first refresh (never on a
     *        cache hit). Tests pass one with a mocked handler.
     * @param string|null $flowPath the flow file; defaults to DEFAULT_FLOW (api/flows/default.yaml)
     */
    public function __construct(
        public readonly ConfigRepository $config,
        public readonly Connection $db,
        ?Closure $clock = null,
        private readonly ?Closure $errorLog = null,
        private ?ExchangeRateClient $rateClient = null,
        private readonly ?string $flowPath = null,
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
     * The production route table: `GET /convert` (P1-05) behind the rate limiter, and
     * `POST /bot/message` (P1-06) behind Cors and the same global rate limiter (ADR 0016 second
     * amendment: one `ratelimit.per_minute`). Every other path is NOT_FOUND. Building it touches
     * neither the database, the flow file nor the upstream client.
     */
    public function router(): Router
    {
        $router = new Router();
        $router->get(ConvertEndpoint::PATH, $this->convertEndpoint(), [$this->rateLimiter()]);
        $router->post(BotMessageEndpoint::PATH, $this->botMessageEndpoint(), [$this->cors(), $this->rateLimiter()]);

        return $router;
    }

    public function botMessageEndpoint(): BotMessageEndpoint
    {
        return new BotMessageEndpoint(fn (): FlowEngine => $this->flowEngine());
    }

    /**
     * The flow engine on the flow file, the sessions table and the conversion service.
     *
     * @throws \FxInstallments\Flow\FlowDefinitionError when the flow file is invalid
     */
    public function flowEngine(): FlowEngine
    {
        $sessions = $this->sessionStore();

        return new FlowEngine(
            $this->flow(),
            $sessions,
            new SessionSweeper($sessions, $this->metrics, $this->clock),
            $this->metrics,
            new AmountParser(new AmountValidator($this->config)),
            new CurrencyResolver(new CurrencyValidator($this->currencyAllowList())),
            new InstallmentsValidator($this->config),
            fn (): ConvertService => $this->convertService(),
            $this->clock,
        );
    }

    /**
     * The flow file, loaded and checked on every call (it is small; a request builds one engine).
     */
    public function flow(): FlowDefinition
    {
        return FlowDefinition::fromFile($this->flowPath ?? self::DEFAULT_FLOW, $this->config);
    }

    public function sessionStore(): SessionStore
    {
        return new SessionStore($this->db, $this->config->int('bot.session_ttl_minutes'));
    }

    /**
     * The expiry sweep without the flow file, for reports (P1-08) and the engine.
     */
    public function sessionSweeper(): SessionSweeper
    {
        return new SessionSweeper($this->sessionStore(), $this->metrics, $this->clock);
    }

    public function convertEndpoint(): ConvertEndpoint
    {
        return new ConvertEndpoint(fn (): ConvertService => $this->convertService(), $this->metrics);
    }

    /**
     * The conversion service on the real allow-list and the rate cache.
     */
    public function convertService(): ConvertService
    {
        return new ConvertService(
            new AmountValidator($this->config),
            new CurrencyValidator($this->currencyAllowList()),
            new InstallmentsValidator($this->config),
            $this->rates(),
        );
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
     * The rate cache (P1-04). The upstream client is built on the first refresh.
     */
    public function rates(): RateCache
    {
        // Built on the first refresh, never on a cache hit (P1-06): bin/bot.php and its test run
        // without an HTTP transport when the stored table is fresh.
        return $this->rates ??= new RateCache(
            new RateStore($this->db),
            fn (): ExchangeRateClient => $this->rateClient ??= ExchangeRateClient::fromEnvironment(),
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
