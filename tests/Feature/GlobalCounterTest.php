<?php

namespace Rejoose\ModelCounter\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Rejoose\ModelCounter\Counter;
use Rejoose\ModelCounter\Enums\Interval;
use Rejoose\ModelCounter\Events\CounterDecremented;
use Rejoose\ModelCounter\Events\CounterIncremented;
use Rejoose\ModelCounter\Events\CounterReset;
use Rejoose\ModelCounter\Models\ModelCounter;
use Rejoose\ModelCounter\Tests\TestCase;
use Rejoose\ModelCounter\Traits\HasCounters;

class GlobalCounterTest extends TestCase
{
    protected function tearDown(): void
    {
        ModelCounter::query()->delete();

        parent::tearDown();
    }

    public function test_can_increment_and_read_a_global_counter(): void
    {
        Counter::incrementGlobal('products', 5);
        Counter::incrementGlobal('products', 3);

        $this->assertEquals(8, Counter::getGlobal('products'));
    }

    public function test_can_decrement_a_global_counter(): void
    {
        Counter::incrementGlobal('credits', 10);
        Counter::decrementGlobal('credits', 4);

        $this->assertEquals(6, Counter::getGlobal('credits'));
    }

    public function test_global_counter_is_stored_with_null_owner(): void
    {
        Counter::setGlobal('products', 42);

        $row = ModelCounter::query()->where('key', 'products')->first();

        $this->assertNotNull($row);
        $this->assertNull($row->owner_type);
        $this->assertNull($row->owner_id);
        $this->assertEquals(42, $row->count);
    }

    public function test_global_counter_does_not_collide_with_an_owned_counter(): void
    {
        $user = GlobalTestOwner::create(['name' => 'Owner']);

        Counter::increment($user, 'products', 100);
        Counter::incrementGlobal('products', 7);

        $this->assertEquals(100, Counter::get($user, 'products'));
        $this->assertEquals(7, Counter::getGlobal('products'));
    }

    public function test_global_interval_sum_and_history(): void
    {
        Counter::incrementGlobal('added', 4, Interval::Day);

        $today = Carbon::today();
        $this->assertEquals(4, Counter::sumGlobal('added', Interval::Day, $today, $today));

        $history = Counter::historyGlobal('added', Interval::Day, 1);
        $this->assertEquals(4, array_sum($history));
    }

    public function test_bulk_set_supports_global_rows(): void
    {
        $written = Counter::bulkSet([
            ['owner_type' => null, 'owner_id' => null, 'key' => 'tier_ms', 'count' => 500, 'interval' => Interval::Month, 'period_start' => '2026-01-01'],
            ['owner_type' => null, 'owner_id' => null, 'key' => 'tier_ms', 'count' => 600, 'interval' => Interval::Month, 'period_start' => '2026-02-01'],
        ]);

        $this->assertEquals(2, $written);
        $this->assertEquals(500, Counter::getGlobal('tier_ms', Interval::Month, Carbon::parse('2026-01-01')));
        $this->assertEquals(600, Counter::getGlobal('tier_ms', Interval::Month, Carbon::parse('2026-02-01')));
    }

    public function test_bulk_set_rejects_a_mixed_owner_invariant(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // owner_type null but owner_id set — would orphan the row (wire key
        // built from owner_type, DB hash folds in owner_id).
        Counter::bulkSet([
            ['owner_type' => null, 'owner_id' => 5, 'key' => 'tier_ms', 'count' => 1],
        ]);
    }

    public function test_bulk_set_rejects_owner_type_without_owner_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Counter::bulkSet([
            ['owner_type' => 'app', 'owner_id' => null, 'key' => 'tier_ms', 'count' => 1],
        ]);
    }

    public function test_snapshot_writes_an_absolute_value_per_period_and_is_idempotent(): void
    {
        Counter::snapshotGlobal('tier_ms', 1000, Interval::Day, '2026-06-08');
        // Re-running the same day overwrites, not accumulates.
        Counter::snapshotGlobal('tier_ms', 1200, Interval::Day, '2026-06-08');

        $rows = ModelCounter::query()
            ->where('key', 'tier_ms')
            ->where('interval', 'day')
            ->where('period_start', '2026-06-08')
            ->get();

        $this->assertCount(1, $rows);
        $this->assertEquals(1200, $rows->first()->count);
    }

    public function test_latest_returns_the_most_recent_snapshot(): void
    {
        Counter::snapshotGlobal('tier_ms', 100, Interval::Month, '2026-04-01');
        Counter::snapshotGlobal('tier_ms', 300, Interval::Month, '2026-06-01');
        Counter::snapshotGlobal('tier_ms', 200, Interval::Month, '2026-05-01');

        $this->assertEquals(300, Counter::latestGlobal('tier_ms', Interval::Month));
    }

    public function test_owner_snapshot_is_isolated_from_global_snapshot(): void
    {
        $user = GlobalTestOwner::create(['name' => 'Owner']);

        Counter::snapshot($user, 'tier_ms', 50, Interval::Month, '2026-06-01');
        Counter::snapshotGlobal('tier_ms', 999, Interval::Month, '2026-06-01');

        $this->assertEquals(50, Counter::latest($user, 'tier_ms', Interval::Month));
        $this->assertEquals(999, Counter::latestGlobal('tier_ms', Interval::Month));
    }

    public function test_global_counters_dispatch_events_with_a_null_owner_without_throwing(): void
    {
        config(['counter.events' => true]);
        Event::fake();

        // Before the fix these threw a TypeError (event ctor required Model).
        Counter::incrementGlobal('products', 2);
        Counter::decrementGlobal('products', 1);
        Counter::resetGlobal('products');

        Event::assertDispatched(CounterIncremented::class, fn ($e) => $e->owner === null && $e->key === 'products');
        Event::assertDispatched(CounterDecremented::class, fn ($e) => $e->owner === null && $e->key === 'products');
        Event::assertDispatched(CounterReset::class, fn ($e) => $e->owner === null && $e->key === 'products');
    }
}

class GlobalTestOwner extends Model
{
    use HasCounters;

    protected $table = 'global_test_owners';

    protected $guarded = [];
}
