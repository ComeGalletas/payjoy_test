<?php

declare(strict_types=1);

namespace FxInstallments\Metrics;

use InvalidArgumentException;

/**
 * Turns a ReportQuery result into the JSON data of `/reports`, CSV, or the table of
 * `bin/report.php` (P1-08). The only file besides InstallmentCalculator that may format money
 * (INV-01, docs/PLAN.md §3): it formats report aggregates, and only from integers. USD totals are
 * integer sums of `amount_usd_cents`, written with integer arithmetic (`104582` → `1,045.82`
 * in JSON and the table, `1045.82` in CSV); no float is ever made. Converted amounts are never
 * shown: Phase 1 reports don't recompute them.
 *
 * - **JSON** (json()): the query's sections as they are, epoch times as ISO 8601 UTC, and an
 *   `amount_usd` string next to every `amount_usd_cents`.
 * - **CSV** (csv()): one long table, `section,key,subkey,metric,value`, one value per row, so it
 *   loads into a spreadsheet as is. Every cell goes through CsvWriter's formula guard.
 * - **Table** (table()): aligned sections for a terminal.
 */
final class ReportFormatter
{
    public const CSV_HEADER = ['section', 'key', 'subkey', 'metric', 'value'];

    private const SUMMARY_LABELS = [
        'sessions_started' => 'Sessions started',
        'sessions_completed' => 'Sessions completed',
        'sessions_abandoned' => 'Sessions abandoned',
        'conversions' => 'Conversions',
        'conversions_api' => '  via /convert',
        'conversions_bot' => '  via the bot',
        'unreadable_amounts' => '  with an unreadable amount',
        'amount_usd_cents' => 'USD converted',
        'api_requests' => '/convert requests',
        'api_errors' => '  answered with an error',
        'error_responses' => 'Error responses',
        'bot_errors' => 'Bot errors',
        'upstream_calls' => 'Upstream calls',
        'conversions_missing_rate' => 'Conversions without a stored rate',
    ];

    /**
     * The `/reports` JSON data.
     *
     * @param array<string, mixed> $report from ReportQuery::run()
     * @return array<string, mixed>
     */
    public static function json(array $report): array
    {
        $period = $report['period'];
        $data = [
            'period' => [
                'from' => $period['from'],
                'to' => $period['to'],
                'timezone' => $period['timezone'],
                'days' => $period['days'],
                'start' => self::iso($period['start_unix']),
                'end' => self::iso($period['end_unix']),
            ],
            'generated_at' => self::iso($report['generated_unix']),
            'summary' => self::withUsd($report['summary']),
            'days' => array_map(self::withUsd(...), $report['days']),
            'currencies' => array_map(self::withUsd(...), $report['currencies']),
            'countries' => array_map(self::withUsd(...), $report['countries']),
            'funnel' => $report['funnel'],
            'errors' => $report['errors'],
            'upstream' => $report['upstream'],
            'requests' => $report['requests'],
            'ip_blocks' => $report['ip_blocks'],
            'notes' => $report['notes'],
        ];

        return $data;
    }

    /**
     * The report as CSV text (CRLF lines, header first).
     *
     * @param array<string, mixed> $report
     */
    public static function csv(array $report): string
    {
        return CsvWriter::write(self::csvRows($report));
    }

