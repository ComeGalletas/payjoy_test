<?php

declare(strict_types=1);

namespace FxInstallments\Metrics;

use Closure;
use FxInstallments\Convert\CurrencyCountryMap;
use FxInstallments\Db\Connection;
use FxInstallments\Flow\FlowDefinition;
use FxInstallments\Flow\SessionStore;
use FxInstallments\Http\IpBlock;
use FxInstallments\Rates\ExchangeRateClient;
use JsonException;
use PDO;
use Throwable;

/**
 * The numbers behind `/reports` and `bin/report.php` (docs/PLAN.md §1 "measurable", §6; P1-08),
 * read from `events`, `sessions` and `requests` over a ReportPeriod. Plain integers and fixed
 * words only; ReportFormatter turns them into JSON, CSV or a table. Portable SQL (§8): no date
 * or JSON functions, `meta` is decoded here.
 *
 * **Never shown:** an IP (`requests.ip` and `ip_blocks` are never selected), the API key, or
 * any upstream text. Every label taken from an event is checked against an allow-list, and
 * anything else is counted under OTHER (round 2), so a hand-edited or imported row can't put
 * free text into any format:
 * - endpoint: endpoints() (ExchangeRateClient::ENDPOINTS); error codes and `refresh_error`:
 *   ErrorCodes; `block_source`: `auto`, `manual` (`none` when null); block `reason`:
 *   BLOCK_REASONS; channel: Metrics::CHANNELS;
 * - currency: `^[A-Z]{3}$`; country: a CurrencyCountryMap name; step: the flow loader's
 *   FlowDefinition::ID_PATTERN (round 3).
 * The `other` note counts every folded value, those only the funnel sees included.
 *
 * **Amounts:** a conversion's cents count only when they are an integer from 1 to
 * `amount.max` × 100; any other value (null, negative, REAL, text, too large) counts the
 * conversion with no USD and adds to `summary.unreadable_amounts` and a note. So the sums are
 * integers that can't overflow in practice (round 2).
 *
 * **Before counting**, expired sessions are closed (`App::sessionSweeper()`, ADR 0021), so the
 * funnel doesn't show them as still open. One sweep closes at most SessionStore::SWEEP_LIMIT,
 * so it is repeated while a pass closes that many, at most `maxSweepPasses` times (round 2); if
 * the bound is reached, or a sweep fails, the report still runs and says so in a note.
 *
 * Sections, all for events with `from <= ts < to` unless said otherwise:
 * - **summary** and **days** (split on `reports.timezone`): sessions started, completed and
 *   abandoned (event counts), conversions (an `api.request` with status ok plus a
 *   `bot.session_completed`) and their USD total as an integer sum of `amount_usd_cents`,
 *   `/convert` requests and errors, error responses, upstream calls.
 * - **currencies:** per currency, `requested` (an `api.request` with a currency, or a bot
 *   currency step answered) and conversions, split api and bot, with the cents total.
 *   **countries:** per typed country (bot only), `requested` and conversions.
 * - **funnel** per channel: the sessions that *started* in the period (`bot.session_start`),
 *   followed to their end even after the period. Each session has one final position: the step
 *   where it completed (`result`), was abandoned, is still open (its `sessions.step`), or, for a
 *   session that never sent an answer (an opened widget, closed as expired), `never_answered`
 *   at `amount`: its own outcome, not counted in `abandoned`; it is still a
 *   `bot.session_abandoned` event (ADR 0022). Per step, `reached` counts the sessions whose
 *   final position is this step or a later one, `passed` those that got past it (the next
 *   step's `reached`; owner's name, round 3), and `abandoned`, `never_answered` and `open` those
 *   whose final position is this step; so `reached = passed + abandoned + never_answered + open`
 *   on every row (for `result`, `completed` in place of `passed`), and started = completed +
 *   abandoned + never_answered + open. `retries` counts every failed answer at a step, a
 *   conversion that sent the user back (`via: convert`) included.
 *   Round 3: a session that ended at, or waits on, a step outside the flow's order (a renamed
 *   step, `other`, a null step) is placed at the known step after the furthest one it
 *   answered, with a note, so `amount.reached` always equals `started`; that step name keeps a
 *   row for its retries. A session whose `sessions` row is closed but whose end event is
 *   missing (the write failed, INV-07) is counted by the row's status (completed, or
 *   abandoned at the row's step with code `other`), with a note.
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
 * period start on, through the `events_ts` index), one read of the cohort's `sessions` rows, and
 * one grouped read of `requests`.
 */
