<?php

declare(strict_types=1);

/*
 * Demo data for the reports (docs/PLAN.md §4, P1-09):
 *
 *     php api/db/seed_demo.php
 *
 * Writes a fixed set of bot sessions, `/convert` requests and request-log rows, spread over the
 * default report period (the last 7 days in `reports.timezone`), so `php api/bin/report.php` and
 * `GET /reports` have something to show right after an install. It writes only `events`,
 * `sessions` and `requests`, all in one transaction:
 *
 * - **No rates** (owner decision, 2026-10-22): never `rate_cache`, `app_state` (breaker, quota),
 *   `rate_limits` or `ip_blocks`. The first real conversion still makes the one real
 *   `/latest/USD` call. Demo conversions carry a stored `rate_scaled` and `rate_as_of` in their
 *   event meta, like real ones (ADR 0011 second amendment), and their rate lookups are `hit`
 *   events, so the demo adds no upstream call to the report's `latest` row.
 * - **Real labels only:** the flow's step names (checked against `flows/default.yaml`), codes
 *   from ErrorCodes, CurrencyCountryMap country names and 3-letter currency codes, so a report
 *   on demo data shows no `other` and no "no stored rate" note. Amounts are integer cents; no
 *   value is a float (INV-09), and nothing here rounds or formats money (INV-01).
 * - **Documentation IPs only:** every request-log row comes from 192.0.2.0/24, 198.51.100.0/24
 *   or 203.0.113.0/24 (RFC 5737). No event holds an IP.
 * - **Deterministic:** the same rows every time, at fixed local times on each of the six days
 *   before today, plus two sessions still open, started minutes before now (they stay open for
 *   `bot.session_ttl_minutes`, then the next sweep closes them as expired). Only the times move
 *   with "now"; there is no randomness. The local times hold on a daylight-saving change day
 *   in `reports.timezone` too (L-04).
 * - **Follows the config (L-04):** the demo block lasts `block.duration_minutes` (its unblock
 *   comes 25 minutes in, or 30 seconds before the block would end if that is sooner, and only
 *   requests before the unblock are blocked). A `bot.session_ttl_minutes` so long that the
 *   sweep closing a session declared to expire would come after now is refused (exit 1, a
 *   fixed text with the longest TTL that works today; owner decision 2026-10-22), so no row is
 *   ever written in the future.
 * - **Telling demo rows apart:** every event this script writes has `"demo": true` in its meta.
 *   The expiry events a later sweep writes for the two open sessions come from the app and
 *   don't carry it; find them by their fixed session ids (sha256 of
 *   `fx-installments demo session 13` and `… 14`, first 32 hex digits). `sessions` rows carry no
 *   marker (their ids are fixed the same way), and demo `requests` rows use the documentation
 *   addresses.
 *
 * Run it after `php api/bin/migrate.php`, on a throwaway database (a separate DB_DSN), never on
 * the one real traffic will use: there is no undo, because `events` are never pruned
 * (docs/INSTALL_LOCAL.md, section 6). It refuses a database that already has events or
 * sessions, so it never mixes demo rows into real ones or runs twice.
 *
 * Exit codes: 0 done; 1 failed (no database file, a schema behind the code or corrupt, a
 * database that already has events or sessions, a TTL too long for the demo, a failed write;
 * stderr names the exception class only, or gives a fixed text); 2 usage. `--help` prints the
 * usage and exits 0. Like bin/report.php, a missing SQLite file is refused and never created.
 *
 * A test may `require_once` this file for DemoWriter and the SEED_* lists: the script part runs
 * only when this file is the one PHP was started with.
 */

use FxInstallments\App;
use FxInstallments\Bootstrap;
use FxInstallments\Config\ConfigRepository;
use FxInstallments\Convert\InstallmentCalculator;
use FxInstallments\Db\Connection;
use FxInstallments\Db\Database;
use FxInstallments\Db\DatabaseFileMissing;
use FxInstallments\Flow\Collected;
use FxInstallments\Flow\FlowDefinition;
use FxInstallments\Http\IpBlock;
use FxInstallments\Metrics\ErrorCodes;
use FxInstallments\Metrics\Metrics;
use FxInstallments\Metrics\ReportPeriod;
use FxInstallments\Metrics\RequestLog;

