<?php

namespace Rejoose\ModelCounter\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Rejoose\ModelCounter\Counter;
use Rejoose\ModelCounter\Models\ModelCounter;
use Rejoose\ModelCounter\Tests\TestCase;
use Rejoose\ModelCounter\Traits\HasCounters;

class CounterTest extends TestCase
{
    protected TestUser $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test user table
        $this->app['db']->connection()->getSchemaBuilder()->create('test_users', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        $this->user = TestUser::create(['name' => 'Test User']);
    }

    public function test_can_increment_counter(): void
    {
        Counter::increment($this->user, 'downloads');

        $cached = Cache::store('redis')->get(
            Counter::redisKey($this->user, 'downloads')
        );

        $this->assertEquals(1, $cached);
    }

    public function test_can_increment_by_custom_amount(): void
    {
        Counter::increment($this->user, 'downloads', 5);

        $cached = Cache::store('redis')->get(
            Counter::redisKey($this->user, 'downloads')
        );

        $this->assertEquals(5, $cached);
    }

    public function test_can_decrement_counter(): void
    {
        Counter::increment($this->user, 'credits', 10);
        Counter::decrement($this->user, 'credits', 3);

        $cached = Cache::store('redis')->get(
            Counter::redisKey($this->user, 'credits')
        );

        $this->assertEquals(7, $cached);
    }

    public function test_can_get_counter_value(): void
    {
        Counter::increment($this->user, 'downloads', 5);

        $value = Counter::get($this->user, 'downloads');

        $this->assertEquals(5, $value);
    }

    public function test_can_get_counter_with_db_baseline(): void
    {
        // Set database baseline
        ModelCounter::setValue($this->user, 'downloads', 100);

        // Add cached increment
        Counter::increment($this->user, 'downloads', 5);

        // Should return sum
        $value = Counter::get($this->user, 'downloads');

        $this->assertEquals(105, $value);
    }

    public function test_can_reset_counter(): void
    {
        Counter::increment($this->user, 'downloads', 10);
        Counter::reset($this->user, 'downloads');

        $value = Counter::get($this->user, 'downloads');

        $this->assertEquals(0, $value);
    }

    public function test_can_set_counter_to_specific_value(): void
    {
        Counter::set($this->user, 'downloads', 1000);

        $value = Counter::get($this->user, 'downloads');

        $this->assertEquals(1000, $value);
    }

    public function test_trait_methods_work(): void
    {
        $this->user->incrementCounter('downloads', 5);
        $this->user->incrementCounter('views', 10);

        $this->assertEquals(5, $this->user->counter('downloads'));
        $this->assertEquals(10, $this->user->counter('views'));

        $counters = $this->user->counters(['downloads', 'views']);
        $this->assertEquals(['downloads' => 5, 'views' => 10], $counters);
    }

    public function test_can_get_all_counters(): void
    {
        ModelCounter::setValue($this->user, 'downloads', 100);
        ModelCounter::setValue($this->user, 'views', 200);
        ModelCounter::setValue($this->user, 'likes', 50);

        $all = $this->user->allCounters();

        $this->assertEquals([
            'downloads' => 100,
            'views' => 200,
            'likes' => 50,
        ], $all);
    }

    protected function tearDown(): void
    {
        // Clear Redis after each test
        Cache::store('redis')->flush();

        parent::tearDown();
    }
}

class TestUser extends Model
{
    use HasCounters;

    protected $table = 'test_users';
    protected $guarded = [];
}

