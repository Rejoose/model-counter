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
        Model $owner,
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
        Model $owner,
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
        Model $owner,
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
    public static function getMany(Model $owner, array $keys, ?Interval $interval = null): array
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
    public static function incrementMany(Model $owner, array $counters, ?Interval $interval = null): void
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
    public static function decrementMany(Model $owner, array $counters, ?Interval $interval = null): void
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
        Model $owner,
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
     * Get sum across all periods for an interval-based counter.
     */
    public static function sum(Model $owner, string $key, Interval $interval): int
    {
        static::validateKey($key);

        $dbSum = ModelCounter::sumForInterval($owner, $key, $interval);

        // In direct mode, all values are in the database
        if (config('counter.direct', false)) {
            return $dbSum;
        }

        // Add current period's cached value
        $cacheValue = Cache::store(config('counter.store'))
            ->get(static::redisKey($owner, $key, $interval), 0);

        return $dbSum + $cacheValue;
    }

    /**
     * Reset a counter to zero (both cache and database).
     */
    public static function reset(
        Model $owner,
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
        Model $owner,
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
        Model $owner,
        string $key,
        Closure $countCallback,
        ?Interval $interval = null,
        ?Carbon $periodStart = null
    ): int {
        static::validateKey($key);

        // Clear cache first to prevent stale increments being added
        Cache::store(config('counter.store'))
            ->forget(static::redisKey($owner, $key, $interval, $periodStart));

        // Execute the count within a transaction for safety
        return DB::transaction(function () use ($owner, $key, $countCallback, $interval, $periodStart) {
            $count = $countCallback();

            ModelCounter::setValue($owner, $key, $count, $interval, $periodStart);

            return $count;
        });
    }

    /**
     * Recount multiple periods for an interval-based counter.
     *
     * @param  Closure(Carbon $periodStart, Carbon $periodEnd): int  $countCallback
     * @return array<string, int> Period key => count
     */
    public static function recountPeriods(
        Model $owner,
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

            $count = DB::transaction(function () use ($owner, $key, $countCallback, $interval, $periodStart, $periodEnd) {
                $count = $countCallback($periodStart, $periodEnd);
                ModelCounter::setValue($owner, $key, $count, $interval, $periodStart);

                return $count;
            });

            $results[$interval->periodKey($periodStart)] = $count;
        }

        return $results;
    }

    /**
     * Generate the Redis key for a counter.
     */
    public static function redisKey(
        Model $owner,
        string $key,
        ?Interval $interval = null,
        ?Carbon $periodStart = null
    ): string {
        $morphClass = strtolower(str_replace('\\', '.', $owner->getMorphClass()));

        $baseKey = config('counter.prefix')
            .$morphClass.':'
            .$owner->getKey().':'
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
    public static function all(Model $owner): array
    {
        return ModelCounter::allForOwner($owner);
    }

    /**
     * Delete all records for a counter (both cache and database).
     */
    public static function delete(
        Model $owner,
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
}
