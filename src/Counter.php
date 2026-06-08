<?php

namespace Rejoose\ModelCounter;

use Carbon\Carbon;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Rejoose\ModelCounter\Enums\Interval;
use Rejoose\ModelCounter\Events\CounterDecremented;
use Rejoose\ModelCounter\Events\CounterIncremented;
use Rejoose\ModelCounter\Events\CounterReset;
use Rejoose\ModelCounter\Models\ModelCounter;

class Counter
{
    /**
     * Wire-level owner segment used for global (ownerless) counters. Redis
     * keys can't encode a NULL owner without breaking counter:sync's
     * colon-split, so global counters travel as `global:0` on the wire and
     * are stored with NULL owner_type / owner_id in the database.
     */
    public const GLOBAL_OWNER_TOKEN = 'global';

    /**
     * Validate a counter key.
     *
     * @throws \InvalidArgumentException
     */
    protected static function validateKey(string $key): void
    {
        if ($key === '') {
            throw new \InvalidArgumentException('Counter key cannot be empty.');
        }

        if (str_contains($key, ':')) {
            throw new \InvalidArgumentException('Counter key cannot contain colons.');
        }

        if (strlen($key) > 100) {
            throw new \InvalidArgumentException('Counter key cannot exceed 100 characters.');
        }
    }

    /**
     * Increment a counter for the given owner and key.
     *
     * In direct mode, writes immediately to the database.
     * Otherwise, uses Redis atomic increment for high performance.
     */
    public static function increment(
        ?Model $owner,
        string $key,
        int $amount = 1,
        ?Interval $interval = null
    ): void {
        static::validateKey($key);

        if (config('counter.direct', false)) {
            ModelCounter::addDelta($owner, $key, $amount, $interval);
        } else {
            Cache::store(config('counter.store'))
                ->increment(static::redisKey($owner, $key, $interval), $amount);
        }

        if (config('counter.events', false)) {
            event(new CounterIncremented($owner, $key, $amount, $interval));
        }
    }

    /**
     * Decrement a counter for the given owner and key.
     *
     * In direct mode, writes immediately to the database.
     * Otherwise, uses Redis atomic decrement for high performance.
     */
    public static function decrement(
        ?Model $owner,
        string $key,
        int $amount = 1,
        ?Interval $interval = null
    ): void {
        static::validateKey($key);

        if (config('counter.direct', false)) {
            ModelCounter::addDelta($owner, $key, -$amount, $interval);
        } else {
            Cache::store(config('counter.store'))
                ->decrement(static::redisKey($owner, $key, $interval), $amount);
        }

        if (config('counter.events', false)) {
            event(new CounterDecremented($owner, $key, $amount, $interval));
        }
    }

    /**
     * Get the current count for the given owner and key.
     *
     * In direct mode, returns only the database value.
     * Otherwise, returns database baseline plus cached increments.
     */
    public static function get(
        ?Model $owner,
        string $key,
        ?Interval $interval = null,
        ?Carbon $periodStart = null
    ): int {
        static::validateKey($key);

        $dbValue = ModelCounter::valueFor($owner, $key, $interval, $periodStart);

        // In direct mode, all values are in the database
        if (config('counter.direct', false)) {
            return $dbValue;
        }

        // For historical periods (not current), only check database
        if ($interval !== null && $periodStart !== null) {
            $currentPeriodStart = $interval->periodStart();
            if (! $periodStart->equalTo($currentPeriodStart)) {
                return $dbValue;
            }
        }

        $cacheValue = Cache::store(config('counter.store'))
            ->get(static::redisKey($owner, $key, $interval), 0);

        return $dbValue + $cacheValue;
    }

    /**
     * Get multiple counters for an owner at once.
     *
     * @return array<string, int>
     */
    public static function getMany(?Model $owner, array $keys, ?Interval $interval = null): array
    {
        foreach ($keys as $key) {
            static::validateKey($key);
        }

        if (empty($keys)) {
            return [];
        }

        // In direct mode, just loop (no cache to batch)
        if (config('counter.direct', false)) {
            $results = [];
            foreach ($keys as $key) {
                $results[$key] = ModelCounter::valueFor($owner, $key, $interval);
            }

            return $results;
        }

        // Batch fetch DB values
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = ModelCounter::valueFor($owner, $key, $interval);
        }

