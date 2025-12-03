<?php

namespace Rejoose\ModelCounter\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Rejoose\ModelCounter\Counter;
use Rejoose\ModelCounter\Enums\Interval;
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

    // ==================== INTERVAL TESTS ====================

    public function test_can_increment_daily_counter(): void
    {
        Counter::increment($this->user, 'page_views', 1, Interval::Day);

        $cached = Cache::store('redis')->get(
            Counter::redisKey($this->user, 'page_views', Interval::Day)
        );

        $this->assertEquals(1, $cached);
    }

    public function test_can_get_daily_counter_value(): void
    {
        Counter::increment($this->user, 'page_views', 5, Interval::Day);

        $value = Counter::get($this->user, 'page_views', Interval::Day);

        $this->assertEquals(5, $value);
    }

    public function test_can_get_monthly_counter_value(): void
    {
        Counter::increment($this->user, 'downloads', 10, Interval::Month);

        $value = Counter::get($this->user, 'downloads', Interval::Month);

        $this->assertEquals(10, $value);
    }

    public function test_interval_counters_are_separate_from_total_counters(): void
    {
        Counter::increment($this->user, 'downloads', 5); // Total
        Counter::increment($this->user, 'downloads', 3, Interval::Day); // Daily

        $total = Counter::get($this->user, 'downloads');
        $daily = Counter::get($this->user, 'downloads', Interval::Day);

        $this->assertEquals(5, $total);
        $this->assertEquals(3, $daily);
    }

    public function test_different_intervals_are_separate(): void
    {
        Counter::increment($this->user, 'views', 10, Interval::Day);
        Counter::increment($this->user, 'views', 20, Interval::Month);
        Counter::increment($this->user, 'views', 100, Interval::Year);

        $this->assertEquals(10, Counter::get($this->user, 'views', Interval::Day));
        $this->assertEquals(20, Counter::get($this->user, 'views', Interval::Month));
        $this->assertEquals(100, Counter::get($this->user, 'views', Interval::Year));
    }

    public function test_can_set_interval_counter(): void
    {
        Counter::set($this->user, 'views', 500, Interval::Month);

        $value = Counter::get($this->user, 'views', Interval::Month);

        $this->assertEquals(500, $value);
    }

    public function test_can_reset_interval_counter(): void
    {
        Counter::increment($this->user, 'views', 100, Interval::Day);
        Counter::reset($this->user, 'views', Interval::Day);

        $value = Counter::get($this->user, 'views', Interval::Day);

        $this->assertEquals(0, $value);
    }

    public function test_interval_period_start_calculation(): void
    {
        $now = Carbon::parse('2024-06-15 14:30:00');
        Carbon::setTestNow($now);

        $this->assertEquals('2024-06-15', Interval::Day->periodStart()->toDateString());
        $this->assertEquals('2024-06-01', Interval::Month->periodStart()->toDateString());
        $this->assertEquals('2024-01-01', Interval::Year->periodStart()->toDateString());

        Carbon::setTestNow();
    }

    public function test_can_get_counter_history(): void
    {
        Carbon::setTestNow(Carbon::parse('2024-06-15'));

        // Set values for multiple months
        ModelCounter::setValue($this->user, 'downloads', 100, Interval::Month, Carbon::parse('2024-06-01'));
        ModelCounter::setValue($this->user, 'downloads', 80, Interval::Month, Carbon::parse('2024-05-01'));
        ModelCounter::setValue($this->user, 'downloads', 60, Interval::Month, Carbon::parse('2024-04-01'));

        $history = Counter::history($this->user, 'downloads', Interval::Month, 3);

        $this->assertEquals([
            '2024-06' => 100,
            '2024-05' => 80,
            '2024-04' => 60,
        ], $history);

        Carbon::setTestNow();
    }

    public function test_history_includes_cached_current_period(): void
    {
        Carbon::setTestNow(Carbon::parse('2024-06-15'));

        // Set database value
        ModelCounter::setValue($this->user, 'downloads', 100, Interval::Month, Carbon::parse('2024-06-01'));

        // Add cached increment
        Counter::increment($this->user, 'downloads', 5, Interval::Month);

        $history = Counter::history($this->user, 'downloads', Interval::Month, 1);

        $this->assertEquals(['2024-06' => 105], $history);

        Carbon::setTestNow();
    }

    public function test_can_get_sum_across_intervals(): void
    {
        // Set values for multiple months
        ModelCounter::setValue($this->user, 'downloads', 100, Interval::Month, Carbon::parse('2024-06-01'));
        ModelCounter::setValue($this->user, 'downloads', 80, Interval::Month, Carbon::parse('2024-05-01'));
        ModelCounter::setValue($this->user, 'downloads', 60, Interval::Month, Carbon::parse('2024-04-01'));

        $sum = Counter::sum($this->user, 'downloads', Interval::Month);

        $this->assertEquals(240, $sum);
    }

    public function test_trait_interval_methods_work(): void
    {
        $this->user->incrementCounter('views', 10, Interval::Day);
        $this->user->incrementCounter('views', 50, Interval::Month);

        $this->assertEquals(10, $this->user->counter('views', Interval::Day));
        $this->assertEquals(50, $this->user->counter('views', Interval::Month));
    }

    // ==================== RECOUNT TESTS ====================

    public function test_can_recount_counter(): void
    {
        // Set initial value
        Counter::set($this->user, 'articles', 5);

        // Recount using callback
        $newCount = Counter::recount($this->user, 'articles', fn () => 10);

        $this->assertEquals(10, $newCount);
        $this->assertEquals(10, Counter::get($this->user, 'articles'));
    }

    public function test_recount_clears_cache(): void
    {
        // Set database value
        ModelCounter::setValue($this->user, 'articles', 100);

        // Add cached increment
        Counter::increment($this->user, 'articles', 5);

        // Verify cache exists
        $this->assertEquals(5, Cache::store('redis')->get(
            Counter::redisKey($this->user, 'articles')
        ));

        // Recount should clear cache and set new value
        Counter::recount($this->user, 'articles', fn () => 50);

        // Cache should be cleared
        $this->assertNull(Cache::store('redis')->get(
            Counter::redisKey($this->user, 'articles')
        ));

        // Value should be the recounted value
        $this->assertEquals(50, Counter::get($this->user, 'articles'));
    }

    public function test_can_recount_interval_counter(): void
    {
        Counter::set($this->user, 'views', 100, Interval::Day);

        $newCount = Counter::recount($this->user, 'views', fn () => 200, Interval::Day);

        $this->assertEquals(200, $newCount);
        $this->assertEquals(200, Counter::get($this->user, 'views', Interval::Day));
    }

    public function test_trait_recount_method_works(): void
    {
        $this->user->setCounter('posts', 10);

        $newCount = $this->user->recountCounter('posts', fn () => 25);

        $this->assertEquals(25, $newCount);
        $this->assertEquals(25, $this->user->counter('posts'));
    }

    public function test_can_recount_multiple_periods(): void
    {
        Carbon::setTestNow(Carbon::parse('2024-06-15'));

        // Recount 3 months
        $results = Counter::recountPeriods(
            $this->user,
            'downloads',
            Interval::Month,
            fn (Carbon $start, Carbon $end) => (int) $start->format('m') * 10,
            3
        );

        $this->assertEquals([
            '2024-06' => 60,
            '2024-05' => 50,
            '2024-04' => 40,
        ], $results);

        // Verify values are in database
        $this->assertEquals(60, Counter::get(
            $this->user,
            'downloads',
            Interval::Month,
            Carbon::parse('2024-06-01')
        ));

        Carbon::setTestNow();
    }

    // ==================== DELETE TESTS ====================

    public function test_can_delete_counter(): void
    {
        Counter::set($this->user, 'temp', 100);

        $deleted = Counter::delete($this->user, 'temp');

        $this->assertEquals(1, $deleted);
        $this->assertEquals(0, Counter::get($this->user, 'temp'));
    }

    public function test_can_delete_interval_counter(): void
    {
        ModelCounter::setValue($this->user, 'views', 100, Interval::Month, Carbon::parse('2024-06-01'));
        ModelCounter::setValue($this->user, 'views', 80, Interval::Month, Carbon::parse('2024-05-01'));

        $deleted = Counter::delete($this->user, 'views', Interval::Month);

        $this->assertEquals(2, $deleted);
    }

    // ==================== REDIS KEY TESTS ====================

    public function test_redis_key_format_without_interval(): void
    {
        $key = Counter::redisKey($this->user, 'downloads');

        $this->assertStringContainsString('counter:', $key);
        $this->assertStringContainsString('testuser:', $key);
        $this->assertStringContainsString('downloads', $key);
        $this->assertStringNotContainsString('day:', $key);
    }

    public function test_redis_key_format_with_interval(): void
    {
        Carbon::setTestNow(Carbon::parse('2024-06-15'));

        $key = Counter::redisKey($this->user, 'downloads', Interval::Day);

        $this->assertStringContainsString('counter:', $key);
        $this->assertStringContainsString('testuser:', $key);
        $this->assertStringContainsString('downloads', $key);
        $this->assertStringContainsString(':day:', $key);
        $this->assertStringContainsString('2024-06-15', $key);

        Carbon::setTestNow();
    }

    public function test_redis_key_format_with_month_interval(): void
    {
        Carbon::setTestNow(Carbon::parse('2024-06-15'));

        $key = Counter::redisKey($this->user, 'downloads', Interval::Month);

        $this->assertStringContainsString(':month:', $key);
        $this->assertStringContainsString('2024-06', $key);

        Carbon::setTestNow();
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
