<?php

namespace Rejoose\ModelCounter\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Rejoose\ModelCounter\Counter;
use Rejoose\ModelCounter\Enums\Interval;
use Rejoose\ModelCounter\Models\ModelCounter;
use Rejoose\ModelCounter\Tests\TestCase;
use Rejoose\ModelCounter\Traits\HasCounters;

class SyncCountersTest extends TestCase
{
    protected SyncTestUser $user;

    protected function setUp(): void
    {
        parent::setUp();

        if (($reason = $this->redisUnavailableReason()) !== null) {
            $this->markTestSkipped($reason);
        }

        if (! $this->app['db']->connection()->getSchemaBuilder()->hasTable('sync_test_users')) {
            $this->app['db']->connection()->getSchemaBuilder()->create('sync_test_users', function ($table) {
                $table->id();
                $table->string('name');
                $table->timestamps();
            });
        }

        $this->useRedisCache();
        Redis::connection('default')->flushdb();

        $this->user = SyncTestUser::create(['name' => 'Sync Test']);
    }

    protected function tearDown(): void
    {
        if ($this->redisUnavailableReason() === null) {
            try {
                Redis::connection('default')->flushdb();
            } catch (\Throwable) {
                // ignore if Redis went away between setUp and tearDown
            }
        }

        parent::tearDown();
    }

    /**
     * Why this suite can't run, or null when Redis is usable. Subclasses
     * targeting another client (e.g. Predis) override the client probe.
     */
    protected function redisUnavailableReason(): ?string
    {
        if (! extension_loaded('redis')) {
            return 'phpredis extension not installed.';
        }

        try {
            Redis::connection('default')->ping();
        } catch (\Throwable $e) {
            return 'Redis not reachable: '.$e->getMessage();
        }

        return null;
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('database.redis.default', [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => (int) env('REDIS_PORT', 6379),
            'database' => 0,
        ]);

        $app['config']->set('cache.stores.redis', [
            'driver' => 'redis',
            'connection' => 'default',
            'prefix' => 'testprefix_cache_',
        ]);
    }

    public function test_sync_moves_cached_increments_to_database(): void
    {
        Counter::increment($this->user, 'downloads', 3);
        Counter::increment($this->user, 'downloads', 2);

        $this->assertEquals(5, Counter::get($this->user, 'downloads'));
        $this->assertEquals(0, ModelCounter::valueFor($this->user, 'downloads'));

        $exit = Artisan::call('counter:sync');
        $this->assertSame(0, $exit, Artisan::output());

        $this->assertEquals(5, ModelCounter::valueFor($this->user, 'downloads'));

        // Redis side should be drained.
        $this->assertSame(
            0,
            (int) Cache::store('redis')->get(Counter::redisKey($this->user, 'downloads'), 0)
        );
    }

    public function test_sync_moves_global_cached_increments_to_database_with_null_owner(): void
    {
        Counter::incrementGlobal('products', 4, Interval::Day);
        Counter::incrementGlobal('products', 6, Interval::Day);

        // Reads sum DB (0) + Redis (10) before sync.
        $today = Carbon::today();
        $this->assertEquals(10, Counter::getGlobal('products', Interval::Day));
        $this->assertEquals(0, ModelCounter::valueFor(null, 'products', Interval::Day, $today));

        $exit = Artisan::call('counter:sync');
        $this->assertSame(0, $exit, Artisan::output());

        // Flushed to a NULL-owner row.
        $row = ModelCounter::query()->where('key', 'products')->where('interval', 'day')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->owner_type);
        $this->assertNull($row->owner_id);
        $this->assertEquals(10, $row->count);

