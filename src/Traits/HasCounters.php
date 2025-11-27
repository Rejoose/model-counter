<?php

namespace Rejoose\ModelCounter\Traits;

use Rejoose\ModelCounter\Counter;

trait HasCounters
{
    /**
     * Get the current value of a counter.
     */
    public function counter(string $key): int
    {
        return Counter::get($this, $key);
    }

    /**
     * Get multiple counters at once.
     */
    public function counters(array $keys): array
    {
        return Counter::getMany($this, $keys);
    }

    /**
     * Get all counters for this model.
     */
    public function allCounters(): array
    {
        return Counter::all($this);
    }

    /**
     * Increment a counter.
     */
    public function incrementCounter(string $key, int $amount = 1): void
    {
        Counter::increment($this, $key, $amount);
    }

    /**
     * Decrement a counter.
     */
    public function decrementCounter(string $key, int $amount = 1): void
    {
        Counter::decrement($this, $key, $amount);
    }

    /**
     * Reset a counter to zero.
     */
    public function resetCounter(string $key): void
    {
        Counter::reset($this, $key);
    }

    /**
     * Set a counter to a specific value.
     */
    public function setCounter(string $key, int $value): void
    {
        Counter::set($this, $key, $value);
    }
}