    /**
     * @param array<string, mixed> $report
     * @return list<list<string|int|null>>
     */
    public static function csvRows(array $report): array
    {
        $rows = [self::CSV_HEADER];
        $period = $report['period'];
        foreach (['from' => $period['from'], 'to' => $period['to'], 'timezone' => $period['timezone'], 'days' => $period['days'],
            'start' => self::iso($period['start_unix']), 'end' => self::iso($period['end_unix']), 'generated_at' => self::iso($report['generated_unix'])] as $metric => $value) {
            $rows[] = ['period', null, null, $metric, $value];
        }
        self::csvCounts($rows, 'summary', null, null, $report['summary']);
        foreach ($report['days'] as $day) {
            self::csvCounts($rows, 'day', $day['date'], null, $day, ['date']);
        }
        foreach ($report['currencies'] as $row) {
            self::csvCounts($rows, 'currency', $row['currency'], null, $row, ['currency']);
        }
        foreach ($report['countries'] as $row) {
            self::csvCounts($rows, 'country', $row['country'], null, $row, ['country']);
        }
        foreach ($report['funnel'] as $funnel) {
            foreach (['started', 'completed', 'abandoned', 'never_answered', 'open'] as $metric) {
                $rows[] = ['funnel', $funnel['channel'], null, $metric, $funnel[$metric]];
            }
            foreach ($funnel['steps'] as $step) {
                foreach ($step as $metric => $value) {
                    if ($metric !== 'step') {
                        $rows[] = ['funnel_step', $funnel['channel'], $step['step'], $metric, $value];
                    }
                }
            }
            foreach ($funnel['abandoned_by_code'] as $row) {
                $rows[] = ['funnel_abandoned', $funnel['channel'], $row['code'], 'count', $row['count']];
            }
        }
        foreach ($report['errors'] as $row) {
            self::csvCounts($rows, 'error', $row['code'], null, $row, ['code']);
        }
        $upstream = $report['upstream'];
        $rows[] = ['upstream', null, null, 'calls', $upstream['calls']];
        $rows[] = ['upstream', null, null, 'breaker_resets', $upstream['breaker_resets']];
        foreach ($upstream['endpoints'] as $row) {
            self::csvCounts($rows, 'upstream_endpoint', $row['endpoint'], null, $row, ['endpoint']);
        }
        foreach ($upstream['failures'] as $row) {
            $rows[] = ['upstream_failure', $row['endpoint'], $row['code'], 'count', $row['count']];
        }
        $requests = $report['requests'];
        $rows[] = ['requests', null, null, 'total', $requests['total']];
        $rows[] = ['requests', null, null, 'blocked', $requests['blocked']];
        foreach ($requests['kinds'] as $row) {
            self::csvCounts($rows, 'requests_kind', $row['kind'], null, $row, ['kind']);
        }
        foreach ($report['ip_blocks']['blocks'] as $row) {
            $rows[] = ['ip_block', $row['block_source'], $row['reason'], 'count', $row['count']];
            $rows[] = ['ip_block', $row['block_source'], $row['reason'], 'extended', $row['extended']];
        }
        foreach ($report['ip_blocks']['unblocks'] as $row) {
            $rows[] = ['ip_unblock', $row['block_source'], null, 'count', $row['count']];
        }
        foreach ($report['notes'] as $note) {
            $rows[] = ['note', null, null, 'text', $note];
        }

        return $rows;
    }