final class ReportQuery
{
    /** The label every value outside its allow-list is counted under. */
    public const OTHER = 'other';

    public const CHANNELS = Metrics::CHANNELS;

    /** The flow's steps in order (docs/PLAN.md §9); other step names follow, sorted. */
    public const STEPS = ['amount', 'currency', 'installments', 'result'];

    public const RESULTS = ['hit', 'miss', 'stale', 'blocked'];

    /** `ip.block` reasons: automatic thresholds (IpBlock), and manual `given` or `default`. */
    public const BLOCK_REASONS = [IpBlock::REASON_RATE_LIMIT_HITS, IpBlock::REASON_ERROR_RESPONSES, 'given', 'default'];

    public const BLOCK_SOURCES = [IpBlock::AUTO, IpBlock::MANUAL];

    /** The widget's file routes (WidgetEndpoint), for the request-log split. */
    public const WIDGET_ASSET_PATHS = ['/bot/widget', '/bot/widget.js', '/bot/widget.css', '/bot/widget.svg'];

    public const FAVICON_PATH = '/favicon.ico';

    /** The most sweep passes before counting (round 2). */
    public const MAX_SWEEP_PASSES = 20;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * The upstream endpoints the client can call (ExchangeRateClient::ENDPOINTS), in its order.
     *
     * @return list<string>
     */
    public static function endpoints(): array
    {
        return array_keys(ExchangeRateClient::ENDPOINTS);
    }

