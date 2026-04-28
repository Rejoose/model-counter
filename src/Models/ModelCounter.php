<?php

namespace Rejoose\ModelCounter\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Rejoose\ModelCounter\Enums\Interval;

/**
 * @property int $id
 * @property string $owner_type
 * @property int|string $owner_id
 * @property string $key
 * @property ?Interval $interval
 * @property ?string $period_start
 * @property int $count
 * @property string $unique_hash
 */
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

    protected static function booted(): void
    {
        static::creating(function (self $counter) {
            if ($counter->unique_hash) {
                return;
            }

            $counter->unique_hash = static::hashFor(
                $counter->owner_type,
                $counter->owner_id,
                $counter->key,
                $counter->interval instanceof Interval ? $counter->interval->value : $counter->interval,
                $counter->period_start
            );
        });
    }

    /**
     * Build the deterministic uniqueness hash for a counter row.
     *
     * Using a single-column sha1 works around MySQL/Postgres treating NULL
     * as distinct in composite unique indexes, which let duplicate
     * `(owner, key, NULL, NULL)` rows slip past the old
     * model_counters_unique constraint.
     */
    public static function hashFor(
        string $ownerType,
        int|string $ownerId,
        string $key,
        ?string $interval,
        Carbon|string|null $periodStart
    ): string {
        if ($periodStart instanceof Carbon) {
            $periodStart = $periodStart->toDateString();
        }

        return sha1(implode('|', [
            $ownerType,
            (string) $ownerId,
            $key,
            $interval ?? '',
            $periodStart ?? '',
        ]));
    }

    /**
     * Get the current counter value from the database for a given owner and key.
     */
    public static function valueFor(
        Model $owner,
        string $key,
        ?Interval $interval = null,
        ?Carbon $periodStart = null
    ): int {
        $hash = static::hashFor(
            $owner->getMorphClass(),
            $owner->getKey(),
            $key,
            $interval?->value,
            $interval !== null ? ($periodStart ?? $interval->periodStart()) : null
        );

        return (int) (static::where('unique_hash', $hash)->value('count') ?? 0);
    }

    /**
     * Add a delta to an existing counter (or create it if it doesn't exist).
     */
    public static function addDelta(
        Model $owner,
        string $key,
        int $amount,
        ?Interval $interval = null,
        ?Carbon $periodStart = null
    ): void {
        static::addDeltaRaw(
            $owner->getMorphClass(),
            $owner->getKey(),
            $key,
            $amount,
            $interval,
            $periodStart
        );
    }

    /**
     * Add a delta without requiring a loaded owner model. Used by sync so we
     * don't pay for N `Model::find()` lookups per batch.
     */
    public static function addDeltaRaw(
        string $ownerType,
        int|string $ownerId,
        string $key,
        int $amount,
        ?Interval $interval = null,
        ?Carbon $periodStart = null
    ): void {
        $periodStartDate = null;
        if ($interval !== null) {
            $periodStartDate = ($periodStart ?? $interval->periodStart())->toDateString();
        }

        $hash = static::hashFor(
            $ownerType,
            $ownerId,
            $key,
            $interval?->value,
            $periodStartDate
        );

        if (static::incrementByHash($hash, $amount)) {
            return;
        }

        $inserted = static::insertOrIgnore([
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'key' => $key,
            'interval' => $interval?->value,
            'period_start' => $periodStartDate,
            'count' => $amount,
            'unique_hash' => $hash,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted) {
            return;
        }

        // Lost the insert race with another worker; retry the update.
        static::incrementByHash($hash, $amount);
    }

    /**
     * Apply a parameter-bound increment to the row matching $hash.
     * Returns the number of affected rows (0 if no row exists yet).
     */
    protected static function incrementByHash(string $hash, int $amount): int
    {
        return DB::table((new self)->getTable())
            ->where('unique_hash', $hash)
            ->increment('count', $amount, ['updated_at' => now()]);
    }

    /**
     * Reset a counter to zero.
     */
    public static function resetValue(
        Model $owner,
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
        Model $owner,
        string $key,
        int $value,
        ?Interval $interval = null,
        ?Carbon $periodStart = null
    ): void {
        $periodStartDate = null;
        if ($interval !== null) {
            $periodStartDate = ($periodStart ?? $interval->periodStart())->toDateString();
        }

        $hash = static::hashFor(
            $owner->getMorphClass(),
            $owner->getKey(),
            $key,
            $interval?->value,
            $periodStartDate
        );

        $record = static::where('unique_hash', $hash)->first();

        if ($record) {
            $record->update(['count' => $value]);

            return;
        }

        // Populate unique_hash explicitly here so the insert succeeds even
        // when the `creating` event listener is suppressed by Event::fake()
        // in tests.
        static::create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'key' => $key,
            'interval' => $interval?->value,
            'period_start' => $periodStartDate,
            'count' => $value,
            'unique_hash' => $hash,
        ]);
    }

    /**
     * Get all counters for a given owner.
     *
     * @return array<string, int>
     */
    public static function allForOwner(Model $owner): array
    {
        return static::where([
            'owner_type' => $owner->getMorphClass(),
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
        Model $owner,
        string $key,
        Interval $interval,
        int $periods = 12,
        ?Carbon $fromDate = null
    ): array {
        $periodStarts = $interval->previousPeriods($periods, $fromDate);
        $dateStrings = array_map(fn ($p) => $p->format('Y-m-d'), $periodStarts);

        $results = static::where([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'key' => $key,
            'interval' => $interval->value,
        ])
            ->whereIn('period_start', $dateStrings)
            ->pluck('count', 'period_start')
            ->toArray();

        $history = [];
        foreach ($periodStarts as $periodStart) {
            $periodKey = $interval->periodKey($periodStart);
            $dateKey = $periodStart->format('Y-m-d');
            $history[$periodKey] = $results[$dateKey] ?? 0;
        }

        return $history;
    }

    /**
     * Get sum of counts across all (or a date-bounded range of) periods for an interval-based counter.
     */
    public static function sumForInterval(
        Model $owner,
        string $key,
        Interval $interval,
        ?Carbon $from = null,
        ?Carbon $to = null
    ): int {
        $query = static::where([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'key' => $key,
            'interval' => $interval->value,
        ]);

        if ($from !== null) {
            $query->where('period_start', '>=', $from->toDateString());
        }

        if ($to !== null) {
            $query->where('period_start', '<=', $to->toDateString());
        }

        return (int) $query->sum('count');
    }

    /**
     * Delete all counter records for an owner and key.
     */
    public static function deleteFor(
        Model $owner,
        string $key,
        ?Interval $interval = null
    ): int {
        $query = static::where([
            'owner_type' => $owner->getMorphClass(),
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
