<?php

declare(strict_types=1);

namespace FxInstallments\Metrics;

use Closure;
use FxInstallments\Db\Connection;
use JsonException;
use PDO;
use Throwable;

/**
 * The numbers behind `/reports` and `bin/report.php` (docs/PLAN.md §1 "measurable", §6; P1-08),
 * read from `events` and `requests` over a ReportPeriod. Plain integers and fixed words only;
 * ReportFormatter turns them into JSON, CSV or a table. Portable SQL (§8): no date or JSON
 * functions, `meta` is decoded here.
 *
 * **Never shown:** an IP (`requests.ip` and `ip_blocks` are never selected), the API key, or
 * any upstream text (no event holds them). Labels that come from event columns (currency,
 * country, step, error code) are cut to MAX_LABEL characters, with invalid UTF-8 and control
 * characters replaced, so a hand-edited row can't break the JSON or a terminal.
 *
 * **Before counting**, expired sessions are closed (`App::sessionSweeper()`, ADR 0021), so the
 * funnel doesn't show them as still open. A failed sweep doesn't stop the report; it adds a note.
 *
 * Sections, all for events with `from <= ts < to` unless said otherwise:
 * - **summary** and **days** (split on `reports.timezone`): sessions started, completed and
 *   abandoned (event counts), conversions (an `api.request` with status ok plus a
 *   `bot.session_completed`) and their USD total as an integer sum of `amount_usd_cents`,
 *   `/convert` requests and errors, error responses, upstream calls.
 * - **currencies:** per currency, `requested` (an `api.request` with a validated currency, or a
 *   bot currency step answered) and conversions, split api and bot, with the cents total.
 *   **countries:** per typed country (bot only), `requested` and conversions.
 * - **funnel** per channel: the sessions that *started* in the period (`bot.session_start`),
 *   followed to their end even after the period: started → each step (reached, answered,
 *   retries, abandoned) → completed. `retries` counts every failed answer at a step, a
 *   conversion that sent the user back (`via: convert`) included. A session that ended with no
 *   answer at all (an opened widget, closed as expired at `amount`) is `never_answered`, its
 *   own outcome, not counted in `abandoned`; it is still a `bot.session_abandoned` event
 *   (ADR 0022). Open sessions are `open`. So started = completed + abandoned + never_answered
 *   + open.
 * - **errors** by code: `http`, one per error response (the Kernel's `error` event; a
 *   `/convert` 4xx or 503 also has an `api.request` event, which is not counted again;
 *   rate-limited and INTERNAL answers have only the `error` event; blocked requests have
 *   neither), and `bot`, the `bot.step` and `bot.session_abandoned` events with a code.
 *   Upstream failures are counted in **upstream**, not here.
 * - **upstream** per endpoint (`meta.endpoint`, `latest` when missing): lookups by result
 *   (`hit`, `miss`, `stale`, `blocked`) and real calls: `miss` and `stale` lookups except those
 *   with `reason=no_key` (a blocked lookup, stale or not, made no call). A call failed when
 *   its event has a `reason` (miss) or a `refresh_error` (stale). `/quota` calls come from the
 *   admin `/health` view; `/codes` is never called in Phase 1.
 * - **requests** (the request log, `requests`, kept `requests.retention_days`): totals by kind
 *   (`api`, `widget_asset` for the widget's page, script, style sheet and icon, `favicon` for
 *   `/favicon.ico`), status class and blocked. Paths themselves are never listed.
 * - **ip_blocks:** `ip.block` events by `block_source` and `reason`, `ip.unblock` by
 *   `block_source`.
 *
 * Cost: one pass over the period's events, one over the funnel cohort's bot events (from the
 * period start on, through the `events_ts` index), and one grouped read of `requests`.
 */
final class ReportQuery
{
    public const MAX_LABEL = 64;

    public const CHANNELS = Metrics::CHANNELS;

    /** The flow's steps in order (docs/PLAN.md §9); other step names follow, sorted. */
    public const STEPS = ['amount', 'currency', 'installments', 'result'];

    public const ENDPOINTS = ['latest', 'codes', 'quota'];

    public const RESULTS = ['hit', 'miss', 'stale', 'blocked'];

