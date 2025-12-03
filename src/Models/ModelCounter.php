<?php

namespace Rejoose\ModelCounter\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Rejoose\ModelCounter\Enums\Interval;

class ModelCounter extends Model
{
    /**
     * The attributes that are mass assignable.
     */
    protected $guarded = [];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'count' => 'integer',
            'interval' => Interval::class,
            // period_start is stored as Y-m-d string, not cast to date
            // to avoid timezone/datetime format issues across databases
        ];
    }

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('counter.table_name', 'model_counters');
    }

    /**
     * Get the current counter value from the database for a given owner and key.
     */
    public static function valueFor(
        \Illuminate\Database\Eloquent\Model $owner,
        string $key,
        ?Interval $interval = null,
        ?Carbon $periodStart = null
    ): int {
        $query = static::where([
            'owner_type' => $owner::class,
            'owner_id' => $owner->getKey(),
            'key' => $key,
        ]);

        if ($interval !== null) {
            $periodStart = $periodStart ?? $interval->periodStart();
            $query->where('interval', $interval->value)
                  ->where('period_start', $periodStart->toDateString());
        } else {
            $query->whereNull('interval')
                  ->whereNull('period_start');
        }

        return $query->value('count') ?? 0;
    }

    /**
     * Add a delta to an existing counter (or create it if it doesn't exist).
     *
     * Uses upsert for atomic operations with proper MySQL/PostgreSQL compatibility.
     */
    public static function addDelta(
        \Illuminate\Database\Eloquent\Model $owner,
        string $key,
        int $amount,
        ?Interval $interval = null,
        ?Carbon $periodStart = null
    ): void {
        $periodStartDate = null;
        if ($interval !== null) {
            $periodStartDate = ($periodStart ?? $interval->periodStart())->toDateString();
        }

        $whereClause = [
            'owner_type' => $owner::class,
            'owner_id' => $owner->getKey(),
            'key' => $key,
        ];

        $query = static::where($whereClause);

        if ($interval !== null) {
            $query->where('interval', $interval->value)
                  ->where('period_start', $periodStartDate);
        } else {
            $query->whereNull('interval')
                  ->whereNull('period_start');
        }

        // Try to update existing record
        $updated = $query->update([
            'count' => DB::raw("count + {$amount}"),
            'updated_at' => now(),
        ]);

        // If no record was updated, create a new one
        if (! $updated) {
            $data = [
                'owner_type' => $owner::class,
                'owner_id' => $owner->getKey(),
                'key' => $key,
                'interval' => $interval?->value,
                'period_start' => $periodStartDate,
                'count' => $amount,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $inserted = static::insertOrIgnore($data);

            // If insert failed due to race condition (record was created by another process),
            // try update again to add the delta
            if (! $inserted) {
                $retryQuery = static::where($whereClause);
                if ($interval !== null) {
                    $retryQuery->where('interval', $interval->value)
                              ->where('period_start', $periodStartDate);
                } else {
                    $retryQuery->whereNull('interval')
                              ->whereNull('period_start');
                }

                $retryQuery->update([
                    'count' => DB::raw("count + {$amount}"),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Reset a counter to zero.
     */
    public static function resetValue(
        \Illuminate\Database\Eloquent\Model $owner,
        string $key,
        ?Interval $interval = null,
        ?Carbon $periodStart = null
    ): void {
        static::setValue($owner, $key, 0, $interval, $periodStart);
    }

    /**
     * Set a counter to a specific value.
     */
    public static function setValue(
        \Illuminate\Database\Eloquent\Model $owner,
        string $key,
        int $value,
        ?Interval $interval = null,
        ?Carbon $periodStart = null
    ): void {
        $periodStartDate = null;
        if ($interval !== null) {
            $periodStartDate = ($periodStart ?? $interval->periodStart())->format('Y-m-d');
        }

        // Build query that properly handles NULL values
        $query = static::where('owner_type', $owner::class)
            ->where('owner_id', $owner->getKey())
            ->where('key', $key);

        if ($interval !== null) {
            $query->where('interval', $interval->value)
                  ->where('period_start', $periodStartDate);
        } else {
            $query->whereNull('interval')
                  ->whereNull('period_start');
        }

        $record = $query->first();

        if ($record) {
            $record->update(['count' => $value]);
        } else {
            static::create([
                'owner_type' => $owner::class,
                'owner_id' => $owner->getKey(),
                'key' => $key,
                'interval' => $interval?->value,
                'period_start' => $periodStartDate,
                'count' => $value,
            ]);
        }
    }

    /**
     * Get all counters for a given owner.
     */
    public static function allForOwner(\Illuminate\Database\Eloquent\Model $owner): array
    {
        return static::where([
            'owner_type' => $owner::class,
            'owner_id' => $owner->getKey(),
        ])
            ->whereNull('interval')
            ->whereNull('period_start')
            ->pluck('count', 'key')
            ->toArray();
    }

    /**
     * Get counter history for multiple periods.
     *
     * @return array<string, int> Period key => count
     */
    public static function history(
        \Illuminate\Database\Eloquent\Model $owner,
        string $key,
        Interval $interval,
        int $periods = 12,
        ?Carbon $fromDate = null
    ): array {
        $periodStarts = $interval->previousPeriods($periods, $fromDate);
        $dateStrings = array_map(fn ($p) => $p->format('Y-m-d'), $periodStarts);

        $results = static::where([
            'owner_type' => $owner::class,
            'owner_id' => $owner->getKey(),
            'key' => $key,
            'interval' => $interval->value,
        ])
            ->whereIn('period_start', $dateStrings)
            ->pluck('count', 'period_start')
            ->toArray();

        // Build result array with all periods (including zeros)
        $history = [];
        foreach ($periodStarts as $periodStart) {
            $periodKey = $interval->periodKey($periodStart);
            $dateKey = $periodStart->format('Y-m-d');
            $history[$periodKey] = $results[$dateKey] ?? 0;
        }

        return $history;
    }

    /**
     * Get sum of counts across all periods for an interval-based counter.
     */
    public static function sumForInterval(
        \Illuminate\Database\Eloquent\Model $owner,
        string $key,
        Interval $interval
    ): int {
        return (int) static::where([
            'owner_type' => $owner::class,
            'owner_id' => $owner->getKey(),
            'key' => $key,
            'interval' => $interval->value,
        ])->sum('count');
    }

    /**
     * Delete all counter records for an owner and key.
     */
    public static function deleteFor(
        \Illuminate\Database\Eloquent\Model $owner,
        string $key,
        ?Interval $interval = null
    ): int {
        $query = static::where([
            'owner_type' => $owner::class,
            'owner_id' => $owner->getKey(),
            'key' => $key,
        ]);

        if ($interval !== null) {
            $query->where('interval', $interval->value);
        }

        return $query->delete();
    }

    /**
     * Define the polymorphic relationship to the owner.
     */
    public function owner()
    {
        return $this->morphTo();
    }
}
