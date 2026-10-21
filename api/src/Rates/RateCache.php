<?php

declare(strict_types=1);

namespace FxInstallments\Rates;

use Closure;
use FxInstallments\Metrics\ErrorCodes;
use FxInstallments\Metrics\Metrics;
use InvalidArgumentException;
use Throwable;

/**
 * The rate cache (docs/PLAN.md §3b, §5; ADR 0012): `rate_cache` holds the whole table from the
 * last `/latest/USD` refresh, and every lookup is served from it.
 *
 * - **Hit**: `now < next_update_unix` → the stored rate, no upstream call.
 * - **Miss**: otherwise one refresh, which rewrites every row in one transaction; so all
 *   lookups in one refresh window cost one upstream call between them.
 * - **Stale**: when the refresh fails, or the breaker blocks it, a stored table younger than
 *   `rates.max_stale_hours` is served with `stale: true`. Age is measured from
 *   `last_update_unix` (upstream's `time_last_update_unix`, the `rate_as_of` users see), so
 *   "48 hours" means the rate itself is at most 48 hours old, whenever it was fetched.
 *   Otherwise the mapped error is thrown.
 * - `next_update_unix` is upstream's `time_next_update_unix`, but never earlier than
 *   MIN_REFRESH_SECONDS after the fetch: if upstream is late and sends a time already past,
 *   lookups don't each call upstream again.
 * - Every failed refresh trips the breaker (UpstreamBreaker), including one whose answer was
 *   fine but whose `rate_cache` write failed (the fetched rate is still served once); every
 *   successful one clears its backoff. So upstream calls are bounded by time, not traffic.
 * - While a backoff opened by `unsupported-code` is open (and for the failed refresh that
 *   opened it), the UPSTREAM_UNSUPPORTED_CODE error thrown is marked
 *   `duringUnsupportedCodeBackoff`, so `/convert` answers 503 (ADR 0012 amendment item 9). A
 *   currency missing from a usable table (`not_in_table`) stays a plain 400.
 *
 * Every lookup writes one `upstream.call` event (source `upstream`) with `meta.result` = `hit`,
 * `miss`, `stale` or `blocked`, `meta.endpoint` = `latest` and `meta.stale`; a failed refresh
 * adds its fixed `reason` (and `refresh_error` when stale data was served). No key, URL or
 * upstream text goes in an event; dropped currencies are listed by code only when the code is
 * a valid 3-letter code.
 */
final class RateCache
{
    public const MIN_REFRESH_SECONDS = 300;

    /** At most this many dropped currencies are listed in one event (all are counted). */
    private const MAX_DROPPED_IN_EVENT = 20;

    /** @var Closure(): int */
    private readonly Closure $clock;

    private readonly int $maxStaleSeconds;

