# Installation Guide

Complete installation guide for the Laravel Model Counter package.

## Prerequisites

Before installing, ensure you have:

- PHP 8.3 or higher
- Laravel 11.x or 12.x
- Redis installed and running
- Composer

## Step-by-Step Installation

### 1. Install Package via Composer

For production use (when published to Packagist):

```bash
composer require rejoose/model-counter
```

For local development:

Add to your `composer.json`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../model-counter"
        }
    ]
}
```

Then install:

```bash
composer require rejoose/model-counter:*
```

### 2. Verify Service Provider Auto-Discovery

The package should auto-register via Laravel's package discovery. Verify by checking:

```bash
php artisan package:discover
```

You should see:
```
Discovered Package: rejoose/model-counter
```

### 3. Publish Configuration (Optional)

If you want to customize the configuration:

```bash
php artisan vendor:publish --tag=counter-config
```

This creates `config/counter.php` where you can customize:

```php
return [
    'store' => env('COUNTER_STORE', 'redis'),
    'prefix' => env('COUNTER_PREFIX', 'counter:'),
    'sync_batch_size' => env('COUNTER_SYNC_BATCH_SIZE', 1000),
    'table_name' => 'model_counters',
];
```

### 4. Configure Redis

Ensure your `.env` file has Redis configured:

```env
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
REDIS_DB=0

# Optional: Custom counter configuration
COUNTER_STORE=redis
COUNTER_PREFIX=counter:
COUNTER_SYNC_BATCH_SIZE=1000
```

Test Redis connection:

```bash
redis-cli ping
# Should return: PONG
```

### 5. Run Database Migrations

The package includes a migration for the `model_counters` table:

```bash
php artisan migrate
```

This creates the table with the following structure:

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

### 6. Schedule Counter Sync

Add the sync command to your scheduler in `app/Console/Kernel.php`:

```php
<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        // Sync counters every minute (recommended for real-time accuracy)
        $schedule->command('counter:sync')->everyMinute();
        
        // Alternative schedules:
        // $schedule->command('counter:sync')->everyFiveMinutes();
        // $schedule->command('counter:sync')->everyTenMinutes();
        // $schedule->command('counter:sync')->hourly();
    }
}
```

### 7. Start the Scheduler

For local development:

```bash
php artisan schedule:work
```

For production, add to your crontab:

```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

## Verification

### Verify Installation

Check that the package is installed correctly:

```bash
# Check if migration exists
php artisan migrate:status

# Check if command is registered
php artisan list counter

# Should show: counter:sync
```

### Test Basic Functionality

Create a test in `tinker`:

```bash
php artisan tinker
```

```php
// Add trait to User model first (see next section)
$user = App\Models\User::first();
$user->incrementCounter('test');
$user->counter('test'); // Should return 1

// Wait for sync or run manually
exit();
```

```bash
php artisan counter:sync
```

Then check database:

```bash
php artisan tinker
```

```php
$user->counter('test'); // Should still be 1
```

## Add Trait to Models

Add the `HasCounters` trait to any model you want to track:

```php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Rejoose\ModelCounter\Traits\HasCounters;

class User extends Authenticatable
{
    use HasCounters;
    
    // Your existing model code...
}
```

## Quick Start Usage

After installation, you can immediately start using counters:

```php
use App\Models\User;

$user = User::find(1);

// Increment
$user->incrementCounter('profile_views');
$user->incrementCounter('downloads', 5);

// Read
$views = $user->counter('profile_views');

// Multiple counters
$stats = $user->counters(['profile_views', 'downloads']);

// All counters
$all = $user->allCounters();
```

## Troubleshooting

### Redis Connection Error

If you see "Connection refused" errors:

```bash
# Check if Redis is running
redis-cli ping

# Start Redis (macOS)
brew services start redis

# Start Redis (Ubuntu)
sudo systemctl start redis

# Start Redis (Windows with WSL)
sudo service redis-server start
```

### Migration Already Exists

If you get "Table already exists" error:

```bash
# Drop the table and re-run
php artisan migrate:rollback
php artisan migrate
```

### Scheduler Not Running

Verify the scheduler is active:

```bash
# Check recent schedule runs
php artisan schedule:list

# Test scheduler manually
php artisan schedule:run
```

### Permission Errors

If you get permission errors:

```bash
# Fix storage permissions
chmod -R 775 storage
chmod -R 775 bootstrap/cache
```

## Advanced Configuration

### Using Multiple Redis Databases

Separate counter cache from other cache:

```php
// config/database.php
'redis' => [
    'counter' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD', null),
        'port' => env('REDIS_PORT', 6379),
        'database' => 2, // Separate database for counters
    ],
],

// config/cache.php
'stores' => [
    'counter_redis' => [
        'driver' => 'redis',
        'connection' => 'counter',
    ],
],

// .env
COUNTER_STORE=counter_redis
```

### Custom Table Name

Change the table name:

```env
# .env
COUNTER_TABLE_NAME=custom_counters
```

```php
// config/counter.php
'table_name' => env('COUNTER_TABLE_NAME', 'model_counters'),
```

Then publish and edit the migration to use your custom name.

## Next Steps

- Read the [README.md](README.md) for full documentation
- Check [EXAMPLES.md](EXAMPLES.md) for real-world usage patterns
- Review [CONTRIBUTING.md](CONTRIBUTING.md) if you want to contribute

## Support

If you encounter any issues:

1. Check the [troubleshooting section](#troubleshooting)
2. Review existing [GitHub Issues](https://github.com/rejoose/model-counter/issues)
3. Create a new issue with detailed information

Happy counting! 🎉