    /**
     * The report as a text table for a terminal.
     *
     * @param array<string, mixed> $report
     */
    public static function table(array $report): string
    {
        $period = $report['period'];
        $out = sprintf(
            "FX Installments report: %s to %s (%s, %d %s)\nGenerated %s\n",
            $period['from'],
            $period['to'],
            $period['timezone'],
            $period['days'],
            $period['days'] === 1 ? 'day' : 'days',
            self::iso($report['generated_unix']),
        );

        $summary = [];
        foreach (self::SUMMARY_LABELS as $key => $label) {
            $value = $report['summary'][$key];
            $summary[] = [$label, $key === 'amount_usd_cents' ? self::usd($value) : self::integer($value)];
        }
        $out .= self::section('Summary', ['', ''], $summary);

        $out .= self::section(
            'Days',
            ['Date', 'Started', 'Completed', 'Abandoned', 'Conversions', 'USD', '/convert', 'Errors', 'Upstream'],
            array_map(static fn (array $day): array => [
                $day['date'], self::integer($day['sessions_started']), self::integer($day['sessions_completed']),
                self::integer($day['sessions_abandoned']), self::integer($day['conversions']), self::usd($day['amount_usd_cents']),
                self::integer($day['api_requests']), self::integer($day['error_responses']), self::integer($day['upstream_calls']),
            ], $report['days']),
        );

        $dimension = static fn (string $key): \Closure => static fn (array $row): array => [
            $row[$key], self::integer($row['requested']), self::integer($row['conversions']),
            self::integer($row['conversions_api']), self::integer($row['conversions_bot']), self::usd($row['amount_usd_cents']),
        ];
        $out .= self::section('Currencies', ['Currency', 'Requested', 'Conversions', 'API', 'Bot', 'USD'], array_map($dimension('currency'), $report['currencies']));
        $out .= self::section('Countries (typed in the bot)', ['Country', 'Requested', 'Conversions', 'API', 'Bot', 'USD'], array_map($dimension('country'), $report['countries']));

        foreach ($report['funnel'] as $funnel) {
            $steps = array_map(static fn (array $step): array => [
                (string) $step['step'],
                $step['reached'] === null ? '-' : self::integer($step['reached']),
                isset($step['passed']) ? self::integer($step['passed']) : '-',
                isset($step['retries']) ? self::integer($step['retries']) : '-',
                isset($step['completed']) ? self::integer($step['completed']) : '-',
                self::integer($step['abandoned']),
                self::integer($step['never_answered'] ?? 0),
                self::integer($step['open'] ?? 0),
            ], $funnel['steps']);
            $out .= self::section(
                "Funnel, channel {$funnel['channel']} (sessions started in the period)",
                ['Step', 'Reached', 'Passed', 'Retries', 'Completed', 'Abandoned', 'Never answered', 'Open'],
                $steps,
            );
            $codes = array_map(static fn (array $row): string => "{$row['code']} {$row['count']}", $funnel['abandoned_by_code']);
            $out .= sprintf(
                "  started %s, completed %s, abandoned %s, never answered %s, still open %s\n  abandoned by code: %s\n",
                self::integer($funnel['started']),
                self::integer($funnel['completed']),
                self::integer($funnel['abandoned']),
                self::integer($funnel['never_answered']),
                self::integer($funnel['open']),
                $codes === [] ? 'none' : implode(', ', $codes),
            );
        }

        $out .= self::section('Errors by code', ['Code', 'HTTP', 'Bot', 'Total'], array_map(static fn (array $row): array => [
            $row['code'], self::integer($row['http']), self::integer($row['bot']), self::integer($row['total']),
        ], $report['errors']));

        $upstream = $report['upstream'];
        $out .= self::section(
            sprintf('Upstream (%s calls, %s breaker resets)', self::integer($upstream['calls']), self::integer($upstream['breaker_resets'])),
            ['Endpoint', 'Lookups', 'Hit', 'Miss', 'Stale', 'Blocked', 'Calls', 'Failed', 'No key'],
            array_map(static fn (array $row): array => [
                $row['endpoint'], self::integer($row['lookups']), self::integer($row['hit']), self::integer($row['miss']),
                self::integer($row['stale']), self::integer($row['blocked']), self::integer($row['calls']),
                self::integer($row['failed_calls']), self::integer($row['no_key']),
            ], $upstream['endpoints']),
        );
        if ($upstream['failures'] !== []) {
            $out .= self::section('Failed upstream calls', ['Endpoint', 'Code', 'Calls'], array_map(static fn (array $row): array => [
                $row['endpoint'], $row['code'], self::integer($row['count']),
            ], $upstream['failures']));
        }

        $requests = $report['requests'];
        $out .= self::section(
            sprintf('Request log (%s requests, %s blocked)', self::integer($requests['total']), self::integer($requests['blocked'])),
            ['Kind', 'Total', '2xx', '3xx', '4xx', '5xx', 'Other', 'Blocked'],
            array_map(static fn (array $row): array => [
                $row['kind'], self::integer($row['total']), self::integer($row['2xx']), self::integer($row['3xx']),
                self::integer($row['4xx']), self::integer($row['5xx']), self::integer($row['other']), self::integer($row['blocked']),
            ], $requests['kinds']),
        );

        $blocks = $report['ip_blocks'];
        $out .= self::section('IP blocks', ['Source', 'Reason', 'Blocks', 'Extended'], array_map(static fn (array $row): array => [
            $row['block_source'], $row['reason'], self::integer($row['count']), self::integer($row['extended']),
        ], $blocks['blocks']));
        $out .= self::section('IP unblocks', ['Source of the lifted block', 'Unblocks'], array_map(static fn (array $row): array => [
            $row['block_source'], self::integer($row['count']),
        ], $blocks['unblocks']));

        $out .= "\nNotes\n";
        foreach ($report['notes'] as $note) {
            $out .= "  - {$note}\n";
        }

        return $out;
    }

