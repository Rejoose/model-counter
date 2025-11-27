# 📊 Laravel Model Counter

**Ultra-efficient model counting package for Laravel with Redis-backed caching and scheduled database synchronization.**

Perfect for tracking downloads, views, likes, visits, or any metric that needs to be counted millions of times per day without database bottlenecks.

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/PHP-8.3%2B-blue)](https://php.net)
[![Laravel Version](https://img.shields.io/badge/Laravel-11%7C12-red)](https://laravel.com)

## ✨ Features

- ⚡ **Blazing Fast**: Uses Redis atomic operations for lightning-fast increments
- 🔄 **Efficient Sync**: Scheduled batch syncing to database reduces DB load by 99%
- 🎯 **Polymorphic**: Works with any Eloquent model as the "owner"
- 💪 **Production Ready**: Battle-tested architecture used in high-traffic analytics systems
- 🧪 **Well Tested**: Comprehensive test coverage
- 📦 **Zero Config**: Works out of the box with sensible defaults
- 🔧 **Highly Configurable**: Customize every aspect to fit your needs

## 📋 Requirements

- PHP 8.3+
- Laravel 11.x or 12.x
- Redis (for caching)

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

Add this to your `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule): void
{
    // Sync every minute for near real-time accuracy
    $schedule->command('counter:sync')->everyMinute();
    
    // OR sync every 5 minutes for reduced overhead
    // $schedule->command('counter:sync')->everyFiveMinutes();
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

## 🎯 Use Cases

### Track Product Downloads

```php
use Rejoose\ModelCounter\Counter;

class Product extends Model
{
    use HasCounters;
}

// When someone downloads
$product->incrementCounter('downloads');

// Display download count
echo "Downloaded {$product->counter('downloads')} times";
```

### Track User Activity

```php
class User extends Authenticatable
{
    use HasCounters;
}

// Track various metrics
$user->incrementCounter('profile_views');
$user->incrementCounter('posts_created');
$user->incrementCounter('comments_made');

// Get user stats
$stats = $user->counters(['profile_views', 'posts_created', 'comments_made']);
```

### Track Organization Metrics

```php
class Organization extends Model
{
    use HasCounters;
}

// Track organization activity
$org->incrementCounter('api_calls');
$org->incrementCounter('storage_used', $fileSize);
$org->incrementCounter('emails_sent');

// Check quotas
if ($org->counter('api_calls') > $org->api_limit) {
    throw new QuotaExceededException();
}
```

## 🔧 Advanced Usage

### Direct Counter Facade

You can use the `Counter` class directly without the trait:

```php
use Rejoose\ModelCounter\Counter;

$user = User::find(1);

Counter::increment($user, 'downloads', 1);
Counter::decrement($user, 'credits', 5);
$count = Counter::get($user, 'downloads');
Counter::reset($user, 'downloads');
Counter::set($user, 'downloads', 1000);
```

### Batch Operations

```php
// Increment multiple related counters
$product->incrementCounter('downloads');
$product->incrementCounter('total_bandwidth', $fileSize);
$product->owner->incrementCounter('total_downloads');
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
    // Cache store (must support atomic operations, Redis recommended)
    'store' => env('COUNTER_STORE', 'redis'),

    // Redis key prefix
    'prefix' => env('COUNTER_PREFIX', 'counter:'),

    // Batch size for sync operations
    'sync_batch_size' => env('COUNTER_SYNC_BATCH_SIZE', 1000),

    // Table name
    'table_name' => 'model_counters',
];
```

### Environment Variables

```env
COUNTER_STORE=redis
COUNTER_PREFIX=counter:
COUNTER_SYNC_BATCH_SIZE=1000
```

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
    count BIGINT UNSIGNED DEFAULT 0,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY (owner_type, owner_id, key),
    INDEX (owner_type, owner_id)
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
$schedule->command('counter:sync')->everyThirtySeconds();
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

