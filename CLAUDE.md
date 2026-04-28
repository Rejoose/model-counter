# CLAUDE.md

## Project Overview

**Package:** `rejoose/model-counter` — A Laravel package for ultra-efficient model counting using Redis-backed caching with scheduled database synchronization. Tracks high-volume metrics (downloads, views, likes, visits) without database bottlenecks.

**PHP:** ^8.3 | **Laravel:** 13.x | **License:** MIT

## Architecture

Three-phase design:

1. **Write** — Redis atomic `INCR`/`DECR` (sub-millisecond, no DB hit)
2. **Sync** — Scheduled `counter:sync` command batch-upserts Redis deltas to the database, then clears Redis keys
3. **Read** — Combines DB baseline + Redis delta for accurate current count

Redis key format: `{prefix}{morph_class}:{owner_id}:{counter_key}[:interval:period_key]`

The morph class uses dot-notation (e.g., `app.models.user`) and respects Laravel's morph map.

## Directory Structure

```
src/
  Counter.php                  # Main static facade (increment/decrement/get/set/reset/delete + bulk ops)
  ModelCounterServiceProvider.php  # Service provider, config, migrations, macros
  Console/SyncCounters.php     # Artisan counter:sync command (Redis→DB, batch owner loading)
  Console/PruneCounters.php    # Artisan counter:prune command (retention-based cleanup)
  Enums/Interval.php           # Day/Week/Month/Quarter/Year enum with period calculations
  Events/                      # CounterIncremented, CounterDecremented, CounterReset, CounterSynced
  Models/ModelCounter.php      # Eloquent model for DB persistence
  Traits/HasCounters.php       # Trait for models (counter methods + query scopes + bulk ops)
  Filament/                    # Optional Filament 4 admin panel integration
config/counter.php             # Package configuration (store, direct, prefix, batch_size, table_name, events, retention)
database/migrations/           # Four migrations: create table, add interval columns, add count index, add unique_hash column
tests/
  Feature/CounterTest.php      # Core functionality + key validation + bulk ops + events tests
  Feature/PruneCountersTest.php    # Prune command tests
  Feature/RecountIntervalTest.php  # Interval recount tests
  Feature/RelationCounterTest.php  # Relation macro + scope tests
  TestCase.php                 # Base test case (SQLite in-memory, array cache, direct mode)
```

## Commands

```bash
# Run tests
composer test              # vendor/bin/pest

# Run tests with coverage
composer test-coverage     # vendor/bin/pest --coverage

# Code formatting (Laravel Pint, PSR-12)
composer pint              # vendor/bin/pint

# Static analysis (PHPStan level 5)
composer phpstan           # vendor/bin/phpstan analyse
```

### Artisan Commands

```bash
# Sync Redis counters to database
php artisan counter:sync [--dry-run] [--pattern=]

# Prune old interval counter records
php artisan counter:prune [--older-than=90] [--interval=day] [--dry-run]
```

## Testing

- Framework: **Pest PHP 3** on top of **Orchestra Testbench 9**
- Database: SQLite in-memory (`RefreshDatabase` trait)
- Default test config: `counter.store = array`, `counter.direct = true` (no Redis required)
- For Redis-specific tests, use the `useRedisCache()` helper in `TestCase`
- Follow AAA pattern (Arrange, Act, Assert)
- Tests live in `tests/Feature/` and `tests/Unit/`

## Code Style & Conventions

- **PSR-12** via Laravel Pint with custom rules: `simplified_null_return`, no `braces`, no `new_with_braces`
- **Strict types** in all files
- Full **type hints** on parameters and return types
- **PHPStan level 5** — all code must pass static analysis
- Indentation: 4 spaces (2 for YAML/JSON)
- Line endings: LF
- Encoding: UTF-8

## CI Pipeline

Defined in `.github/workflows/`:

- **tests.yml** — Matrix: PHP 8.3/8.4 x Laravel 13 x prefer-lowest/prefer-stable. Runs Pest with Redis 7 service container. Uploads coverage to Codecov.
- **lint.yml** — Runs PHPStan and Pint in parallel.

## Key Patterns

- **Polymorphic ownership:** Counters attach to any Eloquent model via `owner_type`/`owner_id`
- **Morph map support:** Redis keys and sync command use `getMorphClass()` — configure via `Relation::enforceMorphMap()` for custom namespaces
- **Interval enum:** `Day`, `Week`, `Month`, `Quarter`, `Year` with `periodKey()` for Redis/DB keys
- **Direct mode:** Set `COUNTER_DIRECT=true` to bypass Redis and write straight to DB (useful for dev/testing)
- **Query scopes:** `withCounter()` and `orderByCounter()` use JOINs to avoid N+1; respects configured table name
- **Atomic operations:** Redis INCR/DECR for writes; GETDEL during sync; insert-or-update with retry for race conditions
- **Bulk operations:** `incrementMany()`/`decrementMany()` for batch counter updates
- **Events:** Opt-in via `counter.events` config — dispatches `CounterIncremented`, `CounterDecremented`, `CounterReset`, `CounterSynced`
- **Key validation:** Counter keys must be non-empty, no colons, max 100 chars
- **Recount:** `recount()` and `recountPeriods()` recalculate counters from source data using relationship macros
- **Pruning:** `counter:prune` command with configurable per-interval retention periods
- **DB-first safety:** `set()` and `reset()` write to DB before clearing cache to prevent data loss

## Configuration (config/counter.php)

| Key              | Env Var                   | Default          | Description                          |
|------------------|---------------------------|------------------|--------------------------------------|
| `store`          | `COUNTER_STORE`           | `redis`          | Cache store (redis, array, custom)   |
| `direct`         | `COUNTER_DIRECT`          | `false`          | Write directly to DB, skip Redis     |
| `prefix`         | `COUNTER_PREFIX`          | `counter:`       | Redis key prefix                     |
| `sync_batch_size`| `COUNTER_SYNC_BATCH_SIZE` | `1000`           | Batch size for sync command          |
| `table_name`     | —                         | `model_counters` | Database table name                  |
| `events`         | `COUNTER_EVENTS`          | `false`          | Dispatch counter events              |
| `retention.day`  | —                         | `90`             | Days to retain daily counter records |
| `retention.week` | —                         | `365`            | Days to retain weekly records        |
| `retention.month`| —                         | `null`           | Days to retain monthly (null=forever)|
| `retention.quarter`| —                       | `null`           | Days to retain quarterly             |
| `retention.year` | —                         | `null`           | Days to retain yearly                |

## Multi-App Usage

This package is used in `rejoose-app` and `papi`. Key considerations:

- **Redis isolation:** Each app uses a separate Redis instance. If sharing Redis, use distinct `COUNTER_PREFIX` values.
- **Morph maps:** Configure `Relation::enforceMorphMap()` in each app for models with custom namespaces. The sync command resolves models via morph map first, then falls back to `App\Models\*`.
- **Migrations:** Published per-app. The table name is configurable via `counter.table_name`.

## Git Conventions

- Commit messages: type(scope): description (e.g., `feat(counter): add weekly interval support`)
- Branch from `main` or `develop`
- PRs require tests and passing CI
