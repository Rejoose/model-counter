<?php

namespace Rejoose\ModelCounter\Traits;

use Carbon\Carbon;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Rejoose\ModelCounter\Counter;
use Rejoose\ModelCounter\Enums\Interval;

trait HasCounters
{
    /**
     * Get the current value of a counter.
     */
    public function counter(string $key, ?Interval $interval = null, ?Carbon $periodStart = null): int
    {
        return Counter::get($this, $key, $interval, $periodStart);
    }

    /**
     * Get multiple counters at once.
     *
     * @return array<string, int>
     */
    public function counters(array $keys, ?Interval $interval = null): array
    {
        return Counter::getMany($this, $keys, $interval);
    }

    /**
     * Get all counters for this model (non-interval counters only).
     *
     * @return array<string, int>
     */
    public function allCounters(): array
    {
        return Counter::all($this);
    }

    /**
     * Increment a counter.
     */
    public function incrementCounter(string $key, int $amount = 1, ?Interval $interval = null): void
    {
        Counter::increment($this, $key, $amount, $interval);
    }

    /**
     * Decrement a counter.
     */
    public function decrementCounter(string $key, int $amount = 1, ?Interval $interval = null): void
    {
        Counter::decrement($this, $key, $amount, $interval);
    }

    /**
     * Increment multiple counters at once.
     *
     * @param  array<string, int>  $counters  Counter key => amount pairs
     */
    public function incrementCounters(array $counters, ?Interval $interval = null): void
    {
        Counter::incrementMany($this, $counters, $interval);
    }

    /**
     * Decrement multiple counters at once.
     *
     * @param  array<string, int>  $counters  Counter key => amount pairs
     */
    public function decrementCounters(array $counters, ?Interval $interval = null): void
    {
        Counter::decrementMany($this, $counters, $interval);
    }

    /**
     * Reset a counter to zero.
     */
    public function resetCounter(string $key, ?Interval $interval = null, ?Carbon $periodStart = null): void
    {
        Counter::reset($this, $key, $interval, $periodStart);
    }

    /**
     * Set a counter to a specific value.
     */
    public function setCounter(string $key, int $value, ?Interval $interval = null, ?Carbon $periodStart = null): void
    {
        Counter::set($this, $key, $value, $interval, $periodStart);
    }

    /**
     * Get counter history for multiple periods.
     *
     * @return array<string, int> Period key => count
     */
    public function counterHistory(
        string $key,
        Interval $interval,
        int $periods = 12,
        ?Carbon $fromDate = null
    ): array {
        return Counter::history($this, $key, $interval, $periods, $fromDate);
    }

    /**
     * Get sum across all (or a date-bounded range of) periods for an interval-based counter.
     */
    public function counterSum(string $key, Interval $interval, ?Carbon $from = null, ?Carbon $to = null): int
    {
        return Counter::sum($this, $key, $interval, $from, $to);
    }

    /**
     * Recount a counter using the provided callback.
     *
     * @param  Closure(): int  $countCallback
     */
    public function recountCounter(
        string $key,
        Closure $countCallback,
        ?Interval $interval = null,
        ?Carbon $periodStart = null
    ): int {
        return Counter::recount($this, $key, $countCallback, $interval, $periodStart);
    }

    /**
     * Recount multiple periods for an interval-based counter.
     *
     * @param  Closure(Carbon $periodStart, Carbon $periodEnd): int  $countCallback
     * @return array<string, int>
     */
    public function recountCounterPeriods(
        string $key,
        Interval $interval,
        Closure $countCallback,
        int $periods = 12,
        ?Carbon $fromDate = null
    ): array {
        return Counter::recountPeriods($this, $key, $interval, $countCallback, $periods, $fromDate);
    }

    /**
     * Delete all records for a counter.
     */
    public function deleteCounter(string $key, ?Interval $interval = null): int
    {
        return Counter::delete($this, $key, $interval);
    }

    /**
     * Scope the query to include a counter value.
     */
    public function scopeWithCounter(Builder $query, string $key): void
    {
        $alias = 'counter_'.preg_replace('/[^a-zA-Z0-9_]/', '_', $key);
        $selectAlias = preg_replace('/[^a-zA-Z0-9_]/', '_', $key).'_count';

        $counterTable = config('counter.table_name', 'model_counters');

        $query->leftJoin("{$counterTable} as {$alias}", function ($join) use ($key, $alias) {
            $join->on("{$alias}.owner_id", '=', $this->getQualifiedKeyName())
                ->where("{$alias}.owner_type", '=', $this->getMorphClass())
                ->where("{$alias}.key", '=', $key)
                ->whereNull("{$alias}.interval")
                ->whereNull("{$alias}.period_start");
        })->addSelect([
            $this->getTable().'.*',
            "{$alias}.count as {$selectAlias}",
        ]);
    }

    /**
     * Scope the query to order by a counter value.
     */
    public function scopeOrderByCounter(Builder $query, string $key, string $direction = 'desc'): void
    {
        $alias = 'counter_'.preg_replace('/[^a-zA-Z0-9_]/', '_', $key);

        $counterTable = config('counter.table_name', 'model_counters');

        $joins = $query->getQuery()->joins ?? [];
        $alreadyJoined = false;
        foreach ($joins as $join) {
            if (isset($join->table) && $join->table === "{$counterTable} as {$alias}") {
                $alreadyJoined = true;
                break;
            }
        }

        if (! $alreadyJoined) {
            $query->leftJoin("{$counterTable} as {$alias}", function ($join) use ($key, $alias) {
                $join->on("{$alias}.owner_id", '=', $this->getQualifiedKeyName())
                    ->where("{$alias}.owner_type", '=', $this->getMorphClass())
                    ->where("{$alias}.key", '=', $key)
                    ->whereNull("{$alias}.interval")
                    ->whereNull("{$alias}.period_start");
            });
        }

        if (is_null($query->getQuery()->columns)) {
            $query->select($this->getTable().'.*');
        }

        $query->orderBy("{$alias}.count", $direction);
    }
}