require_once dirname(__DIR__) . '/vendor/autoload.php';

const SEED_USAGE = "usage: php api/db/seed_demo.php\n";

/** Demo rates as stored integers (rate × 10^10, ADR 0017), from the suite's upstream fixture. */
const SEED_RATES = [
    'BRL' => 52_291_000_000,         // 5.2291
    'COP' => 31_537_735_990_000,     // 3153.773599
    'EUR' => 8_594_000_000,          // 0.8594
    'JPY' => 1_514_680_000_000,      // 151.468
];

/** Seconds between two messages of one demo session. */
const SEED_MESSAGE_GAP = 40;

/** Seconds after its expiry at which a later sweep closes a demo session declared to expire. */
const SEED_SWEEP_DELAY = 75;

/** The demo unblock: this many minutes after the first 429's minute, unless the block ends first. */
const SEED_UNBLOCK_MINUTES = 25;

/**
 * The bot sessions: channel, days before today (0: relative to now), the local start time
 * (`HH:MM`) or, on day 0, minutes before now, the client IP (static sessions only; the CLI
 * sends no HTTP request), the answers in order, and how a session still open after its
 * answers ends (`expire`: closed later by the sweep; `open`: still waiting).
 *
 * Answers: `['ok', value]` (cents, `[code, country|null]` or `[count, default?]`) or
 * `['bad', reason]`; a session completes after the installments answer and is abandoned with
 * BOT_MAX_RETRIES once one step has `bot.max_retries` bad answers.
 */
const SEED_SESSIONS = [
    ['static', 6, '10:12', '192.0.2.10', [['ok', 20_000], ['ok', ['BRL', 'Brazil']], ['ok', [3, false]]], null],
    ['cli', 6, '15:40', null, [['ok', 15_000], ['ok', ['COP', null]], ['ok', [1, true]]], null],
    ['static', 5, '09:05', '192.0.2.11', [['bad', 'unclear'], ['bad', 'unclear'], ['bad', 'unclear']], null],
    ['static', 5, '18:30', '192.0.2.12', [], 'expire'],
    ['static', 4, '11:20', '192.0.2.13', [['ok', 100_000], ['bad', 'ambiguous'], ['ok', ['COP', 'Colombia']], ['ok', [12, false]]], null],
    ['cli', 4, '16:02', null, [['ok', 7_550]], 'expire'],
    ['static', 3, '08:47', '192.0.2.14', [['ok', 8_999], ['ok', ['EUR', null]], ['bad', 'out_of_range'], ['ok', [6, false]]], null],
    ['static', 3, '20:15', '192.0.2.15', [['ok', 50_000], ['ok', ['MXN', 'Mexico']], ['bad', 'out_of_range'], ['bad', 'format'], ['bad', 'out_of_range']], null],
    ['cli', 2, '10:30', null, [['ok', 250_000], ['ok', ['BRL', null]], ['ok', [10, false]]], null],
    ['cli', 2, '17:55', null, [['bad', 'over_max'], ['bad', 'over_max'], ['bad', 'over_max']], null],
    ['static', 1, '12:00', '192.0.2.16', [['ok', 4_990], ['ok', ['JPY', 'Japan']], ['ok', [1, true]]], null],
    ['static', 1, '19:42', '192.0.2.17', [['ok', 30_000], ['ok', ['BRL', null]]], 'expire'],
    ['static', 0, 10, '192.0.2.18', [['ok', 12_000]], 'open'],
    ['cli', 0, 3, null, [], 'open'],
];

/**
 * `GET /convert` requests: days before today, local time, client IP, cents (null when the
 * amount was refused), currency (null when refused or not reached), installments, and the
 * error code and reason for a refused request.
 */
