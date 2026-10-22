<?php

declare(strict_types=1);

namespace FxInstallments\Http;

use Closure;
use FxInstallments\Metrics\ErrorCodes;
use FxInstallments\Metrics\InvalidReportQuery;
use FxInstallments\Metrics\ReportFormatter;
use FxInstallments\Metrics\ReportPeriod;
use FxInstallments\Metrics\ReportQuery;

/**
 * `GET /reports?from&to&format=json|csv` (docs/PLAN.md §6, P1-08). App::router() registers it
 * behind AdminAuth only, so an unset or empty ADMIN_TOKEN refuses everything, and a request
 * without the token never reaches the query. It has no rate limiter: only the operator holding
 * the token gets past AdminAuth, and every refused request is a 401, which counts toward
 * automatic blocking (ADR 0016).
 *
 * - `from`, `to`: `YYYY-MM-DD` days in `reports.timezone`, both included; defaults the last 7
 *   days, today included; at most 366 days (ReportPeriod).
 * - `format`: `json` (default) or `csv`.
 * - Other parameters are ignored. An array value (`from[]=...`), an empty value, a bad date,
 *   `from` after `to`, a longer period or another format answers 400 BAD_REQUEST with a fixed
 *   text naming the rules (the code PLAN §5 gives an unreadable request; no new code).
 *
 * JSON is the envelope with ReportFormatter::json(). CSV is the only non-envelope answer this
 * adds (INV-04 exception, ADR 0022): a TextResponse, `text/csv; charset=utf-8`, as an
 * attachment named after the period, every cell guarded against formulas (CsvWriter). Both
 * carry `Cache-Control: no-store`. Expired sessions are closed before counting (ReportQuery).
 */
final class ReportsEndpoint
{
    public const PATH = '/reports';

    public const FORMATS = ['json', 'csv'];

    public const BAD_QUERY_MESSAGE = 'The report query could not be read. Use from and to as YYYY-MM-DD, from not after to, at most 366 days, and format json or csv.';

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param Closure(): ReportQuery $query built on first use, so routing touches no database
     * @param string $timezone `reports.timezone`
     * @param (Closure(): int)|null $clock UTC epoch seconds; defaults to time()
     */
    public function __construct(
        private readonly Closure $query,
        private readonly string $timezone,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function __invoke(Request $request): Response
    {
        $query = $request->query();
        foreach (['from', 'to', 'format'] as $name) {
            if (array_key_exists($name, $query) && !is_string($query[$name])) {
                return self::badQuery();
            }
        }
        $format = $request->queryParam('format') ?? 'json';
        if (!in_array($format, self::FORMATS, true)) {
            return self::badQuery();
        }
        try {
            $period = ReportPeriod::fromStrings($request->queryParam('from'), $request->queryParam('to'), $this->timezone, ($this->clock)());
        } catch (InvalidReportQuery) {
            return self::badQuery();
        }

        $report = ($this->query)()->run($period);

        if ($format === 'csv') {
            return TextResponse::ok(ReportFormatter::csv($report), 'text/csv; charset=utf-8')
                ->withHeader('Content-Disposition', sprintf('attachment; filename="fx-report-%s-to-%s.csv"', $period->from, $period->to))
                ->withHeader('Cache-Control', 'no-store');
        }

        return JsonResponse::ok(ReportFormatter::json($report))->withHeader('Cache-Control', 'no-store');
    }

    private static function badQuery(): JsonResponse
    {
        return JsonResponse::error(ErrorCodes::BAD_REQUEST, self::BAD_QUERY_MESSAGE);
    }
}