        // Redis side drained; subsequent read comes purely from the DB.
        $this->assertEquals(10, Counter::getGlobal('products', Interval::Day));
        $this->assertSame(
            0,
            (int) Cache::store('redis')->get(Counter::redisKey(null, 'products', Interval::Day), 0)
        );
    }

    public function test_sync_does_not_treat_a_global_aliased_owned_counter_as_ownerless(): void
    {
        // An app could legitimately register a morph alias literally named
        // "global" for a real model. Its keys are global:<real-id>:... and must
        // stay owned — only the reserved global:0 is the ownerless counter.
        Relation::morphMap(['global' => SyncTestUser::class]);

        try {
            $this->assertSame(1, (int) $this->user->getKey()); // non-zero id

            Counter::increment($this->user, 'downloads', 5); // → global:1:downloads
            Counter::incrementGlobal('downloads', 9);        // → global:0:downloads

            $key = Counter::redisKey($this->user, 'downloads');
            $this->assertStringContainsString(':global:1:', $key);

            $exit = Artisan::call('counter:sync');
            $this->assertSame(0, $exit, Artisan::output());

            // Owned row preserved with its real owner.
            $owned = ModelCounter::query()->where('owner_type', 'global')->where('owner_id', 1)->where('key', 'downloads')->first();
            $this->assertNotNull($owned);
            $this->assertEquals(5, $owned->count);

            // Separate ownerless row for the true global counter.
            $global = ModelCounter::query()->whereNull('owner_type')->whereNull('owner_id')->where('key', 'downloads')->first();
            $this->assertNotNull($global);
            $this->assertEquals(9, $global->count);
        } finally {
            Relation::morphMap([], false);
        }
    }

    public function test_sync_preserves_increments_that_arrive_mid_flight(): void
    {
        Counter::increment($this->user, 'views', 10);

        // Simulate a concurrent write between our GET and DECRBY by bumping
        // the counter here: DECRBY should only subtract what we actually read.
        $redisKey = Counter::redisKey($this->user, 'views');
        $fullKey = 'testprefix_cache_'.$redisKey;

        // Read what the command will read.
        $value = (int) Redis::connection('default')->get($fullKey);
        $this->assertSame(10, $value);

        // Concurrent writer bumps the key.
        Redis::connection('default')->incrby($fullKey, 7);

        // Now simulate what the command does: upsert the delta we read, then
        // DECRBY by that amount only.
        ModelCounter::addDeltaRaw(
            $this->user->getMorphClass(),
            $this->user->getKey(),
            'views',
            $value
        );
        Redis::connection('default')->decrby($fullKey, $value);

        $this->assertSame(10, ModelCounter::valueFor($this->user, 'views'));
        $this->assertSame(7, (int) Redis::connection('default')->get($fullKey));
        $this->assertSame(17, Counter::get($this->user, 'views'));
    }

    public function test_sync_syncs_interval_counters(): void
    {
        Counter::increment($this->user, 'page_views', 4, Interval::Day);

        Artisan::call('counter:sync');

        $this->assertEquals(4, ModelCounter::valueFor($this->user, 'page_views', Interval::Day));
    }

    public function test_sync_flushes_net_negative_deltas(): void
    {
        // Net-negative pending deltas are legitimate: a Day bucket that only
        // saw deletions of items created on earlier days. Regression: with an
        // UNSIGNED count column on MySQL the sync failed on every such key
        // (insert of a negative row, then `count = count + (negative)`
        // arithmetic), exited 1, and never drained the key from Redis.

        // New row inserted directly with a negative count.
        Counter::decrement($this->user, 'tags', 7, Interval::Day);

        $exit = Artisan::call('counter:sync');
        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame(-7, ModelCounter::valueFor($this->user, 'tags', Interval::Day));

        // Existing positive row pushed below zero by the upsert arithmetic.
        Counter::incrementGlobal('tags', 5, Interval::Day);

        $exit = Artisan::call('counter:sync');
        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame(5, Counter::getGlobal('tags', Interval::Day));

        Counter::decrementGlobal('tags', 12, Interval::Day);

        $exit = Artisan::call('counter:sync');
        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame(-7, Counter::getGlobal('tags', Interval::Day));

        // Redis fully drained — nothing left to double-apply.
        $exit = Artisan::call('counter:sync');
        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame(-7, Counter::getGlobal('tags', Interval::Day));
    }

    public function test_sync_is_noop_on_array_store(): void
    {
        config(['counter.store' => 'array']);

        $exit = Artisan::call('counter:sync');

        // 'array' is a documented no-op case so it exits 0.
        $this->assertSame(0, $exit);
    }

    public function test_sync_resolves_classes_via_morph_map(): void
    {
        Relation::morphMap([
            'sync_alias' => SyncTestUser::class,
        ]);

        try {
            // With the morph map set, Counter writes the alias into the key.
            Counter::increment($this->user, 'custom', 2);

            $key = Counter::redisKey($this->user, 'custom');
            $this->assertStringContainsString(':sync_alias:', $key);

            $exit = Artisan::call('counter:sync');
            $this->assertSame(0, $exit, Artisan::output());

            $this->assertSame(2, ModelCounter::valueFor($this->user, 'custom'));
        } finally {
            Relation::morphMap([], false);
        }
    }
}

class SyncTestUser extends Model
{
    use HasCounters;

    protected $table = 'sync_test_users';

    protected $guarded = [];
}
