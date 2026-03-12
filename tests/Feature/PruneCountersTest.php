<?php

namespace Rejoose\ModelCounter\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Rejoose\ModelCounter\Enums\Interval;
use Rejoose\ModelCounter\Models\ModelCounter;
use Rejoose\ModelCounter\Tests\TestCase;
use Rejoose\ModelCounter\Traits\HasCounters;

class PruneCountersTest extends TestCase
{
    protected PruneTestUser $user;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->app['db']->connection()->getSchemaBuilder()->hasTable('prune_test_users')) {
            $this->app['db']->connection()->getSchemaBuilder()->create('prune_test_users', function ($table) {
                $table->id();
                $table->string('name');
                $table->timestamps();
            });
        }

        $this->user = PruneTestUser::create(['name' => 'Test User']);
    }

    protected function tearDown(): void
    {
        PruneTestUser::query()->delete();
        ModelCounter::query()->delete();

        parent::tearDown();
    }

    public function test_prune_deletes_old_daily_records(): void
    {
        // Create old daily records
        ModelCounter::setValue($this->user, 'views', 10, Interval::Day, Carbon::now()->subDays(100));
        ModelCounter::setValue($this->user, 'views', 20, Interval::Day, Carbon::now()->subDays(50));
        ModelCounter::setValue($this->user, 'views', 30, Interval::Day, Carbon::now());

        $this->artisan('counter:prune', ['--older-than' => 90, '--interval' => 'day'])
            ->assertExitCode(0);

        // Old record should be deleted, recent ones kept
        $this->assertEquals(2, ModelCounter::where('interval', 'day')->count());
    }

    public function test_prune_dry_run_does_not_delete(): void
    {
        ModelCounter::setValue($this->user, 'views', 10, Interval::Day, Carbon::now()->subDays(100));

        $this->artisan('counter:prune', ['--older-than' => 90, '--interval' => 'day', '--dry-run' => true])
            ->assertExitCode(0);

        // Record should still exist
        $this->assertEquals(1, ModelCounter::where('interval', 'day')->count());
    }

    public function test_prune_uses_retention_config(): void
    {
        config(['counter.retention.day' => 30]);

        ModelCounter::setValue($this->user, 'views', 10, Interval::Day, Carbon::now()->subDays(40));
        ModelCounter::setValue($this->user, 'views', 20, Interval::Day, Carbon::now()->subDays(10));

        $this->artisan('counter:prune', ['--interval' => 'day'])
            ->assertExitCode(0);

        $this->assertEquals(1, ModelCounter::where('interval', 'day')->count());
    }

    public function test_prune_skips_intervals_without_retention(): void
    {
        config(['counter.retention.month' => null]);

        ModelCounter::setValue($this->user, 'views', 10, Interval::Month, Carbon::now()->subMonths(24));

        $this->artisan('counter:prune', ['--interval' => 'month'])
            ->assertExitCode(0);

        // Should not be deleted since retention is null
        $this->assertEquals(1, ModelCounter::where('interval', 'month')->count());
    }

    public function test_prune_invalid_interval_returns_failure(): void
    {
        $this->artisan('counter:prune', ['--interval' => 'invalid'])
            ->assertExitCode(1);
    }
}

class PruneTestUser extends Model
{
    use HasCounters;

    protected $table = 'prune_test_users';

    protected $guarded = [];
}