    /**
     * @param (Closure(): int)|null $clock UTC epoch seconds; defaults to time()
     */
    public function __construct(
        private readonly RateStore $store,
        private readonly ExchangeRateClient $client,
        private readonly UpstreamBreaker $breaker,
        private readonly Metrics $metrics,
        int $maxStaleHours,
        ?Closure $clock = null,
    ) {
        if ($maxStaleHours < 0) {
            throw new InvalidArgumentException('rates.max_stale_hours must not be negative');
        }
        $this->maxStaleSeconds = $maxStaleHours * 3600;
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * The USD → $currency rate, fresh or stale.
     *
     * @param string $currency an uppercase 3-letter code, already validated against the
     *        allow-list; it is never sent upstream
     *
     * @throws UpstreamError the mapped error when no usable rate exists;
     *         UPSTREAM_UNSUPPORTED_CODE when a fresh table doesn't have the currency
     */
    public function rate(string $currency): RateResult
    {
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new InvalidArgumentException('A currency is an uppercase 3-letter code');
        }
        $started = hrtime(true);
        $now = ($this->clock)();
        $table = $this->store->table();

        if ($table !== null && $now < $table->nextUpdateUnix) {
            return $this->serve($table, $currency, 'hit', false, $started, []);
        }

        $blocking = $this->breaker->blocking();
        if ($blocking !== null) {
            $meta = ['reason' => $blocking->kind];
            if ($table !== null && $this->usableStale($table, $currency, $now)) {
                return $this->serve($table, $currency, 'blocked', true, $started, $meta);
            }
            $error = $blocking->error();
            if (self::isUnsupportedCodeBackoff($blocking, $error->errorCode)) {
                $error = $error->duringBackoff();
            }
            $this->record($currency, 'blocked', false, $started, $error, $meta);
            throw $error;
        }

        try {
            $latest = $this->client->latest();
        } catch (UpstreamError $error) {
            $meta = ['reason' => $error->reason];
            if ($error->httpStatus !== null) {
                $meta['http_status'] = $error->httpStatus;
            }
            $trip = null;
            try {
                $trip = $this->breaker->trip($error);
                if ($trip !== null) {
                    $meta['breaker'] = $trip->kind;
                }
            } catch (Throwable) {
                $meta['breaker_write_failed'] = true;
            }
            if ($table !== null && $this->usableStale($table, $currency, $now)) {
                return $this->serve($table, $currency, 'stale', true, $started, ['refresh_error' => $error->errorCode] + $meta);
            }
            // The refresh that opened an `unsupported-code` backoff answers after it is
            // recorded, so it gets the backoff's 503 too; without a recorded backoff it doesn't.
            if (self::isUnsupportedCodeBackoff($trip, $error->errorCode)) {
                $error = $error->duringBackoff();
            }
            $this->record($currency, 'miss', false, $started, $error, $meta);
            throw $error;
        }

        $fresh = new RateTable(
            $latest->rates,
            $latest->lastUpdateUnix,
            max($latest->nextUpdateUnix, $now + self::MIN_REFRESH_SECONDS),
            $now,
        );
        $meta = ['currencies' => count($latest->rates)];
        if ($latest->dropped !== []) {
            $meta['dropped_count'] = count($latest->dropped);
            $meta['dropped'] = array_slice($latest->dropped, 0, self::MAX_DROPPED_IN_EVENT);
        }
        try {
            $this->store->replace($fresh);
            $stored = true;
        } catch (Throwable) {
            $stored = false;
        }
        try {
            if ($stored) {
                $this->breaker->succeeded();
            } else {
                // Serve what was fetched, but back off: without the stored table every lookup
                // would refresh again.
                $meta['store_failed'] = true;
                $trip = $this->breaker->trip(new UpstreamError(ErrorCodes::UPSTREAM_MALFORMED, 'store_failed'));
                if ($trip !== null) {
                    $meta['breaker'] = $trip->kind;
                }
            }
        } catch (Throwable) {
            $meta['breaker_write_failed'] = true;
        }

        return $this->serve($fresh, $currency, 'miss', false, $started, $meta);
    }

    /**
     * ADR 0012 amendment item 9: an UPSTREAM_UNSUPPORTED_CODE error answered while $trip, a
     * backoff opened by `unsupported-code`, is in force is marked so the API answers 503. Any
     * other error, or no such backoff (a failed breaker write, another kind of trip), stays as
     * it is.
     *
     * Only the error code goes in, never the exception: an app frame's arguments must not hold
     * an exception, whose trace holds its callers' arguments (the INV-03 leak test dumps them).
     */
    private static function isUnsupportedCodeBackoff(?BreakerState $trip, string $errorCode): bool
    {
        return $trip !== null
            && $trip->kind === BreakerState::BACKOFF
            && $trip->errorCode === ErrorCodes::UPSTREAM_UNSUPPORTED_CODE
            && $errorCode === ErrorCodes::UPSTREAM_UNSUPPORTED_CODE;
    }

    private function usableStale(RateTable $table, string $currency, int $now): bool
    {
        return isset($table->rates[$currency]) && $table->ageAt($now) < $this->maxStaleSeconds;
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @throws UpstreamError UPSTREAM_UNSUPPORTED_CODE when the table lacks the currency
     */
    private function serve(RateTable $table, string $currency, string $result, bool $stale, int $started, array $meta): RateResult
    {
        if (!isset($table->rates[$currency])) {
            $error = new UpstreamError(ErrorCodes::UPSTREAM_UNSUPPORTED_CODE, 'not_in_table');
            $this->record($currency, $result, $stale, $started, $error, $meta);
            throw $error;
        }
        $this->record($currency, $result, $stale, $started, null, $meta);

        return new RateResult($currency, $table->rates[$currency], $table->lastUpdateUnix, $stale, $table->fetchedAt);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function record(string $currency, string $result, bool $stale, int $started, ?UpstreamError $error, array $meta): void
    {
        $this->metrics->record('upstream.call', [
            'source' => 'upstream',
            'currency' => $currency,
            'status' => $error === null ? 'ok' : 'error',
            'error_code' => $error?->errorCode,
            'latency_ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'meta' => ['result' => $result, 'endpoint' => 'latest', 'stale' => $stale] + $meta,
        ]);
    }
}