    /**
     * @param (Closure(): int)|null $clock UTC epoch seconds; defaults to time()
     * @param (Closure(): int)|null $sweep one sweep pass, returning how many sessions it
     *        closed (App::sessionSweeper()->sweep())
     * @param int $requestsRetentionDays `requests.retention_days`, for the request-log note
     * @param int $maxAmountCents `amount.max` × 100: the largest readable conversion amount
     * @param int $sweepBatch the most one pass closes (SessionStore::SWEEP_LIMIT)
     */
    public function __construct(
        private readonly Connection $db,
        ?Closure $clock = null,
        private readonly ?Closure $sweep = null,
        private readonly int $requestsRetentionDays = 30,
        private readonly int $maxAmountCents = 100_000_000,
        private readonly int $sweepBatch = SessionStore::SWEEP_LIMIT,
        private readonly int $maxSweepPasses = self::MAX_SWEEP_PASSES,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @return array<string, mixed> see the class comment; ReportFormatter documents the shape
     */
    public function run(ReportPeriod $period): array
    {
        $notes = $this->sweepFirst();
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
        $funnel = $this->funnel($period);
        $report['funnel'] = $funnel['rows'];
        $report['requests'] = $this->requestLog($period);
        $otherLabels = $events['other_labels'] + $funnel['other_labels'];

        $notes[] = 'The funnel counts the sessions that started in the period, followed to their end.';
        $notes[] = 'Upstream calls are /latest/USD refreshes (miss and stale lookups, except reason no_key) and /quota calls from the admin health view; /codes is never called in Phase 1.';
        $notes[] = 'Each error response is counted once (its error event); a /convert error\'s api.request event is not counted again.';
        if ($events['missing_rate'] > 0) {
            $notes[] = sprintf(
                'Converted amounts are not recomputed; %d conversion(s) in this period have no stored rate_scaled (recorded before P1-08).',
                $events['missing_rate'],
            );
        }
        $unreadable = $report['summary']['unreadable_amounts'];
        if ($unreadable > 0) {
            $notes[] = sprintf(
                '%d conversion(s) have an amount that is not whole cents from 0.01 to amount.max; they are counted with no USD.',
                $unreadable,
            );
        }
        if ($otherLabels > 0) {
            $notes[] = sprintf(
                '%d value(s) outside the known codes, currencies, countries, steps or words are counted as "other".',
                $otherLabels,
            );
        }
        if ($funnel['outside_flow'] > 0) {
            $notes[] = sprintf(
                '%d session(s) ended at, or wait on, a step outside the flow\'s order; the funnel places each at the known step after its last answer.',
                $funnel['outside_flow'],
            );
        }
        if ($funnel['closed_without_end'] > 0) {
            $notes[] = sprintf(
                '%d session(s) were closed without an end event (its write failed); the funnel counts them by their sessions row.',
                $funnel['closed_without_end'],
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
     * Sweeps expired sessions, pass after pass while a pass closes a full batch, at most
     * maxSweepPasses times. Returns the notes to add.
     *
     * @return list<string>
     */
    private function sweepFirst(): array
    {
        if ($this->sweep === null) {
            return [];
        }
        $passes = 0;
        try {
            do {
                $closed = ($this->sweep)();
                $passes++;
            } while ($closed >= $this->sweepBatch && $passes < $this->maxSweepPasses);
        } catch (Throwable) {
            return ['Expired sessions could not be closed before counting, so some sessions may still show as open.'];
        }
        if ($closed >= $this->sweepBatch) {
            return [sprintf(
                '%d expired sessions were closed before counting; more may remain and show as open.',
                $passes * $this->sweepBatch,
            )];
        }

        return [];
    }

    /**
     * One pass over the period's events: summary, days, currencies, countries, errors, upstream,
     * ip_blocks.
     *
     * @return array{sections: array<string, mixed>, missing_rate: int, other_labels: int}
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
        $summary = $dayTemplate + ['conversions_api' => 0, 'conversions_bot' => 0, 'unreadable_amounts' => 0, 'api_errors' => 0, 'bot_errors' => 0];
        $currencies = [];
        $countries = [];
        $errors = [];
        $upstream = [];
        foreach (self::endpoints() as $endpoint) {
            $upstream[$endpoint] = self::endpointTemplate();
        }
        $failures = [];
        $breakerResets = 0;
        $blocks = [];
        $unblocks = [];
        $missingRate = 0;
        $others = 0;

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
            $code = self::code($row['error_code'], $others);
            $currency = self::currency($row['currency'], $others);
            $country = self::country($row['country'], $others);
            $meta = self::meta($row['meta']);

            switch ($type) {
                case 'bot.session_start':
                    $days[$day]['sessions_started']++;
                    break;

                case 'bot.session_completed':
                    $days[$day]['sessions_completed']++;
                    $this->conversion($days[$day], $summary, $currencies, $countries, 'bot', $currency, $country, $row['amount_usd_cents']);
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
                        $this->conversion($days[$day], $summary, $currencies, $countries, 'api', $currency, null, $row['amount_usd_cents']);
                        if (!isset($meta['rate_scaled'])) {
                            $missingRate++;
                        }
                    } else {
                        $summary['api_errors']++;
                    }
                    break;

                case 'error':
                    $days[$day]['error_responses']++;
                    if ($code === null) {
                        $others++; // an error event without a code is counted as `other` too
                    }
                    self::countError($errors, $code ?? self::OTHER, 'http');
                    break;

                case 'upstream.call':
                    $endpoint = array_key_exists('endpoint', $meta) ? self::word($meta['endpoint'], self::endpoints(), $others) : 'latest';
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
                        ? self::code($meta['refresh_error'] ?? null, $others)
                        : (array_key_exists('reason', $meta) ? ($code ?? self::OTHER) : null);
                    if ($failed !== null) {
                        $upstream[$endpoint]['failed_calls']++;
                        $failures[$endpoint . "\0" . $failed] = ($failures[$endpoint . "\0" . $failed] ?? 0) + 1;
                    }
                    break;

                case 'upstream.breaker_reset':
                    $breakerResets++;
                    break;

                case 'ip.block':
                    $reason = $meta['reason'] ?? null;
                    $key = self::blockSource($meta['block_source'] ?? null, $others) . "\0"
                        . ($reason === null ? 'none' : self::word($reason, self::BLOCK_REASONS, $others));
                    $blocks[$key] ??= ['count' => 0, 'extended' => 0];
                    $blocks[$key]['count']++;
                    if (($meta['extended'] ?? false) === true) {
                        $blocks[$key]['extended']++;
                    }
                    break;

                case 'ip.unblock':
                    $key = self::blockSource($meta['block_source'] ?? null, $others);
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
            [$endpoint, $failedCode] = explode("\0", (string) $key, 2);
            $failureRows[] = ['endpoint' => $endpoint, 'code' => $failedCode, 'count' => $count];
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
            'other_labels' => $others,
        ];
    }

    /**
     * The funnel per channel, for the sessions that started in the period, with how many labels
     * it alone folded into `other` (steps, channels, `sessions.step`, and the code of an end
     * event after the period, which the event pass doesn't see), how many sessions ended outside
     * the flow's order, and how many were closed without an end event.
     *
     * @return array{rows: list<array<string, mixed>>, other_labels: int, outside_flow: int, closed_without_end: int}
     */
    private function funnel(ReportPeriod $period): array
    {
        $cohort = "SELECT session_id FROM events WHERE event_type = 'bot.session_start' AND ts >= ? AND ts < ?";
        $statement = $this->db->pdo()->prepare(
            "SELECT ts, session_id, channel, event_type, step, error_code, status FROM events WHERE source = 'bot' AND ts >= ?"
            . " AND session_id IN ({$cohort}) ORDER BY id",
        );
        $statement->execute([$period->startUnix, $period->startUnix, $period->endUnix]);

        $others = 0;
        $counted = 0; // codes the event pass already counted
        /** @var array<string, array{channel: ?string, answered: array<string, true>, retries: array<string, int>, steps: int, end: ?array{string, string, ?string}}> $sessions */
        $sessions = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $id = (string) $row['session_id'];
            $sessions[$id] ??= ['channel' => null, 'answered' => [], 'retries' => [], 'steps' => 0, 'end' => null];
            $step = self::step($row['step'], $others);
            switch ($row['event_type']) {
                case 'bot.session_start':
                    $sessions[$id]['channel'] ??= self::word($row['channel'], self::CHANNELS, $others);
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
                    $sessions[$id]['end'] ??= ['completed', 'result', null];
                    break;
                case 'bot.session_abandoned':
                    if ((int) $row['ts'] >= $period->endUnix) {
                        $code = self::code($row['error_code'], $others);
                    } else {
                        $code = self::code($row['error_code'], $counted);
                    }
                    $sessions[$id]['end'] ??= ['abandoned', $step, $code ?? self::OTHER];
                    break;
            }
        }
        $statement->closeCursor();

        // Each cohort session's row: where an open one waits, and the status of one whose end
        // event was never written (INV-07 swallowed the write).
        $rows = [];
        $read = $this->db->pdo()->prepare("SELECT session_id, step, status FROM sessions WHERE session_id IN ({$cohort})");
        $read->execute([$period->startUnix, $period->endUnix]);
        foreach ($read->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[(string) $row['session_id']] = [(string) $row['status'], self::step($row['step'], $others)];
        }

        $outsideFlow = 0;
        $closedWithoutEnd = 0;
        $channels = [];
        foreach (self::CHANNELS as $channel) {
            $channels[$channel] = self::channelTemplate();
        }
        foreach ($sessions as $id => $session) {
            if ($session['channel'] === null) {
                continue; // its start event was outside the cohort query (not possible; defensive)
            }
            $funnel = &$channels[$session['channel']];
            $funnel ??= self::channelTemplate();
            $funnel['started']++;
            foreach ($session['retries'] as $step => $count) {
                $funnel['retries'][(string) $step] = ($funnel['retries'][(string) $step] ?? 0) + $count;
            }
            $end = $session['end'];
            $row = $rows[(string) $id] ?? null;
            if ($end === null && $row !== null && $row[0] !== 'open') {
                // Closed, but its end event is missing: counted by the row's status.
                $closedWithoutEnd++;
                $end = $row[0] === 'completed' ? ['completed', 'result', null] : ['abandoned', $row[1], self::OTHER];
            }
            if ($end === null) {
                $at = $row === null ? self::nextStep($session['answered']) : self::knownStep($row[1], $session['answered'], $outsideFlow);
                $funnel['open']++;
                $funnel['open_at'][$at] = ($funnel['open_at'][$at] ?? 0) + 1;
            } elseif ($end[0] === 'completed') {
                $funnel['completed']++;
            } elseif ($session['steps'] === 0) {
                $funnel['never_answered']++;
            } else {
                [, $step, $code] = $end;
                $at = self::knownStep($step, $session['answered'], $outsideFlow);
                $funnel['abandoned']++;
                $funnel['abandoned_at'][$at] = ($funnel['abandoned_at'][$at] ?? 0) + 1;
                $funnel['abandoned_by_code'][(string) $code] = ($funnel['abandoned_by_code'][(string) $code] ?? 0) + 1;
            }
            unset($funnel);
        }

        $out = [];
        foreach ($channels as $channel => $funnel) {
            $out[] = self::funnelRow((string) $channel, $funnel);
        }

        return ['rows' => $out, 'other_labels' => $others, 'outside_flow' => $outsideFlow, 'closed_without_end' => $closedWithoutEnd];
    }

    /**
     * $step when it is one of the flow's steps; otherwise (a renamed or unknown step, `other`,
     * `none`) the known step after the furthest one the session answered, counted in
     * $outsideFlow (round 3: so `amount.reached` always equals the sessions started).
     *
     * @param array<string, true> $answered
     */
    private static function knownStep(string $step, array $answered, int &$outsideFlow): string
    {
        if (in_array($step, self::STEPS, true)) {
            return $step;
        }
        $outsideFlow++;

        return self::nextStep($answered);
    }

    /**
     * The known step after the furthest known step the session answered, or the first; never
     * `result` (a session there either completed or was abandoned there by an upstream error).
     * Used for an open session with no `sessions` row and for a session that ended outside the
     * flow's order.
     *
     * @param array<string, true> $answered
     */
    private static function nextStep(array $answered): string
    {
        $next = 0;
        foreach (self::STEPS as $index => $step) {
            if (isset($answered[$step])) {
                $next = $index + 1;
            }
        }

        return self::STEPS[min($next, count(self::STEPS) - 2)];
    }

    /**
     * @param array<string, mixed> $funnel
     * @return array<string, mixed>
     */
    private static function funnelRow(string $channel, array $funnel): array
    {
        // Sessions whose final position is each step.
        $ending = [];
        foreach (self::STEPS as $index => $step) {
            $ending[$index] = ($funnel['abandoned_at'][$step] ?? 0) + ($funnel['open_at'][$step] ?? 0)
                + ($index === 0 ? $funnel['never_answered'] : 0)
                + ($step === 'result' ? $funnel['completed'] : 0);
        }
        $atOrBeyond = static fn (int $index): int => array_sum(array_slice($ending, $index));

        $steps = [];
        foreach (self::STEPS as $index => $step) {
            $row = ['step' => $step, 'reached' => $atOrBeyond($index)];
            if ($step === 'result') {
                $row['completed'] = $funnel['completed'];
            } else {
                $row['passed'] = $atOrBeyond($index + 1);
                $row['retries'] = $funnel['retries'][$step] ?? 0;
            }
            $row['abandoned'] = $funnel['abandoned_at'][$step] ?? 0;
            $row['never_answered'] = $index === 0 ? $funnel['never_answered'] : 0;
            $row['open'] = $funnel['open_at'][$step] ?? 0;
            $steps[] = $row;
        }

        // Steps outside the flow's order keep a row for their retries; the sessions that ended
        // there are placed at a known step (knownStep()), so these rows reach nobody.
        $others = array_map('strval', array_keys($funnel['retries'] + $funnel['abandoned_at'] + $funnel['open_at']));
        $others = array_values(array_diff(array_unique($others), self::STEPS));
        sort($others, SORT_STRING);
        foreach ($others as $step) {
            $abandoned = $funnel['abandoned_at'][$step] ?? 0;
            $open = $funnel['open_at'][$step] ?? 0;
            $steps[] = [
                'step' => $step,
                'reached' => $abandoned + $open,
                'passed' => 0,
                'retries' => $funnel['retries'][$step] ?? 0,
                'abandoned' => $abandoned,
                'never_answered' => 0,
                'open' => $open,
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
            $rows[] = ['kind' => (string) $kind] + $counts;
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
    private function conversion(array &$day, array &$summary, array &$currencies, array &$countries, string $via, ?string $currency, ?string $country, mixed $rawCents): void
    {
        $cents = $this->readableCents($rawCents);
        if ($cents === null) {
            $summary['unreadable_amounts']++;
            $cents = 0;
        }
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
     * Whole cents from 1 to `amount.max` × 100, or null for anything else.
     */
    private function readableCents(mixed $value): ?int
    {
        if (is_string($value) && preg_match('/^[1-9]\d{0,17}$/D', $value) === 1) {
            $value = (int) $value; // drivers that return integers as text
        }

        return is_int($value) && $value >= 1 && $value <= $this->maxAmountCents ? $value : null;
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
     * @param array<array-key, array<string, int>> $byKey
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
            'retries' => [], 'abandoned_at' => [], 'open_at' => [], 'abandoned_by_code' => [],
        ];
    }

    /**
     * $value when it is one of $allowed, otherwise OTHER (and $others is counted).
     *
     * @param list<string> $allowed
     */
    public static function word(mixed $value, array $allowed, int &$others = 0): string
    {
        if (is_string($value) && in_array($value, $allowed, true)) {
            return $value;
        }
        $others++;

        return self::OTHER;
    }

    /**
     * An ErrorCodes code, null for null, otherwise OTHER.
     */
    public static function code(mixed $value, int &$others = 0): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_string($value) && ErrorCodes::isKnown($value) ? $value : self::word(null, [], $others);
    }

    /**
     * A 3-letter uppercase code, null for null, otherwise OTHER.
     */
    public static function currency(mixed $value, int &$others = 0): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_string($value) && preg_match('/^[A-Z]{3}$/D', $value) === 1 ? $value : self::word(null, [], $others);
    }

    /**
     * A CurrencyCountryMap country name, null for null, otherwise OTHER.
     */
    public static function country(mixed $value, int &$others = 0): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_string($value) && CurrencyCountryMap::currencyOf($value) !== null ? $value : self::word(null, [], $others);
    }

    /**
     * A step id as the flow loader accepts it (FlowDefinition::ID_PATTERN: a letter, then up to
     * 39 letters, digits or `_`), `none` for null, otherwise OTHER.
     */
    public static function step(mixed $value, int &$others = 0): string
    {
        if ($value === null) {
            return 'none';
        }

        return is_string($value) && preg_match(FlowDefinition::ID_PATTERN, $value) === 1 ? $value : self::word(null, [], $others);
    }

    /**
     * `auto` or `manual`, `none` for null (an unblock with no active block), otherwise OTHER.
     */
    private static function blockSource(mixed $value, int &$others): string
    {
        return $value === null ? 'none' : self::word($value, self::BLOCK_SOURCES, $others);
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