    /** The widget's file routes (WidgetEndpoint), for the request-log split. */
    public const WIDGET_ASSET_PATHS = ['/bot/widget', '/bot/widget.js', '/bot/widget.css', '/bot/widget.svg'];

    public const FAVICON_PATH = '/favicon.ico';

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param (Closure(): int)|null $clock UTC epoch seconds; defaults to time()
     * @param (Closure(): int)|null $sweep closes expired sessions before counting (App::sessionSweeper())
     * @param int $requestsRetentionDays `requests.retention_days`, for the request-log note
     */
    public function __construct(
        private readonly Connection $db,
        ?Closure $clock = null,
        private readonly ?Closure $sweep = null,
        private readonly int $requestsRetentionDays = 30,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @return array<string, mixed> see the class comment; ReportFormatter documents the shape
     */
    public function run(ReportPeriod $period): array
    {
        $notes = [];
        if ($this->sweep !== null) {
            try {
                ($this->sweep)();
            } catch (Throwable) {
                $notes[] = 'Expired sessions could not be closed before counting, so some sessions may still show as open.';
            }
        }
        $now = ($this->clock)();

        $report = [
            'period' => [
                'from' => $period->from,
                'to' => $period->to,
                'timezone' => $period->timezone,
                'days' => $period->dayCount(),
                'start_unix' => $period->startUnix,
                'end_unix' => $period->endUnix,
            ],
            'generated_unix' => $now,
        ];

        $events = $this->eventSections($period);
        $report += $events['sections'];
        $report['funnel'] = $this->funnel($period);
        $report['requests'] = $this->requestLog($period);

        $notes[] = 'The funnel counts the sessions that started in the period, followed to their end.';
        $notes[] = 'Upstream calls are /latest/USD refreshes (miss and stale lookups, except reason no_key) and /quota calls from the admin health view; /codes is never called in Phase 1.';
        $notes[] = 'Each error response is counted once (its error event); a /convert error\'s api.request event is not counted again.';
        if ($events['missing_rate'] > 0) {
            $notes[] = sprintf(
                'Converted amounts are not recomputed; %d conversion(s) in this period have no stored rate_scaled (recorded before P1-08).',
                $events['missing_rate'],
            );
        }
        $retainedFrom = $now - $this->requestsRetentionDays * 86_400;
        if ($period->startUnix < $retainedFrom) {
            $notes[] = sprintf(
                'Request-log counts cover only the last %d days (requests.retention_days); older rows are pruned.',
                $this->requestsRetentionDays,
            );
        }
        $report['notes'] = $notes;

        return $report;
    }

    /**
     * One pass over the period's events: summary, days, currencies, countries, errors, upstream,
     * ip_blocks.
     *
     * @return array{sections: array<string, mixed>, missing_rate: int}
     */
    private function eventSections(ReportPeriod $period): array
    {
        $dayTemplate = [
            'sessions_started' => 0,
            'sessions_completed' => 0,
            'sessions_abandoned' => 0,
            'conversions' => 0,
            'amount_usd_cents' => 0,
            'api_requests' => 0,
            'error_responses' => 0,
            'upstream_calls' => 0,
        ];
        $days = [];
        foreach ($period->days as [$date]) {
            $days[] = ['date' => $date] + $dayTemplate;
        }
        $summary = $dayTemplate + ['conversions_api' => 0, 'conversions_bot' => 0, 'api_errors' => 0, 'bot_errors' => 0];
        $currencies = [];
        $countries = [];
        $errors = [];
        $upstream = [];
        foreach (self::ENDPOINTS as $endpoint) {
            $upstream[$endpoint] = self::endpointTemplate();
        }
        $failures = [];
        $breakerResets = 0;
        $blocks = [];
        $unblocks = [];
        $missingRate = 0;

        $statement = $this->db->pdo()->prepare(
            'SELECT ts, event_type, status, error_code, step, currency, country, amount_usd_cents, meta'
            . ' FROM events WHERE ts >= ? AND ts < ? ORDER BY ts, id',
        );
        $statement->execute([$period->startUnix, $period->endUnix]);
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $day = $period->dayIndex((int) $row['ts']);
            if ($day === null) {
                continue;
            }
            $type = (string) $row['event_type'];
            $ok = $row['status'] === 'ok';
            $code = self::label($row['error_code']);
            $currency = self::label($row['currency']);
            $country = self::label($row['country']);
            $cents = self::cents($row['amount_usd_cents']);
            $meta = self::meta($row['meta']);

            switch ($type) {
                case 'bot.session_start':
                    $days[$day]['sessions_started']++;
                    break;

                case 'bot.session_completed':
                    $days[$day]['sessions_completed']++;
                    self::conversion($days[$day], $summary, $currencies, $countries, 'bot', $currency, $country, $cents);
                    if (!isset($meta['rate_scaled'])) {
                        $missingRate++;
                    }
                    break;

                case 'bot.session_abandoned':
                    $days[$day]['sessions_abandoned']++;
                    if ($code !== null) {
                        self::countError($errors, $code, 'bot');
                        $summary['bot_errors']++;
                    }
                    break;

                case 'bot.step':
                    if ($code !== null) {
                        self::countError($errors, $code, 'bot');
                        $summary['bot_errors']++;
                    } elseif ($ok && $row['step'] === 'currency' && $currency !== null) {
                        self::requested($currencies, $currency);
                        if ($country !== null) {
                            self::requested($countries, $country);
                        }
                    }
                    break;

                case 'api.request':
                    $days[$day]['api_requests']++;
                    if ($currency !== null) {
                        self::requested($currencies, $currency);
                    }
                    if ($ok) {
                        self::conversion($days[$day], $summary, $currencies, $countries, 'api', $currency, null, $cents);
                        if (!isset($meta['rate_scaled'])) {
                            $missingRate++;
                        }
                    } else {
                        $summary['api_errors']++;
                    }
                    break;

                case 'error':
                    $days[$day]['error_responses']++;
                    self::countError($errors, $code ?? 'UNKNOWN', 'http');
                    break;

                case 'upstream.call':
                    $endpoint = self::label($meta['endpoint'] ?? 'latest') ?? 'latest';
                    $upstream[$endpoint] ??= self::endpointTemplate();
                    $result = is_string($meta['result'] ?? null) && in_array($meta['result'], self::RESULTS, true) ? $meta['result'] : null;
                    $upstream[$endpoint]['lookups']++;
                    if ($result === null) {
                        $upstream[$endpoint]['unknown']++;
                        break;
                    }
                    $upstream[$endpoint][$result]++;
                    if ($result !== 'miss' && $result !== 'stale') {
                        break;
                    }
                    if (($meta['reason'] ?? null) === 'no_key') {
                        $upstream[$endpoint]['no_key']++;
                        break;
                    }
                    $upstream[$endpoint]['calls']++;
                    $days[$day]['upstream_calls']++;
                    $failed = $result === 'stale'
                        ? self::label($meta['refresh_error'] ?? null)
                        : (isset($meta['reason']) ? ($code ?? 'UNKNOWN') : null);
                    if ($failed !== null) {
                        $upstream[$endpoint]['failed_calls']++;
                        $failures[$endpoint . "\0" . $failed] = ($failures[$endpoint . "\0" . $failed] ?? 0) + 1;
                    }
                    break;

                case 'upstream.breaker_reset':
                    $breakerResets++;
                    break;

                case 'ip.block':
                    $key = (self::label($meta['block_source'] ?? null) ?? 'none') . "\0" . (self::label($meta['reason'] ?? null) ?? 'none');
                    $blocks[$key] ??= ['count' => 0, 'extended' => 0];
                    $blocks[$key]['count']++;
                    if (($meta['extended'] ?? false) === true) {
                        $blocks[$key]['extended']++;
                    }
                    break;

                case 'ip.unblock':
                    $key = self::label($meta['block_source'] ?? null) ?? 'none';
                    $unblocks[$key] = ($unblocks[$key] ?? 0) + 1;
                    break;
            }
        }
        $statement->closeCursor();

        foreach ($days as $row) {
            foreach (array_keys($dayTemplate) as $key) {
                if (!in_array($key, ['conversions', 'amount_usd_cents'], true)) {
                    $summary[$key] += $row[$key];
                }
            }
        }

        ksort($currencies, SORT_STRING);
        ksort($countries, SORT_STRING);
        ksort($errors, SORT_STRING);
        ksort($failures, SORT_STRING);
        ksort($blocks, SORT_STRING);
        ksort($unblocks, SORT_STRING);

        $endpoints = [];
        foreach ($upstream as $endpoint => $counts) {
            $endpoints[] = ['endpoint' => (string) $endpoint] + $counts;
        }
        $failureRows = [];
        foreach ($failures as $key => $count) {
            [$endpoint, $code] = explode("\0", (string) $key, 2);
            $failureRows[] = ['endpoint' => $endpoint, 'code' => $code, 'count' => $count];
        }
        $blockRows = [];
        foreach ($blocks as $key => $counts) {
            [$source, $reason] = explode("\0", (string) $key, 2);
            $blockRows[] = ['block_source' => $source, 'reason' => $reason] + $counts;
        }
        $unblockRows = [];
        foreach ($unblocks as $source => $count) {
            $unblockRows[] = ['block_source' => (string) $source, 'count' => $count];
        }

        return [
            'sections' => [
                'summary' => $summary + ['conversions_missing_rate' => $missingRate],
                'days' => $days,
                'currencies' => self::rows('currency', $currencies),
                'countries' => self::rows('country', $countries),
                'errors' => self::rows('code', $errors),
                'upstream' => [
                    'calls' => array_sum(array_column($endpoints, 'calls')),
                    'endpoints' => $endpoints,
                    'failures' => $failureRows,
                    'breaker_resets' => $breakerResets,
                ],
                'ip_blocks' => ['blocks' => $blockRows, 'unblocks' => $unblockRows],
            ],
            'missing_rate' => $missingRate,
        ];
    }

