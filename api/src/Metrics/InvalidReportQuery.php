<?php

declare(strict_types=1);

namespace FxInstallments\Metrics;

use InvalidArgumentException;

/**
 * A report period that can't be read (P1-08): a bad `from` or `to`. `reason` is a fixed word:
 * `format` (not a `YYYY-MM-DD` calendar date from 1970 on), `order` (`from` after `to`) or
 * `range` (more than ReportPeriod::MAX_DAYS days). `/reports` answers it with 400 BAD_REQUEST;
 * `bin/report.php` prints the message and exits 2. The message is fixed text, never the input.
 */
final class InvalidReportQuery extends InvalidArgumentException
{
    public const REASONS = ['format', 'order', 'range'];

    public function __construct(public readonly string $reason)
    {
        if (!in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException('Unknown report query reason');
        }
        parent::__construct(match ($reason) {
            'format' => 'from and to must be calendar dates written YYYY-MM-DD',
            'order' => 'from must not be after to',
            default => 'a report covers at most ' . ReportPeriod::MAX_DAYS . ' days',
        });
    }
}