const SEED_CONVERSIONS = [
    [6, '10:40', '198.51.100.10', 20_000, 'BRL', 3, null, null],
    [4, '09:15', '198.51.100.11', null, null, null, ErrorCodes::VALIDATION_AMOUNT, 'format'],
    [4, '09:16', '198.51.100.11', 100_000, 'COP', 12, null, null],
    [3, '14:03', '198.51.100.12', 5_000, 'EUR', 1, null, null],
    [3, '14:05', '198.51.100.12', 3_000, null, null, ErrorCodes::VALIDATION_CURRENCY, 'not_allowed'],
    [2, '13:20', '198.51.100.13', 75_000, 'EUR', 6, null, null],
    [1, '09:58', '198.51.100.14', 9_999, 'BRL', 4, null, null],
];

/** Other error responses (each an `error` event and a request-log row): days, time, IP, method, path, code. */
const SEED_ERRORS = [
    [5, '03:12', '203.0.113.40', 'GET', '/robots.txt', ErrorCodes::NOT_FOUND],
    [5, '03:13', '203.0.113.41', 'GET', '/robots.txt', ErrorCodes::NOT_FOUND],
    [5, '18:29', '192.0.2.12', 'GET', '/apple-touch-icon.png', ErrorCodes::NOT_FOUND],
    [1, '08:00', '203.0.113.50', 'GET', '/reports', ErrorCodes::ADMIN_UNAUTHORIZED],
];

/** One client rate-limited in five minutes, blocked automatically, then unblocked by hand. */
const SEED_BLOCKED_IP = '198.51.100.23';