        // Batch fetch cache values using Redis MGET when available
        $store = Cache::store(config('counter.store'));
        $redisKeys = [];
        foreach ($keys as $key) {
            $redisKeys[$key] = static::redisKey($owner, $key, $interval);
        }

        $cacheValues = [];
        try {
            if (! method_exists($store, 'connection')) {
                throw new \RuntimeException('Store does not support connection().');
            }
            $connection = $store->connection();
            $rawValues = $connection->mget(array_values($redisKeys));
            $i = 0;
            foreach ($redisKeys as $counterKey => $redisKey) {
                $cacheValues[$counterKey] = (int) ($rawValues[$i] ?? 0);
                $i++;
            }
        } catch (\Throwable) {
            // Fallback to individual reads if MGET is not available
            foreach ($redisKeys as $counterKey => $redisKey) {
                $cacheValues[$counterKey] = (int) $store->get($redisKey, 0);
            }
        }

        foreach ($keys as $key) {
            $results[$key] += $cacheValues[$key] ?? 0;
        }

        return $results;
    }

    /**
     * Increment multiple counters for an owner at once.
     *
     * @param  array<string, int>  $counters  Counter key => amount pairs
     */
    public static function incrementMany(?Model $owner, array $counters, ?Interval $interval = null): void
    {
        foreach (array_keys($counters) as $key) {
            static::validateKey($key);
        }

        if (config('counter.direct', false)) {
            foreach ($counters as $key => $amount) {
                ModelCounter::addDelta($owner, $key, $amount, $interval);
            }

            return;
        }

        $store = Cache::store(config('counter.store'));
        foreach ($counters as $key => $amount) {
            $store->increment(static::redisKey($owner, $key, $interval), $amount);
        }
    }

    /**
     * Decrement multiple counters for an owner at once.
     *
     * @param  array<string, int>  $counters  Counter key => amount pairs
     */
    public static function decrementMany(?Model $owner, array $counters, ?Interval $interval = null): void
    {
        foreach (array_keys($counters) as $key) {
            static::validateKey($key);
        }

        if (config('counter.direct', false)) {
            foreach ($counters as $key => $amount) {
                ModelCounter::addDelta($owner, $key, -$amount, $interval);
            }

            return;
        }

        $store = Cache::store(config('counter.store'));
        foreach ($counters as $key => $amount) {
            $store->decrement(static::redisKey($owner, $key, $interval), $amount);
        }
    }

    /**
     * Get counter history for multiple periods.
     *
     * @return array<string, int> Period key => count
     */
    public static function history(
        ?Model $owner,
        string $key,
        Interval $interval,
        int $periods = 12,
        ?Carbon $fromDate = null
    ): array {
        static::validateKey($key);

        $history = ModelCounter::history($owner, $key, $interval, $periods, $fromDate);

        // In direct mode, all values are in the database
        if (config('counter.direct', false)) {
            return $history;
        }

        // Add current period's cached value
        $currentPeriodKey = $interval->periodKey($fromDate);
        if (isset($history[$currentPeriodKey])) {
            $cacheValue = Cache::store(config('counter.store'))
                ->get(static::redisKey($owner, $key, $interval), 0);
            $history[$currentPeriodKey] += $cacheValue;
        }

        return $history;
    }

    /**
     * Get sum across all (or a date-bounded range of) periods for an interval-based counter.
     */
    public static function sum(
        ?Model $owner,
        string $key,
        Interval $interval,
        ?Carbon $from = null,
        ?Carbon $to = null
    ): int {
        static::validateKey($key);

        $dbSum = ModelCounter::sumForInterval($owner, $key, $interval, $from, $to);

        // In direct mode, all values are in the database
        if (config('counter.direct', false)) {
            return $dbSum;
        }

        // Only add the Redis cache value if the current period falls within the requested range
        $currentPeriodStart = $interval->periodStart();
        $inRange = ($from === null || ! $currentPeriodStart->lt($from))
            && ($to === null || ! $currentPeriodStart->gt($to));

        if (! $inRange) {
            return $dbSum;
        }

        $cacheValue = Cache::store(config('counter.store'))
            ->get(static::redisKey($owner, $key, $interval), 0);

        return $dbSum + $cacheValue;
    }

    /**
     * Reset a counter to zero (both cache and database).
     */
    public static function reset(
        ?Model $owner,
        string $key,
        ?Interval $interval = null,
        ?Carbon $periodStart = null
    ): void {
        static::validateKey($key);

        // Reset database first, then clear cache to avoid data loss if DB fails
        ModelCounter::resetValue($owner, $key, $interval, $periodStart);

        Cache::store(config('counter.store'))
            ->forget(static::redisKey($owner, $key, $interval, $periodStart));

        if (config('counter.events', false)) {
            event(new CounterReset($owner, $key, $interval, $periodStart));
        }
    }

    /**
     * Set a counter to a specific value.
     */
    public static function set(
        ?Model $owner,
        string $key,
        int $value,
        ?Interval $interval = null,
        ?Carbon $periodStart = null
    ): void {
        static::validateKey($key);

        // Set database value first, then clear cache to avoid data loss if DB fails
        ModelCounter::setValue($owner, $key, $value, $interval, $periodStart);

        Cache::store(config('counter.store'))
            ->forget(static::redisKey($owner, $key, $interval, $periodStart));
    }

    /**
     * Bulk set many absolute counter values in a single batched UPSERT.
     *
     * Use this to seed historical data, apply pre-aggregated source counts,
     * or run a fast backfill. One SQL statement per ~200 rows replaces the
     * per-row SELECT + UPDATE/INSERT loop that {@see set()} performs.
     *
     * Cache invalidation runs for *every* input row, including count=0 and
     * including rows skipped via $skipZero. Skipping the DB write while
     * leaving a stale Redis delta in place would let `get()` return the
     * wrong value, so the cache is cleared unconditionally.
     *
     * Each input row:
     *   - owner_type:   ?string (the morph class as stored — e.g. "App\\Models\\User" or a morph-map alias; null for a global counter)
     *   - owner_id:     int|string|null  (null for a global counter)
     *   - key:          string
     *   - interval:     Interval|string|null (enum, value, or null for global)
     *   - period_start: Carbon|string|null   (Carbon, Y-m-d string, or null for global)
     *   - count:        int                  (absolute value, not a delta)
     *
     * If the same logical (owner, key, interval, period) appears more than once
     * in the input, the last occurrence wins — matching natural SET semantics.
     *
     * @param  array<int, array{owner_type: ?string, owner_id: int|string|null, key: string, count: int, interval?: Interval|string|null, period_start?: Carbon|string|null}>  $rows
     * @return int Number of rows written to the database after filtering.
     */
    public static function bulkSet(array $rows, bool $skipZero = false): int
    {
        if ($rows === []) {
            return 0;
        }

        $store = Cache::store(config('counter.store'));
        $prepared = [];

        foreach ($rows as $row) {
            $key = $row['key'];
            static::validateKey($key);

            $intervalRaw = $row['interval'] ?? null;
            $interval = $intervalRaw instanceof Interval
                ? $intervalRaw
                : ($intervalRaw !== null ? Interval::from($intervalRaw) : null);

            $periodStartInput = $row['period_start'] ?? null;
            $periodStart = null;
            $periodStartDate = null;

            if ($interval !== null) {
                $periodStart = $periodStartInput instanceof Carbon
                    ? $periodStartInput
                    : ($periodStartInput !== null ? Carbon::parse($periodStartInput) : $interval->periodStart());
                $periodStartDate = $periodStart->toDateString();
            }

            // Invalidate cache regardless of skipZero. Skipping the DB write
            // while leaving a stale Redis delta would let get() return the
            // wrong value on the next read.
            $store->forget(self::redisKeyRaw(
                $row['owner_type'],
                $row['owner_id'],
                $key,
                $interval,
                $periodStart,
            ));

            $count = (int) $row['count'];
            if ($skipZero && $count === 0) {
                continue;
            }

            $prepared[] = [
                'owner_type' => $row['owner_type'],
                'owner_id' => $row['owner_id'],
                'key' => $key,
                'interval' => $interval?->value,
                'period_start' => $periodStartDate,
                'count' => $count,
            ];
        }

        ModelCounter::bulkSetValue($prepared);

        return count($prepared);
    }

    /**
     * Build the same cache key that {@see redisKey()} produces, but from raw
     * morph values so bulk callers don't need to hydrate an owner model per
     * row.
     */
    private static function redisKeyRaw(
        ?string $ownerType,
        int|string|null $ownerId,
        string $key,
        ?Interval $interval = null,
        ?Carbon $periodStart = null
    ): string {
        // Mirror redisKey()'s null-owner handling so bulk cache invalidation
        // targets the same `global:0` key the increment path wrote.
        $morphClass = $ownerType === null
            ? self::GLOBAL_OWNER_TOKEN
            : strtolower(str_replace('\\', '.', $ownerType));
        $ownerKey = $ownerType === null ? '0' : $ownerId;

        $baseKey = config('counter.prefix')
            .$morphClass.':'
            .$ownerKey.':'
            .$key;

        if ($interval !== null) {
            return $baseKey.':'.$interval->value.':'.$interval->periodKey($periodStart);
        }

        return $baseKey;
    }

    /**
     * Recount a counter by executing a callback that returns the new count.
     *
     * This is a safe operation that:
     * 1. Clears the cache for this counter
     * 2. Executes the count callback within a transaction
     * 3. Sets the database value to the result
     *
     * @param  Closure(): int  $countCallback  A callback that returns the count value
     */
    public static function recount(
        ?Model $owner,
        string $key,
        Closure $countCallback,
        ?Interval $interval = null,
        ?Carbon $periodStart = null
    ): int {
        static::validateKey($key);

        // Clear cache first to prevent stale increments being added
        Cache::store(config('counter.store'))
            ->forget(static::redisKey($owner, $key, $interval, $periodStart));

        // Run the user-supplied count outside the transaction. Counting a
        // large source table can take seconds to minutes and holding a
        // transaction open for that long blocks vacuum/replication.
        $count = $countCallback();

        DB::transaction(function () use ($owner, $key, $count, $interval, $periodStart) {
            ModelCounter::setValue($owner, $key, $count, $interval, $periodStart);
        });

        return $count;
    }

    /**
     * Recount multiple periods for an interval-based counter.
     *
     * @param  Closure(Carbon $periodStart, Carbon $periodEnd): int  $countCallback
     * @return array<string, int> Period key => count
     */
    public static function recountPeriods(
        ?Model $owner,
        string $key,
        Interval $interval,
        Closure $countCallback,
        int $periods = 12,
        ?Carbon $fromDate = null
    ): array {
        static::validateKey($key);

        $periodStarts = $interval->previousPeriods($periods, $fromDate);
        $results = [];

        foreach ($periodStarts as $periodStart) {
            $periodEnd = $interval->periodEnd($periodStart);

            // Clear cache for this period
            Cache::store(config('counter.store'))
                ->forget(static::redisKey($owner, $key, $interval, $periodStart));

            // Count outside the transaction - see recount() for rationale.
            $count = $countCallback($periodStart, $periodEnd);

            DB::transaction(function () use ($owner, $key, $count, $interval, $periodStart) {
                ModelCounter::setValue($owner, $key, $count, $interval, $periodStart);
            });

            $results[$interval->periodKey($periodStart)] = $count;
        }

        return $results;
    }

    /**
     * Generate the Redis key for a counter.
     */
    public static function redisKey(
        ?Model $owner,
        string $key,
        ?Interval $interval = null,
        ?Carbon $periodStart = null
    ): string {
        // A null owner is a global (ownerless) counter. We still need a
        // parseable, non-empty owner segment on the wire so counter:sync's
        // colon-split survives; reserve the `global:0` token and translate it
        // back to a NULL owner at sync time.
        $morphClass = $owner === null
            ? self::GLOBAL_OWNER_TOKEN
            : strtolower(str_replace('\\', '.', $owner->getMorphClass()));
        $ownerKey = $owner === null ? '0' : $owner->getKey();

        $baseKey = config('counter.prefix')
            .$morphClass.':'
            .$ownerKey.':'
            .$key;

        if ($interval !== null) {
            $periodKey = $interval->periodKey($periodStart);

            return $baseKey.':'.$interval->value.':'.$periodKey;
        }

        return $baseKey;
    }

    /**
     * Get all counters for a given owner (non-interval counters only).
     *
     * @return array<string, int>
     */
    public static function all(?Model $owner): array
    {
        return ModelCounter::allForOwner($owner);
    }

    /**
     * Delete all records for a counter (both cache and database).
     */
    public static function delete(
        ?Model $owner,
        string $key,
        ?Interval $interval = null
    ): int {
        static::validateKey($key);

        // Clear cache
        if ($interval !== null) {
            // For interval-based counters, we need to clear current period cache
            Cache::store(config('counter.store'))
                ->forget(static::redisKey($owner, $key, $interval));
        } else {
            Cache::store(config('counter.store'))
                ->forget(static::redisKey($owner, $key));
        }

        // Delete from database
        return ModelCounter::deleteFor($owner, $key, $interval);
    }

    /*
    |--------------------------------------------------------------------------
    | Global (ownerless) counters
    |--------------------------------------------------------------------------
    |
    | App-wide counters that belong to no model. Stored with NULL owner in the
    | database and the reserved `global:0` token on the Redis wire. These are
    | thin wrappers over the owner-keyed methods with a null owner.
    */

    public static function incrementGlobal(string $key, int $amount = 1, ?Interval $interval = null): void
    {
        static::increment(null, $key, $amount, $interval);
    }

    public static function decrementGlobal(string $key, int $amount = 1, ?Interval $interval = null): void
    {
        static::decrement(null, $key, $amount, $interval);
    }

    public static function getGlobal(string $key, ?Interval $interval = null, ?Carbon $periodStart = null): int
    {
        return static::get(null, $key, $interval, $periodStart);
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<string, int>
     */
    public static function getManyGlobal(array $keys, ?Interval $interval = null): array
    {
        return static::getMany(null, $keys, $interval);
    }

    public static function setGlobal(string $key, int $value, ?Interval $interval = null, ?Carbon $periodStart = null): void
    {
        static::set(null, $key, $value, $interval, $periodStart);
    }

    public static function resetGlobal(string $key, ?Interval $interval = null, ?Carbon $periodStart = null): void
    {
        static::reset(null, $key, $interval, $periodStart);
    }

    /**
     * @return array<string, int>
     */
    public static function historyGlobal(string $key, Interval $interval, int $periods = 12, ?Carbon $fromDate = null): array
    {
        return static::history(null, $key, $interval, $periods, $fromDate);
    }

    public static function sumGlobal(string $key, Interval $interval, ?Carbon $from = null, ?Carbon $to = null): int
    {
        return static::sum(null, $key, $interval, $from, $to);
    }

    public static function deleteGlobal(string $key, ?Interval $interval = null): int
    {
        return static::delete(null, $key, $interval);
    }

    /*
    |--------------------------------------------------------------------------
    | Gauge / snapshot API
    |--------------------------------------------------------------------------
    |
    | A gauge stores an *absolute* value for a period (not an additive delta).
    | snapshot() is the single entry point a daily snapshot job uses to record
    | a cumulative total ("products with MS today") per interval; history()
    | then reads the trend back. It is an intent-revealing alias over set()
    | with an explicit period.
    */

    /**
     * Record an absolute snapshot value for an owner at a specific period.
     */
    public static function snapshot(
        ?Model $owner,
        string $key,
        int $value,
        Interval $interval,
        Carbon|string $periodStart
    ): void {
        $period = $periodStart instanceof Carbon ? $periodStart : Carbon::parse($periodStart);

        static::set($owner, $key, $value, $interval, $period);
    }

    /**
     * Record an absolute global (ownerless) snapshot value for a period.
     */
    public static function snapshotGlobal(
        string $key,
        int $value,
        Interval $interval,
        Carbon|string $periodStart
    ): void {
        static::snapshot(null, $key, $value, $interval, $periodStart);
    }

    /**
     * The most recent snapshot value for an interval-based gauge (the row with
     * the latest period_start). Returns 0 when none exists.
     */
    public static function latest(?Model $owner, string $key, Interval $interval): int
    {
        static::validateKey($key);

        return ModelCounter::latestFor($owner, $key, $interval);
    }

    public static function latestGlobal(string $key, Interval $interval): int
    {
        return static::latest(null, $key, $interval);
    }
}
