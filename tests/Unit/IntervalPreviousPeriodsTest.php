<?php

namespace Rejoose\ModelCounter\Tests\Unit;

use Illuminate\Support\Carbon;
use Rejoose\ModelCounter\Enums\Interval;
use Rejoose\ModelCounter\Tests\TestCase;

class IntervalPreviousPeriodsTest extends TestCase
{
    public function test_month_periods_walk_back_without_skips_from_may_31(): void
    {
        // Regression: subtracting before truncating overflowed. From May 31,
        // subMonths(1) landed in early May (Apr has no 31st) and startOfMonth()
        // snapped it back to May — duplicating the current month and silently
        // skipping April.
        $periods = Interval::Month->previousPeriods(12, Carbon::parse('2026-05-31'));

        $keys = array_map(fn (Carbon $d): string => Interval::Month->periodKey($d), $periods);

        $this->assertSame([
            '2026-05', '2026-04', '2026-03', '2026-02', '2026-01', '2025-12',
            '2025-11', '2025-10', '2025-09', '2025-08', '2025-07', '2025-06',
        ], $keys);
        $this->assertCount(12, array_unique($keys));
    }

    public function test_month_periods_walk_back_without_skips_from_march_31(): void
    {
        // The canonical overflow case: February has no 31st.
        $periods = Interval::Month->previousPeriods(3, Carbon::parse('2026-03-31'));

        $keys = array_map(fn (Carbon $d): string => Interval::Month->periodKey($d), $periods);

        $this->assertSame(['2026-03', '2026-02', '2026-01'], $keys);
    }

    public function test_month_periods_walk_back_without_skips_from_jan_31(): void
    {
        $periods = Interval::Month->previousPeriods(3, Carbon::parse('2026-01-31'));

        $keys = array_map(fn (Carbon $d): string => Interval::Month->periodKey($d), $periods);

        $this->assertSame(['2026-01', '2025-12', '2025-11'], $keys);
    }

    public function test_quarter_periods_walk_back_without_skips_from_quarter_end(): void
    {
        // Aug 31 sits in Q3; stepping back must reach Q2, not overflow back
        // into Q3.
        $periods = Interval::Quarter->previousPeriods(4, Carbon::parse('2026-08-31'));

        $keys = array_map(fn (Carbon $d): string => Interval::Quarter->periodKey($d), $periods);

        $this->assertSame(['2026-Q3', '2026-Q2', '2026-Q1', '2025-Q4'], $keys);
    }
}