// The script part: only when PHP was started with this file, not when a test requires it.
if (get_included_files()[0] === __FILE__) {
    $apiRoot = dirname(__DIR__);
    $usage = static function (): never {
        fwrite(STDERR, SEED_USAGE);
        exit(2);
    };

    $args = array_slice($argv, 1);
    if ($args === ['--help'] || $args === ['-h']) {
        fwrite(STDOUT, SEED_USAGE);
        exit(0);
    }
    if ($args !== []) {
        $usage();
    }

    try {
        Bootstrap::init($apiRoot);
        $config = new ConfigRepository();

        // Refuses a missing SQLite file (never creating it) and a schema behind the code.
        $pdo = Database::fromEnvironment($apiRoot);

        foreach (['events', 'sessions'] as $table) {
            if ((int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn() > 0) {
                fwrite(STDERR, "seed_demo: failed: the database already has {$table}; demo data goes only into a new database\n");
                exit(1);
            }
        }

        $writer = DemoWriter::fromConfig(Connection::wrap($pdo), $config, App::DEFAULT_FLOW, time());

        $pdo->beginTransaction();
        try {
            $counts = $writer->write();
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        fwrite(STDOUT, sprintf(
            "seed_demo: wrote %d sessions, %d events and %d request-log rows, %s to %s (%s)\n",
            $counts['sessions'],
            $counts['events'],
            $counts['requests'],
            $writer->period->from,
            $writer->period->to,
            $writer->period->timezone,
        ));
        exit(0);
    } catch (DatabaseFileMissing | SeedDemoRefused $e) {
        // Fixed texts, without the path.
        fwrite(STDERR, 'seed_demo: failed: ' . $e->getMessage() . "\n");
        exit(1);
    } catch (Throwable $e) {
        // The class only: a message could name the database path or other local details.
        fwrite(STDERR, sprintf("seed_demo: failed: %s\n", $e::class));
        exit(1);
    }
}

/**
 * The config can't hold the demo story; the message is a fixed text with config numbers only.
 */
final class SeedDemoRefused extends RuntimeException
{
}

/**
 * Builds the demo rows in time order and writes them through Metrics (events), RequestLog
 * (requests) and the `sessions` columns SessionStore uses.
 */
final class DemoWriter
{
    /** @var list<array{int, int, string, string, array<string, mixed>}> ts, order, kind, type, fields */
    private array $rows = [];

    private int $order = 0;

    private int $clock = 0;

    private readonly Metrics $metrics;

    private readonly RequestLog $requestLog;

    /** @var array<string, string> expecting => step id */
    private readonly array $stepIds;

    /**
     * The longest TTL, in seconds, under which every session declared to expire is swept by
     * now: the shortest time from such a session's last message to now, less the sweep delay.
     */
    private ?int $ttlRoom = null;

    /**
     * @param int $blockMinutes `block.duration_minutes`
     */
    public function __construct(
        private readonly Connection $db,
        private readonly FlowDefinition $flow,
        private readonly int $ttlSeconds,
        public readonly ReportPeriod $period,
        private readonly int $now,
        private readonly int $blockMinutes = 60,
    ) {
        $this->metrics = new Metrics($db, fn (): int => $this->clock, static function (string $line): void {
            // A failed write is turned into an exception by write(), which rolls everything back.
        });
        $this->requestLog = new RequestLog($db, static function (string $line): void {
        });
        $ids = [];
        foreach (FlowDefinition::EXPECTING as $expecting) {
            $ids[$expecting] = $flow->inputStepFor($expecting)->id;
        }
        $this->stepIds = $ids;
    }

    /**
     * The writer for this config: the flow file, `bot.session_ttl_minutes`, the default report
     * period in `reports.timezone` as of $now, and `block.duration_minutes`.
     */
    public static function fromConfig(Connection $db, ConfigRepository $config, string $flowPath, int $now): self
    {
        return new self(
            $db,
            FlowDefinition::fromFile($flowPath, $config),
            $config->int('bot.session_ttl_minutes') * 60,
            ReportPeriod::fromStrings(null, null, $config->string('reports.timezone'), $now),
            $now,
            $config->int('block.duration_minutes'),
        );
    }

    /**
     * Builds every row, then writes them in time order. Nothing is written when the config
     * can't hold the demo (SeedDemoRefused).
     *
     * @return array{sessions: int, events: int, requests: int}
     *
     * @throws SeedDemoRefused when `bot.session_ttl_minutes` is so long that a session declared
     *         to expire would be swept after now, which would place a row in the future
     */
    public function write(): array
    {
        $sessions = 0;
        foreach (SEED_SESSIONS as $index => $session) {
            $this->session($index + 1, ...$session);
            $sessions++;
        }
        foreach (SEED_CONVERSIONS as $conversion) {
            $this->conversion(...$conversion);
        }
        foreach (SEED_ERRORS as [$days, $at, $ip, $method, $path, $code]) {
            $this->errorResponse($this->at($days, $at), $ip, $method, $path, $code);
        }
        $this->blockedClient($this->at(2, '11:00'));

        // L-04 (owner decision 2026-10-22): refuse, never clamp, a TTL that would put an expiry
        // sweep after now.
        if ($this->ttlRoom !== null && $this->ttlSeconds > $this->ttlRoom) {
            throw new SeedDemoRefused(sprintf(
                'bot.session_ttl_minutes is %d, too long for the demo data: its expired sessions would close after now; seed with at most %d',
                intdiv($this->ttlSeconds, 60),
                max(0, intdiv($this->ttlRoom, 60)),
            ));
        }

        usort($this->rows, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $events = 0;
        $requests = 0;
        foreach ($this->rows as [$ts, , $kind, $type, $fields]) {
            if ($ts > $this->now) {
                throw new LogicException('A demo row would be in the future');
            }
            $this->clock = $ts;
            if ($kind === 'event') {
                $fields['meta'] = ($fields['meta'] ?? []) + ['demo' => true];
                if (!$this->metrics->record($type, $fields)) {
                    throw new RuntimeException('A demo event could not be written');
                }
                $events++;
            } elseif ($kind === 'request') {
                if (!$this->requestLog->record($ts, $fields['ip'], $fields['method'], $fields['path'], $fields['status'], $fields['latency_ms'], $fields['blocked'])) {
                    throw new RuntimeException('A demo request-log row could not be written');
                }
                $requests++;
            } else {
                $this->db->pdo()
                    ->prepare('INSERT INTO sessions (session_id, channel, step, status, collected, retries, created_at, updated_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute(array_values($fields));
            }
        }

        return ['sessions' => $sessions, 'events' => $events, 'requests' => $requests];
    }

    /**
     * One bot session, played like the flow engine would: events per message, the final
     * `sessions` row, and for a static session its widget page view and `/bot/message` requests.
     *
     * @param list<array{string, mixed}> $answers
     */
    private function session(int $number, string $channel, int $days, string|int $when, ?string $ip, array $answers, ?string $end): void
    {
        $id = substr(hash('sha256', "fx-installments demo session {$number}"), 0, 32);
        $started = $days === 0 ? $this->now - (int) $when * 60 : $this->at($days, (string) $when);
        $bot = ['source' => 'bot', 'channel' => $channel, 'session_id' => $id];
        $order = array_values($this->stepIds);
        $step = $order[0];
        $collected = new Collected();
        $retries = 0;
        $status = 'open';
        $ts = $started;

        if ($ip !== null) {
            foreach (['/bot/widget', '/bot/widget.js', '/bot/widget.css', '/bot/widget.svg'] as $path) {
                $this->request($started - 2, $ip, 'GET', $path, 200);
            }
            $this->request($started - 2, $ip, 'GET', '/favicon.ico', 204);
            $this->request($started, $ip, 'POST', '/bot/message', 200);
        }
        $this->event($ts, 'bot.session_start', $bot + ['step' => $this->flow->firstStep()->id, 'meta' => ['flow' => $this->flow->name]]);

        foreach ($answers as [$kind, $value]) {
            if ($status !== 'open') {
                throw new LogicException("Demo session {$number} has answers after it closed");
            }
            $ts += SEED_MESSAGE_GAP;
            if ($ip !== null) {
                $this->request($ts, $ip, 'POST', '/bot/message', 200);
            }
            $expecting = (string) array_search($step, $this->stepIds, true);
            if ($kind === 'bad') {
                $retries++;
                $this->event($ts, 'bot.step', $bot + ['step' => $step, 'error_code' => self::validationCode($expecting), 'meta' => [
                    'outcome' => in_array($value, ['unclear', 'ambiguous'], true) ? 'unclear' : 'invalid',
                    'reason' => $value,
                    'retry' => $retries,
                ]] + $collected->eventFields());
                if ($retries >= $this->flow->maxRetries) {
                    $this->event($ts, 'bot.session_abandoned', $bot + ['step' => $step, 'error_code' => ErrorCodes::BOT_MAX_RETRIES, 'meta' => ['retries' => $retries]] + $collected->eventFields());
                    $status = 'abandoned';
                }
                continue;
            }

            $meta = ['outcome' => 'ok'];
            $collected = match ($expecting) {
                'amount' => $collected->withAmount($value),
                'currency' => $collected->withCurrency($value[0], $value[1]),
                'installments' => $collected->withInstallments($value[0]),
            };
            if ($expecting === 'installments' && $value[1]) {
                $meta['default'] = true;
            }
            $next = $order[array_search($step, $order, true) + 1] ?? null;
            if ($next !== null) {
                $this->event($ts, 'bot.step', $bot + ['step' => $step, 'meta' => $meta] + $collected->eventFields());
                $step = $next;
                $retries = 0;
                continue;
            }

            // The last answer: the result step converts at the stored rate (a hit), and the
            // completed row keeps the step's retry count, as the flow engine saves it.
            $currency = (string) $collected->currency;
            $rate = SEED_RATES[$currency];
            $this->lookup($ts, $currency);
            $this->event($ts, 'bot.step', $bot + ['step' => $step, 'meta' => $meta] + $collected->eventFields());
            $step = $this->flow->callStep()->id;
            $this->event($ts, 'bot.session_completed', $bot + ['step' => $step, 'meta' => [
                'template' => self::template((int) $collected->amountUsdCents, $rate, $currency, (int) $collected->installments),
                'stale' => false,
                'rate_scaled' => $rate,
                'rate_as_of' => self::rateAsOf($ts),
            ]] + $collected->eventFields());
            $status = 'completed';
        }

        $updated = $ts;
        $expires = $status === 'open' ? $ts + $this->ttlSeconds : $ts;
        if ($status === 'open' && $end === 'expire') {
            // Closed by a later sweep (SessionSweeper): abandoned at its last step. write()
            // refuses a TTL that would put that sweep after now.
            $room = $this->now - SEED_SWEEP_DELAY - $ts;
            $this->ttlRoom = $this->ttlRoom === null ? $room : min($this->ttlRoom, $room);
            $updated = $expires + SEED_SWEEP_DELAY;
            $this->event($updated, 'bot.session_abandoned', $bot + ['step' => $step, 'error_code' => ErrorCodes::BOT_SESSION_EXPIRED, 'meta' => ['expires_at' => $expires]] + $collected->eventFields());
            $status = 'abandoned';
        } elseif (($status === 'open') !== ($end === 'open')) {
            throw new LogicException("Demo session {$number} doesn't end as declared");
        }
        $this->rows[] = [$updated, $this->order++, 'session', '', [
            'session_id' => $id,
            'channel' => $channel,
            'step' => $step,
            'status' => $status,
            'collected' => $collected->toJson(),
            'retries' => $retries,
            'created_at' => $started,
            'updated_at' => $updated,
            'expires_at' => $expires,
        ]];
    }

    /**
     * One `GET /convert` answered by its handler: an `api.request` event (and an `error` event
     * for a refused one, as the Kernel writes) and its request-log row.
     */
    private function conversion(int $days, string $at, string $ip, ?int $cents, ?string $currency, ?int $installments, ?string $code, ?string $reason): void
    {
        $ts = $this->at($days, $at);
        $status = $code === null ? 200 : (int) ErrorCodes::httpStatus($code);
        $fields = array_filter(['amount_usd_cents' => $cents, 'currency' => $currency, 'installments' => $installments], static fn (int|string|null $value): bool => $value !== null);
        $meta = ['endpoint' => 'convert'];
        if ($code === null) {
            $rate = SEED_RATES[(string) $currency];
            $this->lookup($ts, (string) $currency);
            $meta += ['stale' => false, 'rate_scaled' => $rate, 'rate_as_of' => self::rateAsOf($ts)];
        } else {
            $meta['reason'] = $reason;
        }
        $this->event($ts, 'api.request', $fields + ['source' => 'api', 'error_code' => $code, 'latency_ms' => 4, 'meta' => $meta + ['http_status' => $status]]);
        if ($code !== null) {
            $this->event($ts, 'error', ['error_code' => $code, 'latency_ms' => 4, 'meta' => ['http_status' => $status, 'method' => 'GET', 'path' => '/convert']]);
        }
        $this->request($ts, $ip, 'GET', '/convert', $status);
    }

    /**
     * An error response the Kernel recorded: its `error` event and its request-log row.
     */
    private function errorResponse(int $ts, string $ip, string $method, string $path, string $code): void
    {
        $status = (int) ErrorCodes::httpStatus($code);
        $this->event($ts, 'error', ['error_code' => $code, 'latency_ms' => 1, 'meta' => ['http_status' => $status, 'method' => $method, 'path' => $path]]);
        $this->request($ts, $ip, $method, $path, $status);
    }

    /**
     * SEED_BLOCKED_IP: a 429 in five consecutive minutes, an automatic block on the fifth
     * (`block.rate_limit_hits`) for `block.duration_minutes`, blocked requests 6 and 9 minutes
     * in, and an operator's unblock 25 minutes in, or 30 seconds before the block would end if
     * that is sooner (L-04), so it always lifts an active block; a blocked request is written
     * only before the unblock. Like the real app, it writes `ip.block` and `ip.unblock` events
     * without the IP, and no `ip_blocks` row (that block would have ended and been pruned long
     * ago).
     */
    private function blockedClient(int $start): void
    {
        for ($minute = 0; $minute < 5; $minute++) {
            $this->errorResponse($start + $minute * 60 + 30, SEED_BLOCKED_IP, 'GET', '/convert', ErrorCodes::RATE_LIMITED);
        }
        $blockedAt = $start + 4 * 60 + 30;
        $this->event($blockedAt, 'ip.block', ['source' => 'api', 'meta' => [
            'block_source' => IpBlock::AUTO,
            'reason' => IpBlock::REASON_RATE_LIMIT_HITS,
            'duration_minutes' => $this->blockMinutes,
            'extended' => false,
        ]]);
        $unblockAt = min($start + SEED_UNBLOCK_MINUTES * 60, $blockedAt + $this->blockMinutes * 60 - 30);
        foreach ([6, 9] as $minute) {
            if ($start + $minute * 60 < $unblockAt) {
                $this->request($start + $minute * 60, SEED_BLOCKED_IP, 'GET', '/convert', 403, true);
            }
        }
        $this->event($unblockAt, 'ip.unblock', ['source' => 'api', 'meta' => [
            'block_source' => IpBlock::AUTO,
            'was_active' => true,
            'by' => 'cli',
        ]]);
    }

    /**
     * A rate lookup served from the stored table: an `upstream.call` hit, which made no call.
     */
    private function lookup(int $ts, string $currency): void
    {
        $this->event($ts, 'upstream.call', ['source' => 'upstream', 'currency' => $currency, 'status' => 'ok', 'latency_ms' => 0, 'meta' => [
            'result' => 'hit',
            'endpoint' => 'latest',
            'stale' => false,
        ]]);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function event(int $ts, string $type, array $fields): void
    {
        $this->rows[] = [$ts, $this->order++, 'event', $type, $fields];
    }

    private function request(int $ts, string $ip, string $method, string $path, int $status, bool $blocked = false): void
    {
        $this->rows[] = [$ts, $this->order++, 'request', '', [
            'ip' => $ip, 'method' => $method, 'path' => $path, 'status' => $status, 'latency_ms' => $blocked ? 0 : 3, 'blocked' => $blocked,
        ]];
    }

    /**
     * $at (`HH:MM`, local) on the day $days before today in the report's time zone. It is read
     * as a local time on that date, so it holds on a daylight-saving change day too (L-04); a
     * time a change skips is moved forward by the change, as PHP does.
     */
    private function at(int $days, string $at): int
    {
        if ($days < 1 || $days >= ReportPeriod::DEFAULT_DAYS || preg_match('/^(\d{2}):(\d{2})$/D', $at, $match) !== 1) {
            throw new LogicException('A demo time is a day 1 to 6 days back and HH:MM');
        }
        $date = $this->period->days[ReportPeriod::DEFAULT_DAYS - 1 - $days][0];

        return (new DateTimeImmutable("{$date} {$match[1]}:{$match[2]}:00", new DateTimeZone($this->period->timezone)))->getTimestamp();
    }

    private static function validationCode(string $expecting): string
    {
        return match ($expecting) {
            'amount' => ErrorCodes::VALIDATION_AMOUNT,
            'currency' => ErrorCodes::VALIDATION_CURRENCY,
            'installments' => ErrorCodes::VALIDATION_INSTALLMENTS,
        };
    }

    /**
     * The result template the flow engine would pick: single for one payment, even when the
     * exact minor-unit total splits evenly, otherwise uneven. Integer arithmetic only.
     */
    private static function template(int $cents, int $rateScaled, string $currency, int $installments): string
    {
        if ($installments === 1) {
            return 'single';
        }
        $total = InstallmentCalculator::minorUnitTotal($cents, $rateScaled, $currency);
        if ($total === null) {
            throw new LogicException('A demo amount is too large for its currency');
        }

        return $total % $installments === 0 ? 'even' : 'uneven';
    }

    /**
     * The latest 00:00:01 UTC at or before $ts: when upstream last updated its rates.
     */
    private static function rateAsOf(int $ts): int
    {
        return intdiv($ts - 1, 86_400) * 86_400 + 1;
    }
}
