<?php

declare(strict_types=1);

/*
 * Reports for operators (docs/PLAN.md §7, P1-08): the same numbers as `GET /reports`.
 *
 *     php bin/report.php [--from=YYYY-MM-DD] [--to=YYYY-MM-DD] [--format=table|csv|json]
 *
 * `from` and `to` are days in `reports.timezone`, both included; the default is the last 7
 * days, today included, and a report covers at most 366 days. `--format` is `table` (default,
 * for a terminal), `csv` (the `/reports?format=csv` text, formula-guarded) or `json` (the
 * `/reports` data, pretty-printed, without the envelope). Each option may be given once.
 *
 * Like `/reports`, it first closes expired sessions as abandoned (ADR 0021), so it writes those
 * events; a failed sweep only adds a note. Output never holds an IP, the API key or upstream
 * text. Refuses to run while the database is behind the code or its SQLite file is missing
 * (run bin/migrate.php first). Exit codes: 0 done, 1 failed (stderr names the exception class
 * only), 2 usage (an unknown or repeated option, a bad date or format, from after to, a
 * period over 366 days). `--help` prints the usage and exits 0.
 */

use FxInstallments\App;
use FxInstallments\Bootstrap;
use FxInstallments\Config\ConfigRepository;
use FxInstallments\Db\Database;
use FxInstallments\Metrics\InvalidReportQuery;
use FxInstallments\Metrics\ReportFormatter;
use FxInstallments\Metrics\ReportPeriod;

$apiRoot = dirname(__DIR__);
require $apiRoot . '/vendor/autoload.php';

const REPORT_USAGE = "usage: php bin/report.php [--from=YYYY-MM-DD] [--to=YYYY-MM-DD] [--format=table|csv|json]\n";
const REPORT_FORMATS = ['table', 'csv', 'json'];

$usage = static function (string $problem = ''): never {
    fwrite(STDERR, ($problem === '' ? '' : "report: {$problem}\n") . REPORT_USAGE);
    exit(2);
};

$args = array_slice($argv, 1);
if ($args === ['--help'] || $args === ['-h']) {
    fwrite(STDOUT, REPORT_USAGE);
    exit(0);
}
$options = [];
foreach ($args as $arg) {
    if (preg_match('/^--(from|to|format)=(.*)$/sD', $arg, $match) !== 1 || isset($options[$match[1]])) {
        $usage();
    }
    $options[$match[1]] = $match[2];
}
$format = $options['format'] ?? 'table';
if (!in_array($format, REPORT_FORMATS, true)) {
    $usage('--format must be table, csv or json');
}

try {
    Bootstrap::init($apiRoot);
    try {
        $period = ReportPeriod::fromStrings($options['from'] ?? null, $options['to'] ?? null, (new ConfigRepository())->string('reports.timezone'), time());
    } catch (InvalidReportQuery $e) {
        $usage($e->getMessage());
    }

    // A missing SQLite file is refused before connecting, so this never creates one.
    $dsn = Database::dsnFromEnvironment($apiRoot);
    if (str_starts_with($dsn, 'sqlite:') && substr($dsn, 7) !== ':memory:' && !is_file(substr($dsn, 7))) {
        fwrite(STDERR, "report: failed: no database file (run php bin/migrate.php first)\n");
        exit(1);
    }
    $app = App::fromEnvironment($apiRoot);
    $app->db->pdo();
    $report = $app->reportQuery()->run($period);

    fwrite(STDOUT, match ($format) {
        'csv' => ReportFormatter::csv($report),
        'json' => json_encode(ReportFormatter::json($report), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
        default => ReportFormatter::table($report),
    });
    exit(0);
} catch (Throwable $e) {
    // The class only: a message could name the database path or other local details.
    fwrite(STDERR, sprintf("report: failed: %s\n", $e::class));
    exit(1);
}
