<?php

namespace Rejoose\ModelCounter\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Rejoose\ModelCounter\Console\SyncCounters;
use Rejoose\ModelCounter\Counter;
use Rejoose\ModelCounter\Enums\Interval;
use Rejoose\ModelCounter\Models\ModelCounter;
use Rejoose\ModelCounter\Tests\TestCase;
use Rejoose\ModelCounter\Traits\HasCounters;

class SyncCountersTest extends TestCase
{
    /**
     * The command's atomic drain-and-reclaim script, reused verbatim so a test
     * can drive the exact concurrency ordering the command can't be interrupted
     * at.
     *
     * NOTE: these Redis-backed tests still simulate concurrency in a single
     * process — a true multi-process race between increment and sync is not
     * covered here.
     */
    private const RECLAIM_SCRIPT = SyncCounters::RECLAIM_SCRIPT;

    protected SyncTestUser $user;

    protected function setUp(): void
    {
        parent::setUp();

        if (($reason = $this->redisUnavailableReason()) !== null) {
            $this->markTestSkipped($reason);
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
        // the counter here: the reclaim script should only subtract what we
        // actually read and, because a residual remains, must NOT delete the
        // key.
        $redisKey = Counter::redisKey($this->user, 'views');
        $fullKey = 'testprefix_cache_'.$redisKey;

        // Read what the command will read.
        $value = (int) Redis::connection('default')->get($fullKey);
        $this->assertSame(10, $value);

        // Concurrent writer bumps the key.
        Redis::connection('default')->incrby($fullKey, 7);

        // Now simulate what the command does: upsert the delta we read, then
        // run the atomic drain-and-reclaim (DECRBY by that amount only, DEL if
        // it hit exactly zero). Uses Laravel's normalised eval signature
        // ($script, $numkeys, ...$args), which is identical for phpredis and
        // Predis.
        ModelCounter::addDeltaRaw(
            $this->user->getMorphClass(),
            $this->user->getKey(),
            'views',
            $value
        );
        Redis::connection('default')->eval(self::RECLAIM_SCRIPT, 1, $fullKey, $value);

        $this->assertSame(10, ModelCounter::valueFor($this->user, 'views'));
        $this->assertSame(7, (int) Redis::connection('default')->get($fullKey));
        // Residual delta left the key alive rather than reclaiming it.
        $this->assertSame(1, (int) Redis::connection('default')->exists($fullKey));
        $this->assertSame(17, Counter::get($this->user, 'views'));
    }

    public function test_sync_reclaims_keys_that_drain_to_zero(): void
    {
        // Without the DEL-if-zero reclaim, a fully-synced key lingers at 0
        // forever and every subsequent per-minute SCAN grows unbounded.
        Counter::increment($this->user, 'clicks', 4);

        $fullKey = 'testprefix_cache_'.Counter::redisKey($this->user, 'clicks');
        $this->assertSame(1, (int) Redis::connection('default')->exists($fullKey));

        $exit = Artisan::call('counter:sync');
        $this->assertSame(0, $exit, Artisan::output());

        $this->assertSame(4, ModelCounter::valueFor($this->user, 'clicks'));
        // Fully drained → key removed entirely, not left lingering at 0.
        $this->assertSame(0, (int) Redis::connection('default')->exists($fullKey));
    }

    public function test_get_many_reads_batched_redis_deltas(): void
    {
        // Regression: getMany() built prefix-less keys and handed them to a raw
        // MGET, which read the wrong keys and returned all zeros. This exercises
        // the real Redis batch read (DB baseline + Redis delta) end to end.
        Counter::increment($this->user, 'a', 3);
        Counter::increment($this->user, 'b', 5);

        // 'c' has only a DB baseline (no Redis delta); 'd' has nothing at all.
        ModelCounter::addDeltaRaw($this->user->getMorphClass(), $this->user->getKey(), 'c', 2);

        $result = Counter::getMany($this->user, ['a', 'b', 'c', 'd']);

        $this->assertSame(3, $result['a']);
        $this->assertSame(5, $result['b']);
        $this->assertSame(2, $result['c']);
        $this->assertSame(0, $result['d']);
    }

    public function test_bulk_writers_pipeline_deltas_to_redis(): void
    {
        // Exercises the pipelined Redis path (RedisStore connection) rather
        // than the per-key fallback used by the array store.
        Counter::incrementMany($this->user, ['likes' => 4, 'shares' => 9]);
        Counter::decrementMany($this->user, ['likes' => 1]);

        $this->assertSame(3, Counter::get($this->user, 'likes'));
        $this->assertSame(9, Counter::get($this->user, 'shares'));

        // The written keys carry the store prefix and survive a full sync.
        $exit = Artisan::call('counter:sync');
        $this->assertSame(0, $exit, Artisan::output());

        $this->assertSame(3, ModelCounter::valueFor($this->user, 'likes'));
        $this->assertSame(9, ModelCounter::valueFor($this->user, 'shares'));
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
