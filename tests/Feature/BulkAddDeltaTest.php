<?php

namespace Rejoose\ModelCounter\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Rejoose\ModelCounter\Enums\Interval;
use Rejoose\ModelCounter\Models\ModelCounter;
use Rejoose\ModelCounter\Tests\TestCase;
use Rejoose\ModelCounter\Traits\HasCounters;

/**
 * Direct exercise of ModelCounter::bulkAddDelta() against the SQLite test
 * connection. The full sync command is covered separately in
 * SyncCountersTest (which requires a live Redis); these assertions just pin
 * the batched UPSERT semantics that the optimised sync command depends on.
 */
class BulkAddDeltaTest extends TestCase
{
    protected BulkTestUser $alice;

    protected BulkTestUser $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = BulkTestUser::create(['name' => 'Alice']);
        $this->bob = BulkTestUser::create(['name' => 'Bob']);
    }

    protected function tearDown(): void
    {
        BulkTestUser::query()->delete();
        ModelCounter::query()->delete();

        parent::tearDown();
    }

    public function test_supports_sqlite_driver(): void
    {
        $this->assertTrue(ModelCounter::supportsBulkAddDelta('sqlite'));
        $this->assertTrue(ModelCounter::supportsBulkAddDelta('mysql'));
        $this->assertTrue(ModelCounter::supportsBulkAddDelta('mariadb'));
        $this->assertTrue(ModelCounter::supportsBulkAddDelta('pgsql'));
        $this->assertFalse(ModelCounter::supportsBulkAddDelta('sqlsrv'));
    }

    public function test_bulk_insert_creates_new_rows(): void
    {
        ModelCounter::bulkAddDelta([
            ['owner_type' => $this->alice->getMorphClass(), 'owner_id' => $this->alice->getKey(), 'key' => 'views', 'amount' => 5],
            ['owner_type' => $this->alice->getMorphClass(), 'owner_id' => $this->alice->getKey(), 'key' => 'downloads', 'amount' => 3],
            ['owner_type' => $this->bob->getMorphClass(), 'owner_id' => $this->bob->getKey(), 'key' => 'views', 'amount' => 7],
        ]);

        $this->assertSame(5, ModelCounter::valueFor($this->alice, 'views'));
        $this->assertSame(3, ModelCounter::valueFor($this->alice, 'downloads'));
        $this->assertSame(7, ModelCounter::valueFor($this->bob, 'views'));
    }

    public function test_bulk_upsert_increments_existing_rows(): void
    {
        ModelCounter::setValue($this->alice, 'views', 10);

        ModelCounter::bulkAddDelta([
            ['owner_type' => $this->alice->getMorphClass(), 'owner_id' => $this->alice->getKey(), 'key' => 'views', 'amount' => 5],
        ]);

        $this->assertSame(15, ModelCounter::valueFor($this->alice, 'views'));
    }

    public function test_bulk_upsert_handles_negative_deltas(): void
    {
        ModelCounter::setValue($this->alice, 'credits', 100);

        ModelCounter::bulkAddDelta([
            ['owner_type' => $this->alice->getMorphClass(), 'owner_id' => $this->alice->getKey(), 'key' => 'credits', 'amount' => -30],
        ]);

        $this->assertSame(70, ModelCounter::valueFor($this->alice, 'credits'));
    }

    public function test_bulk_upsert_aggregates_duplicates_in_input(): void
    {
        // Same logical hash twice in the same call - Postgres/SQLite would
        // throw "ON CONFLICT cannot affect row twice" without pre-aggregation.
        ModelCounter::bulkAddDelta([
            ['owner_type' => $this->alice->getMorphClass(), 'owner_id' => $this->alice->getKey(), 'key' => 'views', 'amount' => 4],
            ['owner_type' => $this->alice->getMorphClass(), 'owner_id' => $this->alice->getKey(), 'key' => 'views', 'amount' => 6],
        ]);

        $this->assertSame(10, ModelCounter::valueFor($this->alice, 'views'));
    }

    public function test_bulk_upsert_skips_rows_that_net_to_zero(): void
    {
        ModelCounter::bulkAddDelta([
            ['owner_type' => $this->alice->getMorphClass(), 'owner_id' => $this->alice->getKey(), 'key' => 'flips', 'amount' => 5],
            ['owner_type' => $this->alice->getMorphClass(), 'owner_id' => $this->alice->getKey(), 'key' => 'flips', 'amount' => -5],
        ]);

        // No row should be created at all - amount netted to zero.
        $this->assertSame(0, ModelCounter::query()->count());
    }

    public function test_bulk_upsert_writes_interval_rows(): void
    {
        $periodStart = Carbon::parse('2024-06-01');

        ModelCounter::bulkAddDelta([
            [
                'owner_type' => $this->alice->getMorphClass(),
                'owner_id' => $this->alice->getKey(),
                'key' => 'page_views',
                'amount' => 12,
                'interval' => Interval::Month->value,
                'period_start' => $periodStart->toDateString(),
            ],
        ]);

        $this->assertSame(
            12,
            ModelCounter::valueFor($this->alice, 'page_views', Interval::Month, $periodStart)
        );
    }

    public function test_bulk_upsert_handles_many_rows_across_chunks(): void
    {
        // The bulk path chunks at 200 rows per statement. Push past that to
        // make sure chunk handoff doesn't drop or re-process anything.
        $rows = [];
        for ($i = 0; $i < 250; $i++) {
            $rows[] = [
                'owner_type' => $this->alice->getMorphClass(),
                'owner_id' => $this->alice->getKey(),
                'key' => 'k'.$i,
                'amount' => $i + 1,
            ];
        }

        ModelCounter::bulkAddDelta($rows);

        $this->assertSame(250, ModelCounter::query()->count());
        $this->assertSame(1, ModelCounter::valueFor($this->alice, 'k0'));
        $this->assertSame(199, ModelCounter::valueFor($this->alice, 'k198'));
        $this->assertSame(250, ModelCounter::valueFor($this->alice, 'k249'));
    }
}

class BulkTestUser extends Model
{
    use HasCounters;

    protected $table = 'bulk_test_users';

    protected $guarded = [];
}
