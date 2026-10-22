<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Metrics;

use FxInstallments\Metrics\ReportFormatter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportFormatterTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string, string}>
     */
    public static function amounts(): iterable
    {
        yield 'zero' => [0, '0.00', '0.00'];
        yield 'one cent' => [1, '0.01', '0.01'];
        yield 'ten cents' => [10, '0.10', '0.10'];
        yield 'a dollar' => [100, '1.00', '1.00'];
        yield 'below a thousand' => [99_999, '999.99', '999.99'];
        yield 'a thousand' => [100_000, '1,000.00', '1000.00'];
        yield 'the example' => [104_582, '1,045.82', '1045.82'];
        yield 'a million' => [100_000_000, '1,000,000.00', '1000000.00'];
        yield 'a large total' => [123_456_789_012_345, '1,234,567,890,123.45', '1234567890123.45'];
    }

    #[DataProvider('amounts')]
    public function testUsdIsBuiltFromIntegerCents(int $cents, string $grouped, string $plain): void
    {
        self::assertSame($grouped, ReportFormatter::usd($cents));
        self::assertSame($plain, ReportFormatter::usdPlain($cents));
    }

    public function testANegativeAmountIsAProgrammingError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ReportFormatter::usd(-1);
    }

    public function testCountsAreGrouped(): void
    {
        self::assertSame(['0', '999', '1,000', '12,345', '1,234,567', '-1,000'], array_map(ReportFormatter::integer(...), [0, 999, 1000, 12345, 1234567, -1000]));
    }

    public function testJsonAddsUsdStringsAndIsoTimes(): void
    {
        $data = ReportFormatter::json(self::report());

        self::assertSame(['from' => '2026-10-21', 'to' => '2026-10-21', 'timezone' => 'UTC', 'days' => 1, 'start' => '2026-10-21T00:00:00Z', 'end' => '2026-10-22T00:00:00Z'], $data['period']);
        self::assertSame('2026-10-22T00:00:00Z', $data['generated_at']);
        self::assertSame(104_582, $data['summary']['amount_usd_cents']);
        self::assertSame('1,045.82', $data['summary']['amount_usd']);
        self::assertSame(['amount_usd_cents', 'amount_usd'], array_slice(array_keys($data['summary']), 4, 2), 'the string follows the cents');
        self::assertSame('1,045.82', $data['days'][0]['amount_usd']);
        self::assertSame(['currency' => '=CMD()', 'requested' => 1, 'conversions' => 1, 'conversions_api' => 1, 'conversions_bot' => 0, 'amount_usd_cents' => 104_582, 'amount_usd' => '1,045.82'], $data['currencies'][0]);
        self::assertSame(['period', 'generated_at', 'summary', 'days', 'currencies', 'countries', 'funnel', 'errors', 'upstream', 'requests', 'ip_blocks', 'notes'], array_keys($data));
    }

    public function testCsvIsOneLongGuardedTable(): void
    {
        $csv = ReportFormatter::csv(self::report());
        $lines = explode("\r\n", $csv);

        self::assertSame('section,key,subkey,metric,value', $lines[0]);
        self::assertSame('', end($lines), 'every line ends in CRLF');
        self::assertContains('period,,,from,2026-10-21', $lines);
        self::assertContains('summary,,,amount_usd_cents,104582', $lines);
        self::assertContains('summary,,,amount_usd,1045.82', $lines);
        self::assertContains("currency,'=CMD(),,conversions,1", $lines, 'the formula is guarded');
        self::assertContains("currency,'=CMD(),,amount_usd,1045.82", $lines);
        self::assertContains('country,"Bonaire, Sint Eustatius",,requested,2', $lines);
        self::assertContains('funnel,static,,never_answered,1', $lines);
        self::assertContains('funnel_step,static,amount,reached,3', $lines);
        self::assertContains('funnel_step,static,nickname,reached,', $lines, 'an unknown reach is empty');
        self::assertContains('funnel_abandoned,static,BOT_MAX_RETRIES,count,1', $lines);
        self::assertContains('error,VALIDATION_AMOUNT,,http,1', $lines);
        self::assertContains('upstream,,,calls,1', $lines);
        self::assertContains('upstream_endpoint,latest,,calls,1', $lines);
        self::assertContains('upstream_failure,latest,UPSTREAM_TIMEOUT,count,1', $lines);
        self::assertContains('requests_kind,favicon,,total,2', $lines);
        self::assertContains('ip_block,auto,error_responses,count,1', $lines);
        self::assertContains('ip_unblock,manual,,count,1', $lines);
        self::assertContains('note,,,text,A note.', $lines);
        self::assertNotContains('currency,=CMD(),,conversions,1', $lines);
        foreach (array_slice($lines, 1, -1) as $line) {
            self::assertSame(5, count(str_getcsv($line, ',', '"', '')), $line);
        }
    }

    public function testTheTableHasEverySectionAndNoUnguardedControlText(): void
    {
        $table = ReportFormatter::table(self::report());

        foreach (['FX Installments report: 2026-10-21 to 2026-10-21 (UTC, 1 day)', 'Summary', 'Days', 'Currencies', 'Countries (typed in the bot)',
            'Funnel, channel static', 'Errors by code', 'Upstream (1 calls, 0 breaker resets)', 'Failed upstream calls', 'Request log (3 requests, 1 blocked)',
            'IP blocks', 'IP unblocks', 'Notes', '  - A note.', 'never answered 1', 'abandoned by code: BOT_MAX_RETRIES 1'] as $text) {
            self::assertStringContainsString($text, $table);
        }
        self::assertMatchesRegularExpression('/^  USD converted +1,045\.82$/m', $table);
        self::assertMatchesRegularExpression('/^  nickname +- +0 +1 +- +1$/m', $table);
        self::assertStringNotContainsString("\r", $table);
    }

    public function testEmptySectionsSayNone(): void
    {
        $report = self::report();
        $report['currencies'] = [];
        $report['upstream']['failures'] = [];

        $table = ReportFormatter::table($report);

        self::assertStringContainsString("\nCurrencies\n  (none)\n", $table);
        self::assertStringNotContainsString('Failed upstream calls', $table);
    }

    /**
     * A small report in ReportQuery's shape.
     *
     * @return array<string, mixed>
     */
    private static function report(): array
    {
        $counts = ['sessions_started' => 3, 'sessions_completed' => 1, 'sessions_abandoned' => 1, 'conversions' => 1, 'amount_usd_cents' => 104_582,
            'api_requests' => 1, 'error_responses' => 1, 'upstream_calls' => 1];

        return [
            'period' => ['from' => '2026-10-21', 'to' => '2026-10-21', 'timezone' => 'UTC', 'days' => 1, 'start_unix' => 1792540800, 'end_unix' => 1792627200],
            'generated_unix' => 1792627200,
            'summary' => $counts + ['conversions_api' => 1, 'conversions_bot' => 0, 'api_errors' => 0, 'bot_errors' => 1, 'conversions_missing_rate' => 0],
            'days' => [['date' => '2026-10-21'] + $counts],
            'currencies' => [['currency' => '=CMD()', 'requested' => 1, 'conversions' => 1, 'conversions_api' => 1, 'conversions_bot' => 0, 'amount_usd_cents' => 104_582]],
            'countries' => [['country' => 'Bonaire, Sint Eustatius', 'requested' => 2, 'conversions' => 0, 'conversions_api' => 0, 'conversions_bot' => 0, 'amount_usd_cents' => 0]],
            'funnel' => [[
                'channel' => 'static', 'started' => 3, 'completed' => 1, 'abandoned' => 1, 'never_answered' => 1, 'open' => 0,
                'steps' => [
                    ['step' => 'amount', 'reached' => 3, 'answered' => 2, 'retries' => 3, 'abandoned' => 0],
                    ['step' => 'currency', 'reached' => 2, 'answered' => 1, 'retries' => 0, 'abandoned' => 0],
                    ['step' => 'installments', 'reached' => 1, 'answered' => 1, 'retries' => 0, 'abandoned' => 0],
                    ['step' => 'result', 'reached' => 1, 'completed' => 1, 'abandoned' => 0],
                    ['step' => 'nickname', 'reached' => null, 'answered' => 0, 'retries' => 1, 'abandoned' => 1],
                ],
                'abandoned_by_code' => [['code' => 'BOT_MAX_RETRIES', 'count' => 1]],
            ]],
            'errors' => [['code' => 'VALIDATION_AMOUNT', 'http' => 1, 'bot' => 3, 'total' => 4]],
            'upstream' => [
                'calls' => 1,
                'endpoints' => [['endpoint' => 'latest', 'lookups' => 2, 'hit' => 1, 'miss' => 1, 'stale' => 0, 'blocked' => 0, 'unknown' => 0, 'calls' => 1, 'no_key' => 0, 'failed_calls' => 1]],
                'failures' => [['endpoint' => 'latest', 'code' => 'UPSTREAM_TIMEOUT', 'count' => 1]],
                'breaker_resets' => 0,
            ],
            'requests' => ['total' => 3, 'blocked' => 1, 'kinds' => [
                ['kind' => 'api', 'total' => 1, '2xx' => 1, '3xx' => 0, '4xx' => 0, '5xx' => 0, 'other' => 0, 'blocked' => 0],
                ['kind' => 'favicon', 'total' => 2, '2xx' => 1, '3xx' => 0, '4xx' => 1, '5xx' => 0, 'other' => 0, 'blocked' => 1],
            ]],
            'ip_blocks' => [
                'blocks' => [['block_source' => 'auto', 'reason' => 'error_responses', 'count' => 1, 'extended' => 0]],
                'unblocks' => [['block_source' => 'manual', 'count' => 1]],
            ],
            'notes' => ['A note.'],
        ];
    }
}