    /**
     * The funnel per channel, for the sessions that started in the period.
     *
     * @return list<array<string, mixed>>
     */
    private function funnel(ReportPeriod $period): array
    {
        $statement = $this->db->pdo()->prepare(
            "SELECT session_id, channel, event_type, step, error_code, status FROM events WHERE source = 'bot' AND ts >= ?"
            . " AND session_id IN (SELECT session_id FROM events WHERE event_type = 'bot.session_start' AND ts >= ? AND ts < ?)"
            . ' ORDER BY id',
        );
        $statement->execute([$period->startUnix, $period->startUnix, $period->endUnix]);

        /** @var array<string, array{channel: ?string, answered: array<string, true>, retries: array<string, int>, steps: int, end: ?array{string, string, ?string}}> $sessions */
        $sessions = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $id = (string) $row['session_id'];
            $sessions[$id] ??= ['channel' => null, 'answered' => [], 'retries' => [], 'steps' => 0, 'end' => null];
            $step = self::label($row['step']) ?? 'none';
            switch ($row['event_type']) {
                case 'bot.session_start':
                    $sessions[$id]['channel'] ??= self::label($row['channel']) ?? 'none';
                    break;
                case 'bot.step':
                    $sessions[$id]['steps']++;
                    if ($row['error_code'] === null && $row['status'] === 'ok') {
                        $sessions[$id]['answered'][$step] = true;
                    } else {
                        $sessions[$id]['retries'][$step] = ($sessions[$id]['retries'][$step] ?? 0) + 1;
                    }
                    break;
                case 'bot.session_completed':
                    $sessions[$id]['end'] ??= ['completed', $step, null];
                    break;
                case 'bot.session_abandoned':
                    $sessions[$id]['end'] ??= ['abandoned', $step, self::label($row['error_code']) ?? 'UNKNOWN'];
                    break;
            }
        }
        $statement->closeCursor();

