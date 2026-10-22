<?php

declare(strict_types=1);

namespace FxInstallments\Http;

use Closure;
use FxInstallments\Db\Connection;
use FxInstallments\Db\Migrations;
use FxInstallments\Db\Migrator;
use FxInstallments\Db\SchemaCorrupt;
use FxInstallments\Db\SchemaOutOfDate;
use FxInstallments\Metrics\ErrorCodes;
use FxInstallments\Rates\BreakerState;
use FxInstallments\Rates\QuotaCache;
use FxInstallments\Rates\RateStore;
use FxInstallments\Rates\UpstreamBreaker;
use PDO;
use Throwable;

/**
 * `GET /health` (docs/PLAN.md §6, P1-08). No rate limiter and no admin middleware: the public
 * view must answer whatever state the database is in, and the admin view is chosen here.
 *
 * - **Healthy** means the database opens, its schema is current (or ahead, ADR 0019) and it
 *   accepts a write: a transaction that updates the `schema_version` row in place and is
 *   rolled back, so nothing changes. Upstream trouble isn't unhealthy: lookups then serve stale
 *   rates or a mapped error, which the admin view shows.
 * - **Public** (no `Authorization` header, or one with another scheme than Bearer): 200
 *   `{"status": "ok"}`, or 503 UNHEALTHY. Nothing else, so the public view tells a caller
 *   nothing about the setup.
 * - **Admin** (a valid `Authorization: Bearer` admin token): always 200 with `status` (`ok` or
 *   `unhealthy`) and the details: the database (`open`, `schema`, versions, `writable`), the
 *   stored rate table (age, `cache_age_seconds`, freshness), the breaker (state, kind, code,
 *   times and `failures`, the consecutive failed refreshes behind a backoff), and the upstream
 *   quota from QuotaCache with both reset dates: upstream's `refresh_day_of_month` and the
 *   breaker's quota block, which ends on the 1st (UTC). Monitors poll the public view's HTTP
 *   status; the admin view is for operators.
 * - A Bearer header with a wrong or malformed token, or any Bearer header while ADMIN_TOKEN is
 *   unset, is 401 ADMIN_UNAUTHORIZED, so a wrong token is noticed (and counts toward automatic
 *   blocking like any 4xx).
 * - While the database can't be written, the admin view doesn't ask upstream for the quota
 *   (the answer couldn't be cached, so every view would call): it shows the last stored
 *   answer with `source: blocked`.
 *
 * public/index.php doesn't open the database before routing `GET` on this path (every other path still
 * fails closed there, ADR 0019), so this answers even when the database is behind the code or
 * can't be opened. The Kernel's block check then fails open, and its request-log row and
 * `error` event can't be written; that never changes this answer (INV-07, INV-08). A database
 * behind the code is never written: it is only read through Migrator. The error log gets the
 * exception class of an unusable database, never its message.
 *
 * No IP, key or upstream text is ever in the body.
 */
final class HealthEndpoint
{
    public const PATH = '/health';

    /** @var Closure(): int */
    private readonly Closure $clock;

    /** @var Closure(string): void */
    private readonly Closure $errorLog;

    /**
     * @param int $maxStaleHours `rates.max_stale_hours`, for `usable_when_stale`
     * @param (Closure(): int)|null $clock UTC epoch seconds; defaults to time()
     * @param (Closure(string): void)|null $errorLog defaults to error_log()
     */
    public function __construct(
        private readonly Connection $db,
        private readonly AdminAuth $auth,
        private readonly RateStore $rates,
        private readonly UpstreamBreaker $breaker,
        private readonly QuotaCache $quota,
        private readonly int $maxStaleHours,
        ?Closure $clock = null,
        ?Closure $errorLog = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
        $this->errorLog = $errorLog ?? static function (string $line): void {
            error_log($line);
        };
    }

