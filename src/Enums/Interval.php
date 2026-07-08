<?php

namespace Rejoose\ModelCounter\Enums;

use Carbon\Carbon;

enum Interval: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Quarter = 'quarter';
    case Year = 'year';

    /**
     * Get a human-readable label for the interval.
     */
    public function label(): string
    {
        return match ($this) {
            self::Day => 'Daily',
            self::Week => 'Weekly',
            self::Month => 'Monthly',
            self::Quarter => 'Quarterly',
            self::Year => 'Yearly',
        };
    }

    /**
     * Get the start of the current period for this interval.
     */
    public function periodStart(?Carbon $date = null): Carbon
    {
        $date = $date ?? now();

        return match ($this) {
            self::Day => $date->copy()->startOfDay(),
            self::Week => $date->copy()->startOfWeek(),
            self::Month => $date->copy()->startOfMonth(),
            self::Quarter => $date->copy()->startOfQuarter(),
            self::Year => $date->copy()->startOfYear(),
        };
    }

    /**
     * Get the end of the current period for this interval.
     */
    public function periodEnd(?Carbon $date = null): Carbon
    {
        $date = $date ?? now();

        return match ($this) {
            self::Day => $date->copy()->endOfDay(),
            self::Week => $date->copy()->endOfWeek(),
            self::Month => $date->copy()->endOfMonth(),
            self::Quarter => $date->copy()->endOfQuarter(),
            self::Year => $date->copy()->endOfYear(),
        };
    }

    /**
     * Get the period key format for this interval (used in cache keys).
     */
    public function periodKey(?Carbon $date = null): string
    {
        $date = $date ?? now();

        return match ($this) {
            self::Day => $date->format('Y-m-d'),
            self::Week => $date->format('o-W'), // ISO week
            self::Month => $date->format('Y-m'),
            self::Quarter => $date->format('Y').'-Q'.$date->quarter,
            self::Year => $date->format('Y'),
        };
    }

    /**
     * Get previous periods for history retrieval.
     *
     * @return array<int, Carbon>
     */
    public function previousPeriods(int $count, ?Carbon $fromDate = null): array
    {
        $date = $fromDate ?? now();
        $periods = [];

        for ($i = 0; $i < $count; $i++) {
            // Truncate to the period start *before* subtracting. Subtracting
            // first from a raw date that carries a day-of-month (e.g. the 31st)
            // lets Carbon overflow into the wrong month/quarter (subMonths from
            // May 31 lands in early May, not April), which would then snap back
            // to the current period and silently skip one.
            $periods[] = match ($this) {
                self::Day => $date->copy()->startOfDay()->subDays($i),
                self::Week => $date->copy()->startOfWeek()->subWeeks($i),
                self::Month => $date->copy()->startOfMonth()->subMonths($i),
                self::Quarter => $date->copy()->startOfQuarter()->subQuarters($i),
                self::Year => $date->copy()->startOfYear()->subYears($i),
            };
        }

        return $periods;
    }

    /**
     * Number of whole periods in [$from, $to] inclusive (period starts).
     */
    public function periodsBetween(Carbon $from, Carbon $to): int
    {
        $start = $this->periodStart($from);
        $end = $this->periodStart($to);

        if ($end->lt($start)) {
            return 0;
        }

        return match ($this) {
            self::Day => (int) $start->diffInDays($end) + 1,
            self::Week => (int) $start->diffInWeeks($end) + 1,
            self::Month => (int) $start->diffInMonths($end) + 1,
            self::Quarter => (int) ($start->diffInMonths($end) / 3) + 1,
            self::Year => (int) $start->diffInYears($end) + 1,
        };
    }
}
