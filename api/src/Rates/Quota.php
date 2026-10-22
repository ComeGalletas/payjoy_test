<?php

declare(strict_types=1);

namespace FxInstallments\Rates;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Upstream's `/quota` answer, for the admin health view (P1-08).
 */
final class Quota
{
    public function __construct(
        public readonly int $planQuota,
        public readonly int $requestsRemaining,
        public readonly int $refreshDayOfMonth,
    ) {
    }

    /**
     * The next date, on or after $now's UTC date, on which upstream says it refreshes the quota
     * (`refresh_day_of_month`, cut to the month's last day: 31 means the 30th in April). Upstream
     * gives no time of day, so this is a date, `YYYY-MM-DD`. The breaker's quota block still ends
     * on the 1st (UTC, ADR 0012); the admin view shows both.
     */
    public function nextRefreshDate(int $now): string
    {
        $today = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('UTC'));
        $year = (int) $today->format('Y');
        $month = (int) $today->format('n');
        $day = min($this->refreshDayOfMonth, (int) $today->format('t'));
        if ((int) $today->format('j') > $day) {
            $month++;
            if ($month === 13) {
                $month = 1;
                $year++;
            }
            $day = min($this->refreshDayOfMonth, self::daysInMonth($year, $month));
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * The number of days in a month, without the calendar extension.
     */
    private static function daysInMonth(int $year, int $month): int
    {
        return (int) (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setDate($year, $month, 1)->format('t');
    }
}
