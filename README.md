# 📊 Laravel Model Counter

**Ultra-efficient model counting package for Laravel with Redis-backed caching, interval-based counting, and scheduled database synchronization.**

Perfect for tracking downloads, views, likes, visits, or any metric that needs to be counted millions of times per day without database bottlenecks.

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/PHP-8.3%2B-blue)](https://php.net)
[![Laravel Version](https://img.shields.io/badge/Laravel-11%7C12-red)](https://laravel.com)

## ✨ Features

- ⚡ **Blazing Fast**: Uses Redis atomic operations for lightning-fast increments
- 🔄 **Efficient Sync**: Scheduled batch syncing to database reduces DB load by 99%
- 📅 **Interval Counting**: Track counts by day, week, month, quarter, or year
- 🔁 **Safe Recount**: Safely recount values from source data
- 🎯 **Polymorphic**: Works with any Eloquent model as the "owner"
- 🖥️ **Filament Integration**: Optional Filament 4 admin panel resource
- 💪 **Production Ready**: Battle-tested architecture used in high-traffic analytics systems
- 🧪 **Well Tested**: Comprehensive test coverage
- 📦 **Zero Config**: Works out of the box with sensible defaults
- 🔧 **Highly Configurable**: Customize every aspect to fit your needs

## 📋 Requirements

- PHP 8.3+
- Laravel 11.x or 12.x
- Redis (for caching)
- Filament 4.x (optional, for admin panel)

## 📦 Installation

### Step 1: Install via Composer

```bash
composer require rejoose/model-counter
```

### Step 2: Publish Configuration (Optional)

```bash
php artisan vendor:publish --tag=counter-config
```

### Step 3: Run Migrations

```bash
php artisan migrate
```

### Step 4: Configure Redis

Ensure your `.env` has Redis configured:

```env
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
```

### Step 5: Schedule Counter Sync

Add this to your `routes/console.php` (Laravel 11+):

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('counter:sync')->everyMinute();
```

Or in `app/Console/Kernel.php` (Laravel 10):

```php
protected function schedule(Schedule $schedule): void
{
    $schedule->command('counter:sync')->everyMinute();
}
```

## 🚀 Quick Start

### 1. Add Trait to Your Model

```php
use Rejoose\ModelCounter\Traits\HasCounters;

class User extends Authenticatable
{
    use HasCounters;
}
```

### 2. Increment Counters

```php
$user = User::find(1);

// Increment by 1
$user->incrementCounter('downloads');

// Increment by custom amount
$user->incrementCounter('views', 5);

// Decrement
$user->decrementCounter('credits', 10);
```

### 3. Read Counters

```php
// Get single counter
$downloads = $user->counter('downloads'); // e.g., 1523

// Get multiple counters
$stats = $user->counters(['downloads', 'views', 'likes']);
// ['downloads' => 1523, 'views' => 4521, 'likes' => 234]

// Get all counters
$allStats = $user->allCounters();
```

### 4. Reset or Set Counters

```php
// Reset to zero
$user->resetCounter('downloads');

// Set to specific value
$user->setCounter('downloads', 1000);
```

## 📅 Interval-Based Counting

Track counts across different time periods - perfect for analytics dashboards, rate limiting, and reporting.

### Available Intervals

```php
use Rejoose\ModelCounter\Enums\Interval;

Interval::Day      // Daily counts
Interval::Week     // Weekly counts
Interval::Month    // Monthly counts
Interval::Quarter  // Quarterly counts
Interval::Year     // Yearly counts
```

### Basic Interval Usage

```php
use Rejoose\ModelCounter\Enums\Interval;

// Increment today's page views
$user->incrementCounter('page_views', 1, Interval::Day);

// Increment this month's API calls
$user->incrementCounter('api_calls', 1, Interval::Month);

// Get today's count
$todayViews = $user->counter('page_views', Interval::Day);

// Get this month's count
$monthlyApiCalls = $user->counter('api_calls', Interval::Month);
```

### Interval Counters are Separate

Total counters and interval counters are tracked independently:

```php
// These are 3 separate counters!
$user->incrementCounter('downloads');                    // Total (no interval)
$user->incrementCounter('downloads', 1, Interval::Day);  // Today's count
$user->incrementCounter('downloads', 1, Interval::Month); // This month's count

$total = $user->counter('downloads');                     // Total downloads
$today = $user->counter('downloads', Interval::Day);      // Today's downloads
$thisMonth = $user->counter('downloads', Interval::Month); // This month's downloads
```

### Get Historical Data

```php
// Get last 12 months of download counts
$history = $user->counterHistory('downloads', Interval::Month, 12);
// Returns: ['2024-12' => 150, '2024-11' => 120, '2024-10' => 95, ...]

// Get last 7 days
$weeklyHistory = $user->counterHistory('page_views', Interval::Day, 7);
// Returns: ['2024-12-03' => 50, '2024-12-02' => 45, ...]

// Get last 4 quarters
$quarterlyHistory = $user->counterHistory('revenue', Interval::Quarter, 4);
```

### Sum Across All Periods

```php
// Get total across all months
$allTimeMonthlySum = $user->counterSum('downloads', Interval::Month);
```

## 🔁 Recount Functionality

Safely recalculate counters from source data - perfect for data migrations, corrections, or periodic reconciliation.

### Basic Recount

```php
// Recount posts from the database
$newCount = $user->recountCounter('posts', fn() => $user->posts()->count());

// Recount with relationship
$newCount = $user->recountCounter('comments', fn() => $user->comments()->count());

// Recount orders for a product
$newCount = $product->recountCounter('orders', fn() => $product->orders()->count());
```

### Recount with Intervals

```php
use Rejoose\ModelCounter\Enums\Interval;

// Recount today's page views
$user->recountCounter(
    'page_views',
    fn() => $user->pageViews()->whereDate('created_at', today())->count(),
    Interval::Day
);
```

### Recount Multiple Periods

Useful for rebuilding historical data:

```php
use Rejoose\ModelCounter\Enums\Interval;
use Carbon\Carbon;

// Recount last 12 months of orders
$results = $user->recountCounterPeriods(
    'orders',
    Interval::Month,
    fn(Carbon $start, Carbon $end) => $user->orders()
        ->whereBetween('created_at', [$start, $end])
        ->count(),
    12 // periods
);

// Returns: ['2024-12' => 15, '2024-11' => 22, '2024-10' => 18, ...]
```

### Direct Counter Class Usage

```php
use Rejoose\ModelCounter\Counter;

// Simple recount
$count = Counter::recount($user, 'posts', fn() => $user->posts()->count());

// Recount multiple periods
$results = Counter::recountPeriods(
    $user,
    'api_calls',
    Interval::Day,
    fn(Carbon $start, Carbon $end) => ApiLog::query()
        ->where('user_id', $user->id)
        ->whereBetween('created_at', [$start, $end])
        ->count(),
    30 // last 30 days
);
```

### Recount via Relationship

You can recount directly from a relationship:

```php
// Recounts 'posts' counter using the posts() relationship count
$user->posts()->recount();

// Recount with custom counter key
$user->posts()->recount('published_posts');

// Recount with interval
$user->posts()->recount('posts', Interval::Day);

// Recount last 30 days
$user->posts()->recount('posts', Interval::Day, 30);

// Recount last 3 months starting from a specific date
$user->posts()->recount('posts', Interval::Day, 90, now()->subMonth());

```

## ⚡ Efficient Querying

The package provides scopes to efficiently query models based on their counters without N+1 problems.

### Order By Counter

Sort models by their counter value:

```php
// Get users with most downloads
$users = User::orderByCounter('downloads', 'desc')->get();

// Get top 10 active users
$topUsers = User::orderByCounter('activity_score', 'desc')->take(10)->get();
```

### With Counter

Add counter values to your model results (as `{key}_count` attribute):

```php
// Get users with their download count
$users = User::withCounter('downloads')->get();

foreach ($users as $user) {
    echo $user->downloads_count; // Efficiently loaded
}
```

## 🎯 Use Cases

### Track Product Downloads with Daily Stats

```php
class Product extends Model
{
    use HasCounters;
}

// When someone downloads
$product->incrementCounter('downloads');              // Total
$product->incrementCounter('downloads', 1, Interval::Day);   // Daily
$product->incrementCounter('downloads', 1, Interval::Month); // Monthly

// Display stats
echo "Total: {$product->counter('downloads')}";
echo "Today: {$product->counter('downloads', Interval::Day)}";
echo "This Month: {$product->counter('downloads', Interval::Month)}";

// Get chart data for last 30 days
$chartData = $product->counterHistory('downloads', Interval::Day, 30);
```

### API Rate Limiting

```php
class User extends Authenticatable
{
    use HasCounters;
    
    public function checkRateLimit(): bool
    {
        $dailyLimit = 1000;
        $currentUsage = $this->counter('api_calls', Interval::Day);
        
        return $currentUsage < $dailyLimit;
    }
    
    public function trackApiCall(): void
    {
        $this->incrementCounter('api_calls', 1, Interval::Day);
        $this->incrementCounter('api_calls', 1, Interval::Month);
    }
}
```

### Monthly Billing Metrics

```php
class Organization extends Model
{
    use HasCounters;
    
    public function getMonthlyUsage(): array
    {
        return [
            'api_calls' => $this->counter('api_calls', Interval::Month),
            'storage_bytes' => $this->counter('storage_used', Interval::Month),
            'emails_sent' => $this->counter('emails', Interval::Month),
        ];
    }
    
    public function recalculateUsage(): void
    {
        $this->recountCounter(
            'storage_used',
            fn() => $this->files()->sum('size'),
            Interval::Month
        );
    }
}
```

## 🖥️ Filament Admin Panel

The package includes an optional Filament 4 resource for managing counters.

### Option 1: Use the Plugin (Recommended)

Register the plugin in your Filament panel provider:

```php
use Rejoose\ModelCounter\Filament\ModelCounterPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // ...
        ->plugin(
            ModelCounterPlugin::make()
                ->navigationGroup('Analytics')        // optional
                ->navigationIcon('heroicon-o-chart-bar')  // optional
                ->navigationSort(100)                 // optional
        );
}
```

### Option 2: Publish & Customize

Publish the Filament resources to your app for full customization:

```bash
php artisan vendor:publish --tag=counter-filament
```

This copies the resource files to `app/Filament/Resources/` where you can customize them.

### Filament Features

- **Table view** with sortable columns, search, and filters
- **Filter by**: Interval type, Owner type, Counter key
- **Badge colors** for different interval types
- **Navigation badge** showing total counter count
- **CRUD operations**: Create, Edit, Delete counters
- **Bulk delete** support

## 🔧 Advanced Usage

### Direct Counter Facade

You can use the `Counter` class directly without the trait:

```php
use Rejoose\ModelCounter\Counter;
use Rejoose\ModelCounter\Enums\Interval;

$user = User::find(1);

// Basic operations
Counter::increment($user, 'downloads', 1);
Counter::decrement($user, 'credits', 5);
$count = Counter::get($user, 'downloads');
Counter::reset($user, 'downloads');
Counter::set($user, 'downloads', 1000);

// With intervals
Counter::increment($user, 'views', 1, Interval::Day);
Counter::get($user, 'views', Interval::Day);
Counter::history($user, 'views', Interval::Day, 30);
Counter::sum($user, 'views', Interval::Day);

// Recount
Counter::recount($user, 'posts', fn() => $user->posts()->count());
```

### Delete Counters

```php
// Delete a specific counter
$user->deleteCounter('old_metric');

// Delete all interval records for a counter
$user->deleteCounter('page_views', Interval::Day);
```

### Manual Sync

You can manually trigger a sync anytime:

```bash
# Sync all counters
php artisan counter:sync

# Dry run (see what would be synced)
php artisan counter:sync --dry-run

# Sync specific pattern
php artisan counter:sync --pattern="user:*"
```

## ⚙️ Configuration

The `config/counter.php` file contains all configuration options:

```php
return [
    // Cache store: 'redis' (production) or 'array' (testing)
    'store' => env('COUNTER_STORE', 'redis'),

    // Direct mode: write directly to DB instead of caching
    'direct' => env('COUNTER_DIRECT', false),

    // Cache key prefix
    'prefix' => env('COUNTER_PREFIX', 'counter:'),

    // Batch size for sync operations (Redis only)
    'sync_batch_size' => env('COUNTER_SYNC_BATCH_SIZE', 1000),

    // Table name
    'table_name' => 'model_counters',
];
```

### Environment Variables

```env
COUNTER_STORE=redis
COUNTER_DIRECT=false
COUNTER_PREFIX=counter:
COUNTER_SYNC_BATCH_SIZE=1000
```

### Direct Mode (Local Development)

For local development without Redis, enable direct mode:

```env
COUNTER_STORE=array
COUNTER_DIRECT=true
```

In direct mode:
- Increments/decrements write directly to the database
- No sync command needed
- Perfect for testing and local development
- Slightly slower than Redis but simpler setup

## 🏗️ Architecture

### How It Works

1. **Increment Phase** (Write-Heavy):
   - All increments are written to Redis using atomic `INCR` operations
   - Extremely fast (< 1ms per operation)
   - No database writes during this phase

2. **Sync Phase** (Scheduled):
   - The `counter:sync` command runs on your schedule (e.g., every minute)
   - Reads all cached counters from Redis
   - Batch updates the database using efficient upsert operations
   - Clears the Redis cache after successful sync

3. **Read Phase**:
   - Reads the baseline value from the database
   - Adds any cached increments from Redis
   - Returns the combined total

### Performance Benefits

- **99% fewer database writes**: Instead of 1M writes/day, you make ~1,440 (one per minute)
- **Lightning fast increments**: Redis atomic operations are 100x faster than DB writes
- **Scalable**: Handles millions of increments per day effortlessly
- **Eventually consistent**: Counters are accurate within your sync interval

## 🧪 Testing

```bash
composer test
```

## 📊 Database Schema

The package creates a `model_counters` table:

```sql
CREATE TABLE model_counters (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_type VARCHAR(255) NOT NULL,
    owner_id BIGINT UNSIGNED NOT NULL,
    key VARCHAR(100) NOT NULL,
    interval VARCHAR(20) NULL,
    period_start DATE NULL,
    count BIGINT UNSIGNED DEFAULT 0,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY (owner_type, owner_id, key, interval, period_start),
    INDEX (owner_type, owner_id),
    INDEX (key, interval, period_start)
);
```

## 🔒 Thread Safety

All operations are designed to be thread-safe:

- Redis atomic operations (`INCR`, `DECR`)
- Database upserts with proper locking
- Race condition handling in sync command

## 🐛 Troubleshooting

### Counters Not Syncing

Check that the scheduler is running:

```bash
php artisan schedule:work
# or in production, ensure cron is configured:
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

### Redis Connection Issues

Verify Redis is running and accessible:

```bash
redis-cli ping
# Should return: PONG
```

### Large Memory Usage

If you have millions of unsynchronized counters, adjust the sync frequency:

```php
// Sync more frequently
Schedule::command('counter:sync')->everyThirtySeconds();
```

## 🤝 Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## 📝 License

This package is open-sourced software licensed under the [MIT license](LICENSE).

## 🙏 Credits

- Inspired by analytics systems at scale (Redis + DB pattern)
- Built for the Laravel community

## 📮 Support

- 🐛 [Issue Tracker](https://github.com/rejoose/model-counter/issues)
- 📖 [Documentation](https://github.com/rejoose/model-counter)

---

Made with ❤️ for the Laravel community
