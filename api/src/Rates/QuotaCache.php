<?php

declare(strict_types=1);

namespace FxInstallments\Rates;

use Closure;
use FxInstallments\Db\Connection;
use FxInstallments\Metrics\ErrorCodes;
use FxInstallments\Metrics\Metrics;
use InvalidArgumentException;
use JsonException;
use PDOException;
use Throwable;

/**
 * Upstream's `/quota`, cached for the admin `/health` view (docs/PLAN.md §3b, P1-08): upstream
 * is asked at most once per `health.quota_cache_minutes`, whether the answer was a quota or an
 * error, so neither admin traffic nor a failing upstream can turn health checks into calls.
 *
 * - The answer is one `app_state` row, STATE_KEY (JSON: `checked_at`, the three quota numbers
 *   or `error_code`). A row that doesn't read back exactly is ignored. A failed write (a
 *   read-only database) is ignored too: the answer is still shown, and the next view asks again.
 * - While the breaker holds an **auth** block, the key is known bad, so upstream is not asked.
 *   Other trips don't stop it: `/quota` isn't a refresh, and it never trips the breaker.
 * - Every status() writes one `upstream.call` event (source `upstream`, `meta.endpoint=quota`)
 *   with `meta.result` = `hit` (cached), `miss` (asked; `reason` on failure) or `blocked`, like
 *   the rate cache's events, so a report counts real calls the same way: `miss` except
 *   `reason=no_key`. No key, URL or upstream text goes in the event.
 */
final class QuotaCache
{
    public const STATE_KEY = 'upstream_quota';

    /** @var Closure(): ExchangeRateClient */
    private readonly Closure $client;

    /** @var Closure(): int */
    private readonly Closure $clock;

    private readonly int $cacheSeconds;

    /**
     * @param ExchangeRateClient|(Closure(): ExchangeRateClient) $client built only when upstream is asked
     * @param int $cacheMinutes `health.quota_cache_minutes`, at least 1
     * @param (Closure(): int)|null $clock UTC epoch seconds; defaults to time()
     */
    public function __construct(
        private readonly Connection $db,
        ExchangeRateClient|Closure $client,
        private readonly UpstreamBreaker $breaker,
        private readonly Metrics $metrics,
        int $cacheMinutes,
        ?Closure $clock = null,
    ) {
        if ($cacheMinutes < 1) {
            throw new InvalidArgumentException('health.quota_cache_minutes must be at least 1');
        }
        $this->cacheSeconds = $cacheMinutes * 60;
        $this->client = $client instanceof ExchangeRateClient ? static fn (): ExchangeRateClient => $client : $client;
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function status(): QuotaStatus
    {
        $started = hrtime(true);
        $now = ($this->clock)();
        $cached = $this->read();
        if ($cached !== null && $cached->checkedAt !== null && $now >= $cached->checkedAt && $now < $cached->checkedAt + $this->cacheSeconds) {
            $this->record('hit', $started, $cached->errorCode, null);

            return $cached;
        }

        if ($this->breaker->blocking()?->kind === BreakerState::AUTH) {
            $this->record('blocked', $started, ErrorCodes::UPSTREAM_AUTH, BreakerState::AUTH);

            return new QuotaStatus(QuotaStatus::BLOCKED, $cached?->checkedAt, $cached?->quota, ErrorCodes::UPSTREAM_AUTH);
        }

        $quota = null;
        $errorCode = null;
        $reason = null;
        try {
            $quota = ($this->client)()->quota();
        } catch (UpstreamError $error) {
            $errorCode = $error->errorCode;
            $reason = $error->reason;
        }
        $status = new QuotaStatus(QuotaStatus::UPSTREAM, $now, $quota, $errorCode);
        $this->record('miss', $started, $errorCode, $reason);
        try {
            $this->write($status, $now);
        } catch (Throwable) {
            // The answer is still shown; the next view asks again.
        }

        return $status;
    }

    private function read(): ?QuotaStatus
    {
        $select = $this->db->pdo()->prepare('SELECT value FROM app_state WHERE key = ?');
        $select->execute([self::STATE_KEY]);
        $value = $select->fetchColumn();
        if (!is_string($value)) {
            return null;
        }
        try {
            $data = json_decode($value, true, 2, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($data) || !is_int($data['checked_at'] ?? null)) {
            return null;
        }
        $code = $data['error_code'] ?? null;
        if (is_string($code)) {
            return in_array($code, UpstreamError::CODES, true)
                ? new QuotaStatus(QuotaStatus::CACHE, $data['checked_at'], null, $code)
                : null;
        }
        $plan = $data['plan_quota'] ?? null;
        $remaining = $data['requests_remaining'] ?? null;
        $day = $data['refresh_day_of_month'] ?? null;
        if (!is_int($plan) || $plan < 0 || !is_int($remaining) || !is_int($day) || $day < 1 || $day > 31) {
            return null;
        }

        return new QuotaStatus(QuotaStatus::CACHE, $data['checked_at'], new Quota($plan, $remaining, $day), null);
    }

    /**
     * Select-then-write (ADR 0010), like the breaker's row.
     */
    private function write(QuotaStatus $status, int $now): void
    {
        $value = json_encode($status->quota === null
            ? ['checked_at' => $now, 'error_code' => $status->errorCode]
            : [
                'checked_at' => $now,
                'plan_quota' => $status->quota->planQuota,
                'requests_remaining' => $status->quota->requestsRemaining,
                'refresh_day_of_month' => $status->quota->refreshDayOfMonth,
            ], JSON_THROW_ON_ERROR);
        $pdo = $this->db->pdo();
        $update = $pdo->prepare('UPDATE app_state SET value = ?, updated_at = ? WHERE key = ?');
        $update->execute([$value, $now, self::STATE_KEY]);
        if ($update->rowCount() > 0) {
            return;
        }
        try {
            $pdo->prepare('INSERT INTO app_state (key, value, updated_at) VALUES (?, ?, ?)')->execute([self::STATE_KEY, $value, $now]);
        } catch (PDOException $e) {
            // Another process inserted it first: overwrite it.
            $update->execute([$value, $now, self::STATE_KEY]);
            if ($update->rowCount() === 0) {
                throw $e;
            }
        }
    }

    private function record(string $result, int $started, ?string $errorCode, ?string $reason): void
    {
        $meta = ['result' => $result, 'endpoint' => 'quota'];
        if ($reason !== null) {
            $meta['reason'] = $reason;
        }
        $this->metrics->record('upstream.call', [
            'source' => 'upstream',
            'status' => $errorCode === null ? 'ok' : 'error',
            'error_code' => $errorCode,
            'latency_ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'meta' => $meta,
        ]);
    }
}