        $channels = [];
        foreach (self::CHANNELS as $channel) {
            $channels[$channel] = self::channelTemplate();
        }
        foreach ($sessions as $session) {
            if ($session['channel'] === null) {
                continue; // its start event was outside the cohort query (not possible; defensive)
            }
            $channel = $session['channel'];
            $channels[$channel] ??= self::channelTemplate();
            $funnel = &$channels[$channel];
            $funnel['started']++;
            foreach (array_keys($session['answered']) as $step) {
                $funnel['answered'][$step] = ($funnel['answered'][$step] ?? 0) + 1;
            }
            foreach ($session['retries'] as $step => $count) {
                $funnel['retries'][$step] = ($funnel['retries'][$step] ?? 0) + $count;
            }
            if ($session['end'] === null) {
                $funnel['open']++;
            } elseif ($session['end'][0] === 'completed') {
                $funnel['completed']++;
            } elseif ($session['steps'] === 0) {
                $funnel['never_answered']++;
            } else {
                [, $step, $code] = $session['end'];
                $funnel['abandoned']++;
                $funnel['abandoned_at'][$step] = ($funnel['abandoned_at'][$step] ?? 0) + 1;
                $funnel['abandoned_by_code'][(string) $code] = ($funnel['abandoned_by_code'][(string) $code] ?? 0) + 1;
            }
            unset($funnel);
        }

