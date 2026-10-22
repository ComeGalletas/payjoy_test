<?php

declare(strict_types=1);

namespace FxInstallments\Metrics;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * The days a report covers (P1-08): whole calendar days in `reports.timezone`, `from` and `to`
 * both included.
 *
 * - **Form:** `YYYY-MM-DD`, a real calendar date in the years MIN_YEAR to MAX_YEAR (1970 to
 *   9998), in `reports.timezone`.
 * - **Defaults:** a missing `to` is today in that zone; a missing `from` is six days before
 *   `to`, so the default report is the last 7 days, today included.
 * - **Limits:** `from` must not be after `to`, and the period is at most MAX_DAYS days.
 * - **Bounds:** the period starts at 00:00 of `from` in the zone and ends, exclusive, at 00:00
 *   of the day after `to`; each day is split the same way, so a day is 23 or 25 hours long
 *   across a DST change. Events are bucketed by their UTC `ts` against these bounds.
 *
 * Bad input throws InvalidReportQuery with a fixed reason (`format`, `order`, `range`).
 */
final class ReportPeriod
{
    /** At most this many days (a leap year). */
    public const MAX_DAYS = 366;

    /** The default period's length in days. */
    public const DEFAULT_DAYS = 7;

    /**
     * Years a date may have. The period ends at the day after `to`, which must still be a
     * four-digit year, so 9999 is refused (round 2: `9999-12-31` made the end unparsable).
     */
    public const MIN_YEAR = 1970;
    public const MAX_YEAR = 9998;

    private const DATE_PATTERN = '/^(\d{4})-(\d{2})-(\d{2})$/D';

    /**
     * @param list<array{string, int, int}> $days [date, start, end) per day, in order
     */
    private function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $timezone,
        public readonly int $startUnix,
        public readonly int $endUnix,
        public readonly array $days,
    ) {
    }

    /**
     * @param string|null $from `YYYY-MM-DD` or null for the default
     * @param string|null $to `YYYY-MM-DD` or null for today
     * @param int $now UTC epoch seconds, for "today"
     *
     * @throws InvalidReportQuery for a bad date, from after to, or more than MAX_DAYS days
     * @throws InvalidArgumentException for an unknown time zone (a config error)
     */
    public static function fromStrings(?string $from, ?string $to, string $timezone, int $now): self
    {
        $zone = self::zone($timezone);
        // Calendar arithmetic on plain dates (UTC midnights, so no DST), then each date's
        // bounds in the report's zone.
        $toDay = $to === null
            ? self::day((new DateTimeImmutable('@' . $now))->setTimezone($zone)->format('Y-m-d'))
            : self::day($to);
        $fromDay = $from === null ? $toDay->modify('-' . (self::DEFAULT_DAYS - 1) . ' days') : self::day($from);

        if ($fromDay > $toDay) {
            throw new InvalidReportQuery('order');
        }
        if (intdiv($toDay->getTimestamp() - $fromDay->getTimestamp(), 86_400) + 1 > self::MAX_DAYS) {
            throw new InvalidReportQuery('range');
        }

        $days = [];
        for ($day = $fromDay; $day <= $toDay; $day = $day->modify('+1 day')) {
            $days[] = [$day->format('Y-m-d'), self::startOf($day, $zone), self::startOf($day->modify('+1 day'), $zone)];
        }

        return new self(
            $fromDay->format('Y-m-d'),
            $toDay->format('Y-m-d'),
            $zone->getName(),
            $days[0][1],
            $days[count($days) - 1][2],
            $days,
        );
    }

    public function dayCount(): int
    {
        return count($this->days);
    }

    /**
     * The index in days of the day holding $ts, or null when it is outside the period.
     */
    public function dayIndex(int $ts): ?int
    {
        if ($ts < $this->startUnix || $ts >= $this->endUnix) {
            return null;
        }
        $low = 0;
        $high = count($this->days) - 1;
        while ($low < $high) {
            $middle = intdiv($low + $high + 1, 2);
            if ($this->days[$middle][1] <= $ts) {
                $low = $middle;
            } else {
                $high = $middle - 1;
            }
        }

        return $low;
    }

    private static function zone(string $timezone): DateTimeZone
    {
        try {
            return new DateTimeZone($timezone);
        } catch (\Exception) {
            throw new InvalidArgumentException('reports.timezone is not a known time zone');
        }
    }

    /**
     * The calendar date $text as a UTC midnight (a plain date, for arithmetic only).
     *
     * @throws InvalidReportQuery
     */
    private static function day(string $text): DateTimeImmutable
    {
        if (preg_match(self::DATE_PATTERN, $text, $match) !== 1
            || !checkdate((int) $match[2], (int) $match[3], (int) $match[1])
            || (int) $match[1] < self::MIN_YEAR || (int) $match[1] > self::MAX_YEAR) {
            throw new InvalidReportQuery('format');
        }

        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setDate((int) $match[1], (int) $match[2], (int) $match[3])
            ->setTime(0, 0);
    }

    /**
     * The first second of $day's date in $zone (00:00, or the first valid time after it when a
     * DST change skips midnight).
     */
    private static function startOf(DateTimeImmutable $day, DateTimeZone $zone): int
    {
        try {
            return (new DateTimeImmutable($day->format('Y-m-d') . ' 00:00:00', $zone))->getTimestamp();
        } catch (\Exception) {
            // Not reachable within MIN_YEAR..MAX_YEAR; a bad date is still the caller's input.
            throw new InvalidReportQuery('format');
        }
    }
}
