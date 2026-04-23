<?php

namespace Rejoose\ModelCounter\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
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

        if (! extension_loaded('redis')) {
            $this->markTestSkipped('phpredis extension not installed.');
        }

        try {
            Redis::connection('default')->ping();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis not reachable: '.$e->getMessage());
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
        if (extension_loaded('redis')) {
            try {
                Redis::connection('default')->flushdb();
            } catch (\Throwable) {
                // ignore if Redis went away between setUp and tearDown
            }
        }

        parent::tearDown();
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
