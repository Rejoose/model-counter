<?php

namespace Rejoose\ModelCounter\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Rejoose\ModelCounter\Counter;
use Rejoose\ModelCounter\Enums\Interval;
use Rejoose\ModelCounter\Models\ModelCounter;
use Rejoose\ModelCounter\Tests\TestCase;
use Rejoose\ModelCounter\Traits\HasCounters;

/**
 * Covers ModelCounter::bulkSetValue() and the high-level Counter::bulkSet()
 * wrapper. These are the backfill / historical-seed primitives that mirror
 * bulkAddDelta() but with absolute-set rather than additive semantics.
 */
class BulkSetValueTest extends TestCase
{
    protected BulkSetTestUser $alice;

    protected BulkSetTestUser $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = BulkSetTestUser::create(['name' => 'Alice']);
        $this->bob = BulkSetTestUser::create(['name' => 'Bob']);
    }

    protected function tearDown(): void
    {
        BulkSetTestUser::query()->delete();
        ModelCounter::query()->delete();

        parent::tearDown();
    }

    public function test_supports_same_drivers_as_bulk_add_delta(): void
    {
        $this->assertTrue(ModelCounter::supportsBulkSetValue('sqlite'));
        $this->assertTrue(ModelCounter::supportsBulkSetValue('mysql'));
        $this->assertTrue(ModelCounter::supportsBulkSetValue('mariadb'));
        $this->assertTrue(ModelCounter::supportsBulkSetValue('pgsql'));
        $this->assertFalse(ModelCounter::supportsBulkSetValue('sqlsrv'));
    }

    public function test_bulk_set_value_inserts_new_rows(): void
    {
        ModelCounter::bulkSetValue([
            ['owner_type' => $this->alice->getMorphClass(), 'owner_id' => $this->alice->getKey(), 'key' => 'views', 'count' => 5],
            ['owner_type' => $this->alice->getMorphClass(), 'owner_id' => $this->alice->getKey(), 'key' => 'downloads', 'count' => 3],
            ['owner_type' => $this->bob->getMorphClass(), 'owner_id' => $this->bob->getKey(), 'key' => 'views', 'count' => 7],
        ]);

        $this->assertSame(5, ModelCounter::valueFor($this->alice, 'views'));
        $this->assertSame(3, ModelCounter::valueFor($this->alice, 'downloads'));
        $this->assertSame(7, ModelCounter::valueFor($this->bob, 'views'));
    }

    public function test_bulk_set_value_overwrites_existing_rows(): void
    {
        ModelCounter::setValue($this->alice, 'views', 100);

        ModelCounter::bulkSetValue([
            ['owner_type' => $this->alice->getMorphClass(), 'owner_id' => $this->alice->getKey(), 'key' => 'views', 'count' => 5],
        ]);

        // Absolute set: 100 is replaced with 5, not incremented to 105.
        $this->assertSame(5, ModelCounter::valueFor($this->alice, 'views'));
    }

    public function test_bulk_set_value_last_write_wins_for_duplicate_hashes(): void
    {
        // Same logical hash twice — bulkSetValue should pick the last value,
        // not error out with "ON CONFLICT cannot affect row twice".
        ModelCounter::bulkSetValue([
            ['owner_type' => $this->alice->getMorphClass(), 'owner_id' => $this->alice->getKey(), 'key' => 'views', 'count' => 4],
            ['owner_type' => $this->alice->getMorphClass(), 'owner_id' => $this->alice->getKey(), 'key' => 'views', 'count' => 9],
        ]);

        $this->assertSame(9, ModelCounter::valueFor($this->alice, 'views'));
    }

    public function test_bulk_set_value_writes_zero_explicitly(): void
    {
        ModelCounter::setValue($this->alice, 'views', 10);

        ModelCounter::bulkSetValue([
            ['owner_type' => $this->alice->getMorphClass(), 'owner_id' => $this->alice->getKey(), 'key' => 'views', 'count' => 0],
        ]);

        // Unlike bulkAddDelta which skips amount=0, bulkSetValue writes the
        // literal zero — callers wanting to skip should filter beforehand.
        $this->assertSame(0, ModelCounter::valueFor($this->alice, 'views'));
    }

    public function test_bulk_set_value_writes_interval_rows(): void
    {
        $periodStart = Carbon::parse('2024-06-01');

        ModelCounter::bulkSetValue([
            [
                'owner_type' => $this->alice->getMorphClass(),
                'owner_id' => $this->alice->getKey(),
                'key' => 'page_views',
                'count' => 12,
                'interval' => Interval::Month->value,
                'period_start' => $periodStart->toDateString(),
            ],
        ]);

        $this->assertSame(
            12,
            ModelCounter::valueFor($this->alice, 'page_views', Interval::Month, $periodStart)
        );
    }

    public function test_bulk_set_value_handles_many_rows_across_chunks(): void
    {
        $rows = [];
        for ($i = 0; $i < 250; $i++) {
            $rows[] = [
                'owner_type' => $this->alice->getMorphClass(),
                'owner_id' => $this->alice->getKey(),
                'key' => 'k'.$i,
                'count' => $i + 1,
            ];
        }

        ModelCounter::bulkSetValue($rows);

        $this->assertSame(250, ModelCounter::query()->count());
        $this->assertSame(1, ModelCounter::valueFor($this->alice, 'k0'));
        $this->assertSame(199, ModelCounter::valueFor($this->alice, 'k198'));
        $this->assertSame(250, ModelCounter::valueFor($this->alice, 'k249'));
    }

    public function test_counter_bulk_set_accepts_interval_enum_and_carbon(): void
    {
        $written = Counter::bulkSet([
            [
                'owner_type' => $this->alice->getMorphClass(),
                'owner_id' => $this->alice->getKey(),
                'key' => 'sales',
                'count' => 42,
                'interval' => Interval::Day,
                'period_start' => Carbon::parse('2025-03-15'),
            ],
        ]);

        $this->assertSame(1, $written);
        $this->assertSame(
            42,
            ModelCounter::valueFor($this->alice, 'sales', Interval::Day, Carbon::parse('2025-03-15'))
        );
    }

    public function test_counter_bulk_set_skip_zero_drops_zero_rows(): void
    {
        $written = Counter::bulkSet([
            [
                'owner_type' => $this->alice->getMorphClass(),
                'owner_id' => $this->alice->getKey(),
                'key' => 'sales',
                'count' => 5,
                'interval' => Interval::Day,
                'period_start' => '2025-03-15',
            ],
            [
                'owner_type' => $this->alice->getMorphClass(),
                'owner_id' => $this->alice->getKey(),
                'key' => 'sales',
                'count' => 0,
                'interval' => Interval::Day,
                'period_start' => '2025-03-16',
            ],
        ], skipZero: true);

        $this->assertSame(1, $written);
        $this->assertSame(1, ModelCounter::query()->count());
        $this->assertSame(
            5,
            ModelCounter::valueFor($this->alice, 'sales', Interval::Day, Carbon::parse('2025-03-15'))
        );
    }

    public function test_counter_bulk_set_skip_zero_still_invalidates_cache(): void
    {
        // Seed a stale "delta" in the configured cache store for a hash that
        // would later be backfilled as zero. After bulkSet(skipZero: true),
        // get() must not return that stale delta.
        $this->app['config']->set('counter.direct', false);

        $periodStart = Carbon::parse('2025-03-15');
        $store = $this->app['cache']->store(config('counter.store'));
        $cacheKey = config('counter.prefix')
            .strtolower(str_replace('\\', '.', $this->alice->getMorphClass()))
            .':'.$this->alice->getKey().':sales:day:'.$periodStart->format('Y-m-d');

        $store->forever($cacheKey, 7);
        $this->assertSame(7, (int) $store->get($cacheKey, 0));

        Counter::bulkSet([
            [
                'owner_type' => $this->alice->getMorphClass(),
                'owner_id' => $this->alice->getKey(),
                'key' => 'sales',
                'count' => 0,
                'interval' => Interval::Day,
                'period_start' => $periodStart,
            ],
        ], skipZero: true);

        $this->assertSame(0, (int) $store->get($cacheKey, 0));
        $this->assertSame(
            0,
            Counter::get($this->alice, 'sales', Interval::Day, $periodStart)
        );
    }

    public function test_counter_bulk_set_no_rows_is_noop(): void
    {
        $this->assertSame(0, Counter::bulkSet([]));
        $this->assertSame(0, ModelCounter::query()->count());
    }
}

class BulkSetTestUser extends Model
{
    use HasCounters;

    protected $table = 'bulk_set_test_users';

    protected $guarded = [];
}
