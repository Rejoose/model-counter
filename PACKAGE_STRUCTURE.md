# Package Structure

This document outlines the complete structure of the Laravel Model Counter package.

```
model-counter/
├── .editorconfig                   # Editor configuration for consistent coding style
├── .gitignore                      # Git ignore rules
├── CHANGELOG.md                    # Version history and changes
├── composer.json                   # Package dependencies and metadata
├── CONTRIBUTING.md                 # Contribution guidelines
├── EXAMPLES.md                     # Real-world usage examples
├── LICENSE                         # MIT License
├── phpunit.xml                     # PHPUnit configuration
├── README.md                       # Main documentation
│
├── config/
│   └── counter.php                 # Package configuration file
│
├── database/
│   └── migrations/
│       └── 2024_01_01_000000_create_model_counters_table.php
│                                   # Database migration for counters table
│
├── src/
│   ├── Counter.php                 # Main Counter manager class
│   ├── ModelCounterServiceProvider.php
│   │                               # Laravel service provider (auto-discovered)
│   │
│   ├── Console/
│   │   └── SyncCounters.php        # Artisan command for syncing Redis to DB
│   │
│   ├── Models/
│   │   └── ModelCounter.php        # Eloquent model for counter persistence
│   │
│   └── Traits/
│       └── HasCounters.php         # Trait for owner models
│
└── tests/
    ├── Pest.php                    # Pest PHP configuration
    ├── TestCase.php                # Base test case
    │
    └── Feature/
        └── CounterTest.php         # Feature tests for counter functionality
```

## Core Components

### 1. Counter Manager (`src/Counter.php`)

The main facade for all counter operations. Provides static methods for:
- `increment()` - Atomic increment in Redis
- `decrement()` - Atomic decrement in Redis
- `get()` - Read counter (DB + Redis delta)
- `getMany()` - Batch read multiple counters
- `reset()` - Reset counter to zero
- `set()` - Set counter to specific value
- `all()` - Get all counters for an owner

### 2. Service Provider (`src/ModelCounterServiceProvider.php`)

Handles package bootstrapping:
- Merges package config
- Publishes config file
- Loads migrations
- Registers console commands
- Auto-discovered by Laravel

### 3. Model Counter (`src/Models/ModelCounter.php`)

Eloquent model for database persistence:
- Polymorphic relationship to owner
- Atomic delta operations
- Efficient upsert with race condition handling
- Query methods for retrieving counter values

### 4. HasCounters Trait (`src/Traits/HasCounters.php`)

Convenient trait for owner models:
- `counter($key)` - Get single counter
- `counters($keys)` - Get multiple counters
- `allCounters()` - Get all counters
- `incrementCounter($key, $amount)` - Increment
- `decrementCounter($key, $amount)` - Decrement
- `resetCounter($key)` - Reset to zero
- `setCounter($key, $value)` - Set specific value

### 5. Sync Command (`src/Console/SyncCounters.php`)

Scheduled command for Redis → DB synchronization:
- Uses Redis SCAN for efficient key iteration
- Batch processing with configurable size
- Dry-run mode for testing
- Pattern-based selective syncing
- Detailed reporting and error handling
- Atomic operations with GETDEL

## Database Schema

### `model_counters` Table

| Column       | Type              | Description                          |
|--------------|-------------------|--------------------------------------|
| id           | BIGINT UNSIGNED   | Primary key                          |
| owner_type   | VARCHAR(255)      | Polymorphic owner class              |
| owner_id     | BIGINT UNSIGNED   | Polymorphic owner ID                 |
| key          | VARCHAR(100)      | Counter name/identifier              |
| count        | BIGINT UNSIGNED   | Current counter value                |
| created_at   | TIMESTAMP         | Creation timestamp                   |
| updated_at   | TIMESTAMP         | Last update timestamp                |

**Indexes:**
- Unique: `(owner_type, owner_id, key)`
- Index: `(owner_type, owner_id)`

## Redis Key Format

```
{prefix}{model}:{id}:{key}
```

Examples:
- `counter:user:123:downloads`
- `counter:product:456:views`
- `counter:organization:789:api_calls`

## Configuration Options

| Option            | Default          | Description                           |
|-------------------|------------------|---------------------------------------|
| `store`           | `redis`          | Cache store for counter operations    |
| `prefix`          | `counter:`       | Redis key prefix                      |
| `sync_batch_size` | `1000`           | Batch size for sync operations        |
| `table_name`      | `model_counters` | Database table name                   |

## Testing

Tests are organized using Pest PHP:
- **TestCase**: Base class with Orchestra Testbench
- **Feature Tests**: End-to-end functionality tests
- **Test Database**: SQLite in-memory
- **Test Cache**: Redis

## Architecture Patterns

### Write Path (Increment)
1. User calls `$model->incrementCounter('key')`
2. Redis atomic `INCR` operation
3. Returns immediately (< 1ms)
4. No database hit

### Read Path (Get)
1. User calls `$model->counter('key')`
2. Query database for baseline value
3. Query Redis for cached delta
4. Return sum of baseline + delta

### Sync Path (Scheduled)
1. Cron triggers `counter:sync` command
2. SCAN Redis for all counter keys
3. For each key:
   - GETDEL atomically reads and removes
   - Parse key to find owner
   - Update database with delta
4. Counters now synced, Redis cleared

## Performance Characteristics

- **Write Operations**: O(1) - Redis atomic operations
- **Read Operations**: O(1) - Single DB query + single Redis query
- **Sync Operations**: O(n) - Linear in number of counters
- **Database Writes**: Reduced by ~99% compared to direct writes
- **Throughput**: Handles millions of increments per day

## Extension Points

The package is designed to be extended:

1. **Custom Owner Models**: Any Eloquent model can use the trait
2. **Custom Counter Keys**: Use any string as a counter key
3. **Custom Sync Schedule**: Configure sync frequency
4. **Custom Batch Sizes**: Adjust for your Redis/DB performance
5. **Custom Redis Connection**: Use dedicated Redis instance

## Dependencies

### Runtime
- `php: ^8.3`
- `illuminate/support: ^11.0|^12.0`
- `illuminate/database: ^11.0|^12.0`
- `illuminate/redis: ^11.0|^12.0`

### Development
- `orchestra/testbench: ^9.0`
- `pestphp/pest: ^3.0`

