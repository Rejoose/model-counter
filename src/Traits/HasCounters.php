<?php

namespace Rejoose\ModelCounter\Traits;

use BackedEnum;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Rejoose\ModelCounter\Contracts\DefinesCounters;
use Rejoose\ModelCounter\Counter;
use Rejoose\ModelCounter\CounterDefinition;
use Rejoose\ModelCounter\Enums\CounterVerifyMode;
use Rejoose\ModelCounter\Enums\Interval;

trait HasCounters
{
    /**
     * Get the current value of a counter.
     */
    public function counter(string|BackedEnum $key, ?Interval $interval = null, ?Carbon $periodStart = null): int
    {
        return Counter::get($this, $this->normalizeCounterKey($key), $interval, $periodStart);
    }

    /**
     * Get multiple counters at once.
     *
     * @param  array<int, string|BackedEnum>  $keys
     * @return array<string, int>
     */
    public function counters(array $keys, ?Interval $interval = null): array
    {
        return Counter::getMany($this, array_map(fn ($k) => $this->normalizeCounterKey($k), $keys), $interval);
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
    public function incrementCounter(string|BackedEnum $key, int $amount = 1, ?Interval $interval = null): void
    {
        Counter::increment($this, $this->normalizeCounterKey($key), $amount, $interval);
    }

    /**
     * Decrement a counter.
     */
    public function decrementCounter(string|BackedEnum $key, int $amount = 1, ?Interval $interval = null): void
    {
        Counter::decrement($this, $this->normalizeCounterKey($key), $amount, $interval);
    }

    /**
     * Increment multiple counters at once.
     *
     * @param  array<string, int>  $counters  Counter key => amount pairs (string keys; pass `MyEnum::Foo->value`)
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
    public function resetCounter(string|BackedEnum $key, ?Interval $interval = null, ?Carbon $periodStart = null): void
    {
        Counter::reset($this, $this->normalizeCounterKey($key), $interval, $periodStart);
    }

    /**
     * Set a counter to a specific value.
     */
    public function setCounter(string|BackedEnum $key, int $value, ?Interval $interval = null, ?Carbon $periodStart = null): void
    {
        Counter::set($this, $this->normalizeCounterKey($key), $value, $interval, $periodStart);
    }

    /**
     * Get counter history for multiple periods.
     *
     * @return array<string, int> Period key => count
     */
    public function counterHistory(
        string|BackedEnum $key,
        Interval $interval,
        int $periods = 12,
        ?Carbon $fromDate = null
    ): array {
        return Counter::history($this, $this->normalizeCounterKey($key), $interval, $periods, $fromDate);
    }

    /**
     * Get sum across all (or a date-bounded range of) periods for an interval-based counter.
     */
    public function counterSum(string|BackedEnum $key, Interval $interval, ?Carbon $from = null, ?Carbon $to = null): int
    {
        return Counter::sum($this, $this->normalizeCounterKey($key), $interval, $from, $to);
    }

    /**
     * Recount a counter using the provided callback.
     *
     * @param  Closure(): int  $countCallback
     */
    public function recountCounter(
        string|BackedEnum $key,
        Closure $countCallback,
        ?Interval $interval = null,
        ?Carbon $periodStart = null
    ): int {
        return Counter::recount($this, $this->normalizeCounterKey($key), $countCallback, $interval, $periodStart);
    }

    /**
     * Recount multiple periods for an interval-based counter.
     *
     * @param  Closure(Carbon $periodStart, Carbon $periodEnd): int  $countCallback
     * @return array<string, int>
     */
    public function recountCounterPeriods(
        string|BackedEnum $key,
        Interval $interval,
        Closure $countCallback,
        int $periods = 12,
        ?Carbon $fromDate = null
    ): array {
        return Counter::recountPeriods($this, $this->normalizeCounterKey($key), $interval, $countCallback, $periods, $fromDate);
    }

    /**
     * Delete all records for a counter.
     */
    public function deleteCounter(string|BackedEnum $key, ?Interval $interval = null): int
    {
        return Counter::delete($this, $this->normalizeCounterKey($key), $interval);
    }

    /**
     * Recount every counter declared in counterDefinitions() using the model's
     * declared source-of-truth closures. For interval counters, recounts every
     * period in [$from, $to]. For non-interval counters, recounts the global value
     * (the date range is ignored).
     *
     * AtLeast-mode definitions are skipped — their source query can't reproduce
     * cumulative event history, so recounting would clobber the live total.
     *
     * @return array<string, int|array<string, int|string>> Counter key => result.
     *                                                      Recounted: int (non-interval) or period map.
     *                                                      Skipped: ['skipped' => true, 'reason' => ...].
     */
    public function recountAllCounters(?Carbon $from = null, ?Carbon $to = null): array
    {
        $this->assertDefinesCounters(__FUNCTION__);

        $results = [];
        foreach ($this->counterDefinitions() as $definition) {
            if (! $definition->isRecountable()) {
                $results[$definition->key] = [
                    'skipped' => true,
                    'reason' => 'verify mode is '.$definition->verifyMode->value.' — counter is cumulative and cannot be recomputed from source',
                ];

                continue;
            }

            $results[$definition->key] = $this->recountFromDefinition($definition, $from, $to);
        }

        return $results;
    }

    /**
     * Compare a stored counter value against the source-of-truth defined on the model
     * without writing anything. For interval counters, drift is reported per period.
     *
     * @return array{
     *   key: string,
     *   interval: ?string,
     *   matches: bool,
     *   stored: int,
     *   actual: int,
     *   periods?: array<string, array{stored: int, actual: int, matches: bool}>
     * }
     */
    public function verifyCounter(string|BackedEnum $key, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $this->assertDefinesCounters(__FUNCTION__);

        $normalized = $this->normalizeCounterKey($key);
        $definitions = $this->counterDefinitions();

        if (! isset($definitions[$normalized])) {
            throw new \InvalidArgumentException("No counter definition for '{$normalized}' on ".static::class.'.');
        }

        return $this->verifyFromDefinition($definitions[$normalized], $from, $to);
    }

    /**
     * Verify every counter declared in counterDefinitions().
     *
     * @return array<string, array<string, mixed>>
     */
    public function verifyAllCounters(?Carbon $from = null, ?Carbon $to = null): array
    {
        $this->assertDefinesCounters(__FUNCTION__);

        $results = [];
        foreach ($this->counterDefinitions() as $definition) {
            $results[$definition->key] = $this->verifyFromDefinition($definition, $from, $to);
        }

        return $results;
    }

    /**
     * Scope the query to include a counter value.
     */
    public function scopeWithCounter(Builder $query, string|BackedEnum $key): void
    {
        $key = $this->normalizeCounterKey($key);
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
    public function scopeOrderByCounter(Builder $query, string|BackedEnum $key, string $direction = 'desc'): void
    {
        $key = $this->normalizeCounterKey($key);
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

    protected function normalizeCounterKey(string|BackedEnum $key): string
    {
        return CounterDefinition::normalizeKey($key);
    }

    private function assertDefinesCounters(string $method): void
    {
        if (! $this instanceof DefinesCounters) {
            throw new \LogicException(static::class.' must implement '.DefinesCounters::class.' to call '.$method.'().');
        }
    }

    /**
     * @return int|array<string, int>
     */
    private function recountFromDefinition(CounterDefinition $definition, ?Carbon $from, ?Carbon $to): int|array
    {
        if ($definition->interval === null) {
            return $this->recountCounter(
                $definition->key,
                fn () => $definition->runRecount(),
            );
        }

        [$periods, $fromDate] = $this->resolveIntervalRange($definition->interval, $from, $to);

        return $this->recountCounterPeriods(
            $definition->key,
            $definition->interval,
            fn (Carbon $start, Carbon $end) => $definition->runRecount($start, $end),
            $periods,
            $fromDate,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function verifyFromDefinition(CounterDefinition $definition, ?Carbon $from, ?Carbon $to): array
    {
        if ($definition->interval === null) {
            $stored = $this->counter($definition->key);
            $actual = $definition->runRecount();

            return [
                'key' => $definition->key,
                'interval' => null,
                'mode' => $definition->verifyMode->value,
                'stored' => $stored,
                'actual' => $actual,
                'matches' => $this->compareCounterValues($stored, $actual, $definition->verifyMode),
            ];
        }

        [$periods, $fromDate] = $this->resolveIntervalRange($definition->interval, $from, $to);
        $periodStarts = $definition->interval->previousPeriods($periods, $fromDate);

        $perPeriod = [];
        $totalStored = 0;
        $totalActual = 0;
        $allMatch = true;

        foreach ($periodStarts as $periodStart) {
            $periodEnd = $definition->interval->periodEnd($periodStart);
            $stored = $this->counter($definition->key, $definition->interval, $periodStart);
            $actual = $definition->runRecount($periodStart, $periodEnd);

            $matches = $this->compareCounterValues($stored, $actual, $definition->verifyMode);
            $perPeriod[$definition->interval->periodKey($periodStart)] = [
                'stored' => $stored,
                'actual' => $actual,
                'matches' => $matches,
            ];

            $totalStored += $stored;
            $totalActual += $actual;
            $allMatch = $allMatch && $matches;
        }

        return [
            'key' => $definition->key,
            'interval' => $definition->interval->value,
            'mode' => $definition->verifyMode->value,
            'stored' => $totalStored,
            'actual' => $totalActual,
            'matches' => $allMatch,
            'periods' => $perPeriod,
        ];
    }

    private function compareCounterValues(int $stored, int $actual, CounterVerifyMode $mode): bool
    {
        return match ($mode) {
            CounterVerifyMode::Equal => $stored === $actual,
            CounterVerifyMode::AtLeast => $stored >= $actual,
        };
    }

    /**
     * Translate (?$from, ?$to) into the (periods, fromDate) shape used by
     * Counter::recountPeriods (which walks $periods backward from $fromDate).
     *
     * @return array{0: int, 1: Carbon}
     */
    private function resolveIntervalRange(Interval $interval, ?Carbon $from, ?Carbon $to): array
    {
        $to = $to ?? now();
        $from = $from ?? $to;

        $periods = max(1, $interval->periodsBetween($from, $to));

        return [$periods, $to];
    }
}
