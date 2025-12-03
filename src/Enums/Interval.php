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
            self::Quarter => $date->format('Y') . '-Q' . $date->quarter,
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
            $periods[] = match ($this) {
                self::Day => $date->copy()->subDays($i)->startOfDay(),
                self::Week => $date->copy()->subWeeks($i)->startOfWeek(),
                self::Month => $date->copy()->subMonths($i)->startOfMonth(),
                self::Quarter => $date->copy()->subQuarters($i)->startOfQuarter(),
                self::Year => $date->copy()->subYears($i)->startOfYear(),
            };
        }

        return $periods;
    }
}

