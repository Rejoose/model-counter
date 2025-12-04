<?php

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Rejoose\ModelCounter\Enums\Interval;
use Rejoose\ModelCounter\Traits\HasCounters;

// Define test models
class RecountIntervalTestUser extends Model
{
    use HasCounters;

    protected $table = 'recount_interval_test_users';

    protected $guarded = [];

    public function logins()
    {
        return $this->hasMany(RecountIntervalTestLogin::class, 'user_id');
    }
}

class RecountIntervalTestLogin extends Model
{
    protected $table = 'recount_interval_test_logins';

    protected $guarded = [];
}

beforeEach(function () {
    // Create test user table
    if (! $this->app['db']->connection()->getSchemaBuilder()->hasTable('recount_interval_test_users')) {
        $this->app['db']->connection()->getSchemaBuilder()->create('recount_interval_test_users', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    // Create test logins table
    if (! $this->app['db']->connection()->getSchemaBuilder()->hasTable('recount_interval_test_logins')) {
        $this->app['db']->connection()->getSchemaBuilder()->create('recount_interval_test_logins', function ($table) {
            $table->id();
            $table->foreignId('user_id');
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
        });
    }

    $this->user = RecountIntervalTestUser::create(['name' => 'Test User']);
});

afterEach(function () {
    RecountIntervalTestUser::query()->delete();
    RecountIntervalTestLogin::query()->delete();
});

test('recount with interval counts only records in that interval', function () {
    // Create logins for today, yesterday, and 2 days ago
    $today = now();
    $yesterday = now()->subDay();
    $twoDaysAgo = now()->subDays(2);

    RecountIntervalTestLogin::create(['user_id' => $this->user->id, 'created_at' => $today]);
    RecountIntervalTestLogin::create(['user_id' => $this->user->id, 'created_at' => $today]); // 2 today
    RecountIntervalTestLogin::create(['user_id' => $this->user->id, 'created_at' => $yesterday]); // 1 yesterday
    RecountIntervalTestLogin::create(['user_id' => $this->user->id, 'created_at' => $twoDaysAgo]); // 1 two days ago

    // Recount for today (default 1 period)
    // This should ONLY count the 2 logins from today
    $this->user->logins()->recount('user_logins', Interval::Day);

    // Check counter for today
    expect($this->user->counter('user_logins', Interval::Day))->toBe(2);
});

test('recount with interval and multiple periods', function () {
    // Create logins for today, yesterday, and 2 days ago
    $today = now();
    $yesterday = now()->subDay();
    $twoDaysAgo = now()->subDays(2);

    RecountIntervalTestLogin::create(['user_id' => $this->user->id, 'created_at' => $today]);
    RecountIntervalTestLogin::create(['user_id' => $this->user->id, 'created_at' => $today]); // 2 today
    RecountIntervalTestLogin::create(['user_id' => $this->user->id, 'created_at' => $yesterday]); // 1 yesterday
    RecountIntervalTestLogin::create(['user_id' => $this->user->id, 'created_at' => $twoDaysAgo]); // 1 two days ago

    // Recount for last 3 days
    // We expect the macro to support periods argument
    $this->user->logins()->recount('user_logins', Interval::Day, 3);

    // Check counters
    expect($this->user->counter('user_logins', Interval::Day, $today))->toBe(2)
        ->and($this->user->counter('user_logins', Interval::Day, $yesterday))->toBe(1)
        ->and($this->user->counter('user_logins', Interval::Day, $twoDaysAgo))->toBe(1);
});
