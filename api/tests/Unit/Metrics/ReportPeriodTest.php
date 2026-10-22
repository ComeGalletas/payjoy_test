<?php

declare(strict_types=1);

namespace FxInstallments\Tests\Unit\Metrics;

use FxInstallments\Metrics\InvalidReportQuery;
use FxInstallments\Metrics\ReportPeriod;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportPeriodTest extends TestCase
{
    /** 2026-10-22 03:00:00 UTC, which is 2026-10-21 22:00 in Bogota (UTC-5, no DST). */
    private const NOW = 1792638000;

    public function testDaysAreWholeDaysInTheReportTimezoneBothEndsIncluded(): void
    {
        $period = ReportPeriod::fromStrings('2026-10-20', '2026-10-21', 'America/Bogota', self::NOW);

        self::assertSame(['2026-10-20', '2026-10-21', 'America/Bogota', 2], [$period->from, $period->to, $period->timezone, $period->dayCount()]);
        // 00:00 Bogota is 05:00 UTC.
        self::assertSame(gmmktime(5, 0, 0, 10, 20, 2026), $period->startUnix);
        self::assertSame(gmmktime(5, 0, 0, 10, 22, 2026), $period->endUnix);
        self::assertSame([
            ['2026-10-20', gmmktime(5, 0, 0, 10, 20, 2026), gmmktime(5, 0, 0, 10, 21, 2026)],
            ['2026-10-21', gmmktime(5, 0, 0, 10, 21, 2026), gmmktime(5, 0, 0, 10, 22, 2026)],
        ], $period->days);
    }

    public function testEventsAreBucketedByTheirLocalDay(): void
    {
        $period = ReportPeriod::fromStrings('2026-10-20', '2026-10-21', 'America/Bogota', self::NOW);

        self::assertNull($period->dayIndex(gmmktime(4, 59, 59, 10, 20, 2026)), 'before the first day');
        self::assertSame(0, $period->dayIndex(gmmktime(5, 0, 0, 10, 20, 2026)));
        // 2026-10-21 04:30 UTC is still 2026-10-20 in Bogota.
        self::assertSame(0, $period->dayIndex(gmmktime(4, 30, 0, 10, 21, 2026)));
        self::assertSame(1, $period->dayIndex(gmmktime(5, 0, 0, 10, 21, 2026)));
        self::assertSame(1, $period->dayIndex(gmmktime(4, 59, 59, 10, 22, 2026)));
        self::assertNull($period->dayIndex(gmmktime(5, 0, 0, 10, 22, 2026)), 'the end is exclusive');
    }

    public function testDefaultsAreTheLastSevenDaysInTheZoneTodayIncluded(): void
    {
        // At NOW it is still the 21st in Bogota, but already the 22nd in UTC.
        $bogota = ReportPeriod::fromStrings(null, null, 'America/Bogota', self::NOW);
        self::assertSame(['2026-10-15', '2026-10-21', 7], [$bogota->from, $bogota->to, $bogota->dayCount()]);

        $utc = ReportPeriod::fromStrings(null, null, 'UTC', self::NOW);
        self::assertSame(['2026-10-16', '2026-10-22'], [$utc->from, $utc->to]);

        $toOnly = ReportPeriod::fromStrings(null, '2026-03-02', 'UTC', self::NOW);
        self::assertSame(['2026-02-24', '2026-03-02'], [$toOnly->from, $toOnly->to]);

        $fromOnly = ReportPeriod::fromStrings('2026-10-01', null, 'America/Bogota', self::NOW);
        self::assertSame(['2026-10-01', '2026-10-21', 21], [$fromOnly->from, $fromOnly->to, $fromOnly->dayCount()]);

        $oneDay = ReportPeriod::fromStrings('2026-10-21', '2026-10-21', 'America/Bogota', self::NOW);
        self::assertSame(1, $oneDay->dayCount());
    }

    public function testADstChangeGivesA23Or25HourDay(): void
    {
        // New York: DST starts 2026-03-08 and ends 2026-11-01.
        $spring = ReportPeriod::fromStrings('2026-03-08', '2026-03-08', 'America/New_York', self::NOW);
        self::assertSame(23 * 3600, $spring->endUnix - $spring->startUnix);

        $autumn = ReportPeriod::fromStrings('2026-11-01', '2026-11-01', 'America/New_York', self::NOW);
        self::assertSame(25 * 3600, $autumn->endUnix - $autumn->startUnix);

        // Santiago skips midnight when DST starts (2026-09-06): the day starts at 01:00.
        $skipped = ReportPeriod::fromStrings('2026-09-05', '2026-09-07', 'America/Santiago', self::NOW);
        self::assertSame(['2026-09-05', '2026-09-06', '2026-09-07'], array_column($skipped->days, 0));
        foreach ($skipped->days as $index => [, $start, $end]) {
            self::assertLessThan($end, $start, "day {$index}");
            if ($index > 0) {
                self::assertSame($skipped->days[$index - 1][2], $start, 'days are contiguous');
            }
        }
    }

    public function testTheLongestPeriodIs366Days(): void
    {
        self::assertSame(366, ReportPeriod::fromStrings('2028-01-01', '2028-12-31', 'UTC', self::NOW)->dayCount());

        $this->expectExceptionObject(new InvalidReportQuery('range'));
        ReportPeriod::fromStrings('2026-01-01', '2027-01-02', 'UTC', self::NOW);
    }

    public function testTheLastAcceptedYearIs9998(): void
    {
        $period = ReportPeriod::fromStrings('9998-12-31', '9998-12-31', 'America/Bogota', self::NOW);

        self::assertSame([ReportPeriod::MAX_YEAR, 1], [(int) substr($period->to, 0, 4), $period->dayCount()]);
        self::assertGreaterThan($period->startUnix, $period->endUnix);
        self::assertSame('1970-01-01', ReportPeriod::fromStrings('1970-01-01', '1970-01-01', 'America/Bogota', self::NOW)->from);
    }

    public function testFromAfterToIsRefused(): void
    {
        try {
            ReportPeriod::fromStrings('2026-10-22', '2026-10-21', 'UTC', self::NOW);
            self::fail('from after to was accepted');
        } catch (InvalidReportQuery $e) {
            self::assertSame('order', $e->reason);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badDates(): iterable
    {
        yield 'empty' => [''];
        yield 'no dashes' => ['20261021'];
        yield 'one-digit month' => ['2026-1-21'];
        yield 'not a day' => ['2026-02-30'];
        yield 'month 13' => ['2026-13-01'];
        yield 'time included' => ['2026-10-21T00:00'];
        yield 'trailing newline' => ["2026-10-21\n"];
        yield 'leading space' => [' 2026-10-21'];
        yield 'before 1970' => ['1969-12-31'];
        // Round 2: the day after 9999-12-31 isn't a four-digit year.
        yield 'year 9999' => ['9999-12-31'];
        yield 'early in 9999' => ['9999-01-01'];
        yield 'relative' => ['yesterday'];
        yield 'formula' => ['=CMD()'];
    }

    #[DataProvider('badDates')]
    public function testABadDateIsAFormatError(string $date): void
    {
        foreach ([[$date, '2026-10-21'], ['2026-10-01', $date]] as [$from, $to]) {
            try {
                ReportPeriod::fromStrings($from, $to, 'UTC', self::NOW);
                self::fail('accepted ' . json_encode($date));
            } catch (InvalidReportQuery $e) {
                self::assertSame('format', $e->reason);
            }
        }
    }

    public function testAnUnknownZoneIsAConfigError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('reports.timezone');
        ReportPeriod::fromStrings(null, null, 'Mars/Olympus', self::NOW);
    }
}