    public function __invoke(Request $request): JsonResponse
    {
        $admin = self::asksForAdmin($request->header('authorization'));
        if ($admin && !$this->auth->allows($request)) {
            return AdminAuth::unauthorized();
        }

        $now = ($this->clock)();
        [$database, $pdo] = $this->database();
        $usable = $pdo !== null;
        $healthy = $usable && $database['writable'] === true;

        if (!$admin) {
            $response = $healthy ? JsonResponse::ok(['status' => 'ok']) : JsonResponse::error(ErrorCodes::UNHEALTHY);

            return $response->withHeader('Cache-Control', 'no-store');
        }

        $breaker = null;
        $breakerRead = false;
        if ($usable) {
            try {
                $breaker = $this->breaker->state();
                $breakerRead = true;
            } catch (Throwable $e) {
                $this->note($e);
            }
        }

        return JsonResponse::ok([
            'status' => $healthy ? 'ok' : 'unhealthy',
            'checked_at' => self::iso($now),
            'database' => $database,
            'rates' => $usable ? $this->guarded(fn (): array => $this->rates($now)) : null,
            'breaker' => $breakerRead ? self::breaker($breaker, $now) : null,
            // A database that can't be written can't cache the answer, so upstream isn't asked
            // (it would be on every view): the last stored answer is shown instead (round 2).
            'quota' => $usable ? $this->guarded(fn (): array => $this->quota($now, $breaker, $database['writable'] === true)) : null,
        ])->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Whether the request asks for the admin view: an `Authorization` header with the Bearer
     * scheme (any case, possibly malformed after it). Any other scheme, such as a proxy's Basic
     * credentials, or an empty header, gets the public view (round 2).
     */
    private static function asksForAdmin(?string $authorization): bool
    {
        return $authorization !== null && preg_match('/^\s*bearer(?:\s|$)/i', $authorization) === 1;
    }

    /**
     * The database part, and the PDO when the schema is usable (current or ahead).
     *
     * @return array{array<string, mixed>, ?PDO}
     */
    private function database(): array
    {
        $latest = Migrations::latestVersion();
        $state = ['open' => false, 'schema' => 'unknown', 'schema_version' => null, 'latest_version' => $latest, 'writable' => null];
        try {
            $pdo = $this->db->pdo();
            $version = (new Migrator($pdo))->currentVersion();
        } catch (SchemaOutOfDate $e) {
            $this->note($e);

            return [['open' => true, 'schema' => 'behind', 'schema_version' => $e->current] + $state, null];
        } catch (SchemaCorrupt $e) {
            $this->note($e);

            return [['open' => true, 'schema' => 'corrupt'] + $state, null];
        } catch (Throwable $e) {
            $this->note($e);

            return [$state, null];
        }

        $state = ['open' => true, 'schema_version' => $version] + $state;
        if ($version < $latest) {
            $this->note(new SchemaOutOfDate($version, $latest));

            return [['schema' => 'behind'] + $state, null];
        }
        $state['schema'] = $version === $latest ? 'current' : 'ahead';
        $state['writable'] = self::writable($pdo);

        return [$state, $pdo];
    }

    /**
     * Whether a write transaction can update a row: the schema_version row is set to itself and
     * the transaction rolled back, so nothing is changed. A read-only file or connection, or a
     * write lock held past the busy timeout, gives false.
     */
    private static function writable(PDO $pdo): bool
    {
        try {
            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE app_state SET value = value WHERE key = ?')->execute([Migrator::VERSION_KEY]);

                return true;
            } finally {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function rates(int $now): array
    {
        $table = $this->rates->table();
        if ($table === null) {
            return ['cached' => false, 'currencies' => 0];
        }
        $age = $table->ageAt($now);

        return [
            'cached' => true,
            'currencies' => count($table->rates),
            'rate_as_of' => self::iso($table->lastUpdateUnix),
            'rate_age_seconds' => $age,
            'fetched_at' => self::iso($table->fetchedAt),
            'cache_age_seconds' => $now - $table->fetchedAt,
            'next_update' => self::iso($table->nextUpdateUnix),
            'fresh' => $now < $table->nextUpdateUnix,
            'usable_when_stale' => $age < $this->maxStaleHours * 3600,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function breaker(?BreakerState $state, int $now): array
    {
        if ($state === null) {
            return ['state' => 'closed', 'kind' => null, 'error_code' => null, 'since' => null, 'until' => null, 'failures' => 0];
        }

        return [
            'state' => $state->isOpenAt($now) ? 'open' : 'expired',
            'kind' => $state->kind,
            'error_code' => $state->errorCode,
            'since' => self::iso($state->since),
            'until' => $state->until === null ? null : self::iso($state->until),
            // Consecutive failed refreshes: the next backoff grows with it (ADR 0012).
            'failures' => $state->failures,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function quota(int $now, ?BreakerState $breaker, bool $mayAsk): array
    {
        $status = $this->quota->status($mayAsk);
        $quota = $status->quota;

        return [
            'source' => $status->source,
            'checked_at' => $status->checkedAt === null ? null : self::iso($status->checkedAt),
            'age_seconds' => $status->checkedAt === null ? null : $now - $status->checkedAt,
            'plan_quota' => $quota?->planQuota,
            'requests_remaining' => $quota?->requestsRemaining,
            'refresh_day_of_month' => $quota?->refreshDayOfMonth,
            'error_code' => $status->errorCode,
            'resets' => [
                'upstream_refresh_date' => $quota?->nextRefreshDate($now),
                'breaker_quota_block_until' => $breaker !== null && $breaker->kind === BreakerState::QUOTA && $breaker->isOpenAt($now) && $breaker->until !== null
                    ? self::iso($breaker->until)
                    : null,
            ],
        ];
    }

    /**
     * Runs one admin section; a failure gives null (the section shows null) and a note.
     *
     * @param Closure(): array<string, mixed> $section
     * @return array<string, mixed>|null
     */
    private function guarded(Closure $section): ?array
    {
        try {
            return $section();
        } catch (Throwable $e) {
            $this->note($e);

            return null;
        }
    }

    private function note(Throwable $e): void
    {
        try {
            ($this->errorLog)(sprintf('fx-installments health: %s', $e::class));
        } catch (Throwable) {
            // Logging must not change the answer.
        }
    }

    private static function iso(int $unix): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $unix);
    }
}