    /**
     * Cents as a grouped USD amount: 104582 → `1,045.82`.
     */
    public static function usd(int $cents): string
    {
        return self::group(self::dollars($cents)) . '.' . self::centsPart($cents);
    }

    /**
     * Cents as a plain decimal for CSV: 104582 → `1045.82`.
     */
    public static function usdPlain(int $cents): string
    {
        return self::dollars($cents) . '.' . self::centsPart($cents);
    }

    /**
     * A count with thousands grouping: 12345 → `12,345`. Report counts are never negative.
     */
    public static function integer(int $value): string
    {
        if ($value < 0) {
            throw new InvalidArgumentException('Report counts are never negative');
        }

        return self::group((string) $value);
    }

    public static function iso(int $unix): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $unix);
    }

    private static function dollars(int $cents): string
    {
        if ($cents < 0) {
            throw new InvalidArgumentException('Report amounts are never negative');
        }

        return (string) intdiv($cents, 100);
    }

    private static function centsPart(int $cents): string
    {
        return str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function group(string $digits): string
    {
        $grouped = '';
        $length = strlen($digits);
        for ($i = 0; $i < $length; $i++) {
            if ($i > 0 && ($length - $i) % 3 === 0) {
                $grouped .= ',';
            }
            $grouped .= $digits[$i];
        }

        return $grouped;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function withUsd(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            $out[$key] = $value;
            if ($key === 'amount_usd_cents') {
                $out['amount_usd'] = self::usd($value);
            }
        }

        return $out;
    }

    /**
     * One row per integer in $counts (skipping $skip), plus `amount_usd` next to the cents.
     *
     * @param list<list<string|int|null>> $rows
     * @param array<string, mixed> $counts
     * @param list<string> $skip
     */
    private static function csvCounts(array &$rows, string $section, string|int|null $key, string|int|null $subkey, array $counts, array $skip = []): void
    {
        $key = $key === null ? null : (string) $key;
        $subkey = $subkey === null ? null : (string) $subkey;
        foreach ($counts as $metric => $value) {
            if (in_array($metric, $skip, true)) {
                continue;
            }
            $rows[] = [$section, $key, $subkey, (string) $metric, $value];
            if ($metric === 'amount_usd_cents') {
                $rows[] = [$section, $key, $subkey, 'amount_usd', self::usdPlain($value)];
            }
        }
    }

    /**
     * A titled block of aligned columns: the first left-aligned, the rest right-aligned.
     *
     * @param list<string> $header
     * @param list<list<string|int>> $rows cells are cast to strings (a label PHP turned into
     *        an int array key, like a step named `2024`, stays printable: round 2)
     */
    private static function section(string $title, array $header, array $rows): string
    {
        $out = "\n{$title}\n";
        if ($rows === []) {
            return $out . "  (none)\n";
        }
        $rows = array_map(static fn (array $row): array => array_map('strval', $row), $rows);
        $showHeader = array_filter($header, static fn (string $cell): bool => $cell !== '') !== [];
        $all = $showHeader ? [$header, ...$rows] : $rows;
        $widths = [];
        foreach ($all as $row) {
            foreach ($row as $index => $cell) {
                $widths[$index] = max($widths[$index] ?? 0, mb_strlen($cell, 'UTF-8'));
            }
        }
        foreach ($all as $row) {
            $cells = [];
            foreach ($row as $index => $cell) {
                $cells[] = mb_str_pad($cell, $widths[$index], ' ', $index === 0 ? STR_PAD_RIGHT : STR_PAD_LEFT, 'UTF-8');
            }
            $out .= '  ' . rtrim(implode('  ', $cells)) . "\n";
        }

        return $out;
    }
}
