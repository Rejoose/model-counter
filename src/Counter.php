<?php

namespace Rejoose\ModelCounter;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Rejoose\ModelCounter\Models\ModelCounter;

class Counter
{
    /**
     * Increment a counter for the given owner and key.
     *
     * This operation is extremely fast as it only touches Redis
     * using atomic increment operations.
     */
    public static function increment(Model $owner, string $key, int $amount = 1): void
    {
        Cache::store(config('counter.store'))
            ->increment(static::redisKey($owner, $key), $amount);
    }

    /**
     * Decrement a counter for the given owner and key.
     */
    public static function decrement(Model $owner, string $key, int $amount = 1): void
    {
        Cache::store(config('counter.store'))
            ->decrement(static::redisKey($owner, $key), $amount);
    }

    /**
     * Get the current count for the given owner and key.
     *
     * Returns the database baseline plus any cached increments
     * that haven't been synced yet.
     */
    public static function get(Model $owner, string $key): int
    {
        $cacheValue = Cache::store(config('counter.store'))
            ->get(static::redisKey($owner, $key), 0);

        $dbValue = ModelCounter::valueFor($owner, $key);

        return $dbValue + $cacheValue;
    }

    /**
     * Get multiple counters for an owner at once.
     *
     * @param Model $owner
     * @param array $keys
     * @return array
     */
    public static function getMany(Model $owner, array $keys): array
    {
        $results = [];

        foreach ($keys as $key) {
            $results[$key] = static::get($owner, $key);
        }

        return $results;
    }

    /**
     * Reset a counter to zero (both cache and database).
     */
    public static function reset(Model $owner, string $key): void
    {
        // Clear cache
        Cache::store(config('counter.store'))
            ->forget(static::redisKey($owner, $key));

        // Reset database
        ModelCounter::resetValue($owner, $key);
    }

    /**
     * Set a counter to a specific value.
     */
    public static function set(Model $owner, string $key, int $value): void
    {
        // Clear cache first
        Cache::store(config('counter.store'))
            ->forget(static::redisKey($owner, $key));

        // Set database value
        ModelCounter::setValue($owner, $key, $value);
    }

    /**
     * Generate the Redis key for a counter.
     */
    public static function redisKey(Model $owner, string $key): string
    {
        return config('counter.prefix')
            . strtolower(class_basename($owner)) . ':'
            . $owner->getKey() . ':'
            . $key;
    }

    /**
     * Get all counters for a given owner.
     *
     * @param Model $owner
     * @return array
     */
    public static function all(Model $owner): array
    {
        return ModelCounter::allForOwner($owner);
    }
}