        $rows = [];
        foreach ($channels as $channel => $funnel) {
            $rows[] = self::funnelRow((string) $channel, $funnel);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $funnel
     * @return array<string, mixed>
     */
    private static function funnelRow(string $channel, array $funnel): array
    {
        $names = array_unique(array_merge(
            array_keys($funnel['answered']),
            array_keys($funnel['retries']),
            array_keys($funnel['abandoned_at']),
        ));
        $others = array_values(array_diff($names, self::STEPS));
        sort($others, SORT_STRING);

        $steps = [];
        $previous = $funnel['started'];
        foreach (self::STEPS as $step) {
            $answered = $funnel['answered'][$step] ?? 0;
            $row = ['step' => $step, 'reached' => $previous];
            if ($step === 'result') {
                $row['completed'] = $funnel['completed'];
            } else {
                $row['answered'] = $answered;
                $row['retries'] = $funnel['retries'][$step] ?? 0;
            }
            $row['abandoned'] = $funnel['abandoned_at'][$step] ?? 0;
            $steps[] = $row;
            $previous = $answered;
        }
        foreach ($others as $step) {
            $steps[] = [
                'step' => $step,
                'reached' => null,
                'answered' => $funnel['answered'][$step] ?? 0,
                'retries' => $funnel['retries'][$step] ?? 0,
                'abandoned' => $funnel['abandoned_at'][$step] ?? 0,
            ];
        }
        ksort($funnel['abandoned_by_code'], SORT_STRING);
        $byCode = [];
        foreach ($funnel['abandoned_by_code'] as $code => $count) {
            $byCode[] = ['code' => (string) $code, 'count' => $count];
        }

        return [
            'channel' => $channel,
            'started' => $funnel['started'],
            'completed' => $funnel['completed'],
            'abandoned' => $funnel['abandoned'],
            'never_answered' => $funnel['never_answered'],
            'open' => $funnel['open'],
            'steps' => $steps,
            'abandoned_by_code' => $byCode,
        ];
    }

    /**
     * The request log in the period, by kind, status class and blocked. Never the IP or a path.
     *
     * @return array<string, mixed>
     */
    private function requestLog(ReportPeriod $period): array
    {
        $assets = implode(', ', array_fill(0, count(self::WIDGET_ASSET_PATHS), '?'));
        $statement = $this->db->pdo()->prepare(
            "SELECT CASE WHEN path IN ({$assets}) THEN 'widget_asset' WHEN path = ? THEN 'favicon' ELSE 'api' END AS kind,"
            . ' status / 100 AS class, blocked, COUNT(*) AS count'
            . ' FROM requests WHERE ts >= ? AND ts < ? GROUP BY 1, 2, 3',
        );
        $statement->execute([...self::WIDGET_ASSET_PATHS, self::FAVICON_PATH, $period->startUnix, $period->endUnix]);

        $template = ['total' => 0, '2xx' => 0, '3xx' => 0, '4xx' => 0, '5xx' => 0, 'other' => 0, 'blocked' => 0];
        $kinds = ['api' => $template, 'widget_asset' => $template, 'favicon' => $template];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $kind = (string) $row['kind'];
            $count = (int) $row['count'];
            $class = (int) $row['class'];
            $kinds[$kind]['total'] += $count;
            $kinds[$kind][$class >= 2 && $class <= 5 ? $class . 'xx' : 'other'] += $count;
            if ((int) $row['blocked'] === 1) {
                $kinds[$kind]['blocked'] += $count;
            }
        }

        $rows = [];
        foreach ($kinds as $kind => $counts) {
            $rows[] = ['kind' => $kind] + $counts;
        }

        return [
            'total' => array_sum(array_column($rows, 'total')),
            'blocked' => array_sum(array_column($rows, 'blocked')),
            'kinds' => $rows,
        ];
    }

