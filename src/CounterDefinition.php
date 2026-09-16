<?php

namespace Rejoose\ModelCounter;

use BackedEnum;
use Carbon\Carbon;
use Closure;
use Rejoose\ModelCounter\Enums\CounterVerifyMode;
use Rejoose\ModelCounter\Enums\Interval;

class CounterDefinition
{
    private function __construct(
        public readonly string $key,
        public ?Interval $interval = null,
        public ?Closure $recount = null,
        public CounterVerifyMode $verifyMode = CounterVerifyMode::Equal,
        public bool $gauge = false,
    ) {}

    public static function make(string|BackedEnum $key): self
    {
        return new self(self::normalizeKey($key));
    }

    public function interval(?Interval $interval): self
    {
        $this->interval = $interval;

        return $this;
    }

    public function verifyMode(CounterVerifyMode $mode): self
    {
        $this->verifyMode = $mode;

        return $this;
    }

    /**
     * Declare this counter a gauge: each period stores an *absolute* value
     * (e.g. "all-time distinct clients as of that day"), written by a snapshot
     * job via Counter::snapshot() / Counter::bulkSet(), never an additive delta.
     *
     * A gauge's recount closure still receives (Carbon $start, Carbon $end) and
     * must return the value as of $end. Because each row is already the full
     * value, recountAllCounters() recounts a gauge once (the period at the end
     * of the range) instead of once per period, and verify compares the latest
     * stored snapshot at or before the range end against the source instead of
     * summing every period.
     *
     * Only meaningful together with interval(); a gauge without an interval
     * throws when recounted or verified.
     */
    public function gauge(bool $gauge = true): self
    {
        $this->gauge = $gauge;

        return $this;
    }

    public function isGauge(): bool
    {
        return $this->gauge;
    }

    /**
     * Whether this counter can be safely recomputed from its source. Cumulative
     * event counters (CounterVerifyMode::AtLeast) cannot — recounting from
     * `updated_at` would lose history of intermediate updates.
     */
    public function isRecountable(): bool
    {
        return $this->verifyMode === CounterVerifyMode::Equal;
    }

    /**
     * Provide the source-of-truth count.
     *
     * For non-interval counters, the closure receives no arguments and returns the total.
     * For interval counters, it receives (Carbon $start, Carbon $end) and returns the
     * count for that period.
     *
     * @param  Closure(): int|Closure(Carbon, Carbon): int  $callback
     */
    public function recountUsing(Closure $callback): self
    {
        $this->recount = $callback;

        return $this;
    }

    /**
     * Run the recount closure for a single period (or globally if non-interval).
     */
    public function runRecount(?Carbon $start = null, ?Carbon $end = null): int
    {
        if ($this->recount === null) {
            throw new \LogicException("No recount closure defined for counter '{$this->key}'.");
        }

        if ($this->interval === null) {
            return ($this->recount)();
        }

        return ($this->recount)($start, $end);
    }

    public static function normalizeKey(string|BackedEnum $key): string
    {
        return $key instanceof BackedEnum ? (string) $key->value : $key;
    }
}