    /**
     * @param array<string, int> $day
     * @param array<string, int> $summary
     * @param array<string, array<string, int>> $currencies
     * @param array<string, array<string, int>> $countries
     */
    private static function conversion(array &$day, array &$summary, array &$currencies, array &$countries, string $via, ?string $currency, ?string $country, int $cents): void
    {
        $day['conversions']++;
        $day['amount_usd_cents'] += $cents;
        $summary['conversions']++;
        $summary['conversions_' . $via]++;
        $summary['amount_usd_cents'] += $cents;
        if ($currency !== null) {
            $currencies[$currency] ??= self::dimensionTemplate();
            $currencies[$currency]['conversions']++;
            $currencies[$currency]['conversions_' . $via]++;
            $currencies[$currency]['amount_usd_cents'] += $cents;
        }
        if ($country !== null) {
            $countries[$country] ??= self::dimensionTemplate();
            $countries[$country]['conversions']++;
            $countries[$country]['conversions_' . $via]++;
            $countries[$country]['amount_usd_cents'] += $cents;
        }
    }

    /**
     * @param array<string, array<string, int>> $dimension
     */
    private static function requested(array &$dimension, string $key): void
    {
        $dimension[$key] ??= self::dimensionTemplate();
        $dimension[$key]['requested']++;
    }

    /**
     * @param array<string, array<string, int>> $errors
     */
    private static function countError(array &$errors, string $code, string $kind): void
    {
        $errors[$code] ??= ['http' => 0, 'bot' => 0, 'total' => 0];
        $errors[$code][$kind]++;
        $errors[$code]['total']++;
    }

    /**
     * @param array<string, array<string, int>> $byKey
     * @return list<array<string, int|string>>
     */
    private static function rows(string $name, array $byKey): array
    {
        $rows = [];
        foreach ($byKey as $key => $counts) {
            $rows[] = [$name => (string) $key] + $counts;
        }

        return $rows;
    }

    /**
     * @return array<string, int>
     */
    private static function dimensionTemplate(): array
    {
        return ['requested' => 0, 'conversions' => 0, 'conversions_api' => 0, 'conversions_bot' => 0, 'amount_usd_cents' => 0];
    }

    /**
     * @return array<string, int>
     */
    private static function endpointTemplate(): array
    {
        return ['lookups' => 0, 'hit' => 0, 'miss' => 0, 'stale' => 0, 'blocked' => 0, 'unknown' => 0, 'calls' => 0, 'no_key' => 0, 'failed_calls' => 0];
    }

    /**
     * @return array<string, mixed>
     */
    private static function channelTemplate(): array
    {
        return [
            'started' => 0, 'completed' => 0, 'abandoned' => 0, 'never_answered' => 0, 'open' => 0,
            'answered' => [], 'retries' => [], 'abandoned_at' => [], 'abandoned_by_code' => [],
        ];
    }

    /**
     * A safe label from an event column or meta value: null for null or a non-string, otherwise
     * valid UTF-8 with control characters replaced, at most MAX_LABEL characters.
     */
    public static function label(mixed $value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value) || $value === '') {
            return null;
        }
        $text = (string) preg_replace('/\p{Cc}/u', "\u{FFFD}", mb_scrub($value, 'UTF-8'));

        return mb_substr($text, 0, self::MAX_LABEL, 'UTF-8');
    }

    private static function cents(mixed $value): int
    {
        if (is_string($value) && preg_match('/^(0|[1-9]\d{0,17})$/D', $value) === 1) {
            return (int) $value;
        }

        return is_int($value) && $value > 0 ? $value : 0;
    }

    /**
     * @return array<string, mixed>
     */
    private static function meta(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        try {
            $meta = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($meta) && !array_is_list($meta) ? $meta : [];
    }
}
