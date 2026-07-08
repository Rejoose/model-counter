# Changelog

All notable changes to `model-counter` will be documented in this file.

## [2.4.2] - 2026-07-08

### Fixed
- **`counter:sync` now reclaims Redis keys that drain to zero.** Sync deliberately uses `GET` + `DECRBY` (not `GETDEL`) so a failed DB write is retried and a concurrent increment mid-sync is preserved — but a fully-drained key was left at `0` forever. With interval counters minting a new key per period and sync running every minute, each run `SCAN`ned a monotonically growing set of dead keys and Redis memory grew with history, not activity. The `DECRBY` is now an atomic Lua `DECRBY` + `DEL`-if-zero per key, which reclaims drained keys while preserving the concurrent-increment guarantee (an increment lands before the script and keeps the key alive, or after and recreates it).
- **`Interval::previousPeriods()` no longer skips a month/quarter near month-end.** For `Month`/`Quarter`, the date was decremented *before* being truncated, so from a day like May 31 `subMonths(1)` overflowed (April has no 31st) and `startOfMonth()` snapped back to the current period — duplicating it and silently skipping one. This corrupted `history()`, `recountPeriods()`, and interval `verify` runs invoked on the 29th–31st. The truncation now happens before the subtraction.
- **`Counter::getMany()` batched read fixed.** It built prefix-less keys and handed them to a raw `MGET`, which read the wrong keys and returned all zeros (the per-key fallback masked it). It now uses `Repository::many()`, which `MGET`s with the cache-store prefix applied. `incrementMany()`/`decrementMany()` now pipeline their writes to Redis in a single round trip instead of one `INCR`/`DECR` per key.

### Docs
- Corrected the README / CLAUDE.md claim that sync uses `GETDEL` (it uses `GET` + `DECRBY`), and documented that `cache:clear` / `FLUSHDB` on the counter store discards all unsynced deltas — run `counter:sync` first.

### Tests / CI
- Added Redis-backed coverage for `getMany()`, the key-reclaim behaviour (asserted under both phpredis and Predis), the bulk-writer pipeline path, and month-end `previousPeriods()`.
- Added a MySQL CI job. Test-suite table creation moved out of `setUp()`/`beforeEach()` into `TestCase::defineDatabaseMigrations()` so it runs before `RefreshDatabase`'s transaction — required for isolation on MySQL, where DDL auto-commits.

## [2.4.1] - 2026-06-12

### Fixed
- **`count` column is now signed** (`make_count_signed_on_model_counters_table` migration + signed type in the create migration for fresh installs). Decrements can legitimately drive a counter negative — e.g. a Day bucket that only saw deletions of items created on earlier days. On MySQL the previous UNSIGNED column made `counter:sync` fail on every net-negative delta (error 1264 on insert, 1690 on the `count = count + (negative)` upsert arithmetic); the failed keys were never drained from Redis, so every subsequent scheduled run exited 1 as well.
- The Filament `ModelCounterResource` form no longer enforces `minValue(0)` on `count` — admins can view/edit legitimately negative counters.
- **`counter:sync` now works with the Predis client.** The SCAN loop started from a `null` cursor (a phpredis ≥6 requirement), which Predis serializes to an empty string — Redis rejects it with `ERR invalid cursor`, so the command exited 1 on every run under `REDIS_CLIENT=predis`. The initial cursor is now client-aware. The whole sync suite re-runs under Predis via `SyncCountersPredisTest` (`predis/predis` added to require-dev only).

## [2.4.0] - 2026-06-08

### Added
- **Global (ownerless) counters.** App-wide counters that belong to no model, stored with `NULL` owner_type / owner_id. New facade methods `Counter::incrementGlobal/decrementGlobal/getGlobal/getManyGlobal/setGlobal/resetGlobal/sumGlobal/historyGlobal/deleteGlobal`. All owner-keyed `Counter` and `ModelCounter` methods now accept a nullable owner (`?Model`); passing `null` targets the global counter. On the Redis wire global counters use a reserved `global:0` owner token (so `counter:sync`'s colon-split is unaffected), translated back to a `NULL` owner on flush. Requires the new `make_owner_nullable_on_model_counters_table` migration.
- **Gauge / snapshot API.** `Counter::snapshot()` / `Counter::snapshotGlobal()` record an *absolute* value for a period (intent-revealing aliases over `set()` with an explicit period — last-write-wins, idempotent per period). `Counter::latest()` / `Counter::latestGlobal()` read the most recent snapshot for an interval. Designed for daily cumulative-total snapshots feeding trend charts.
- **`counter:recount {model}` command.** Recounts every counter declared by a `DefinesCounters` model from its source-of-truth closures, chunking over the model's records (`--id`, `--from`, `--to`, `--chunk`). Replaces per-app hand-rolled recount loops.

## [2.3.0] - 2026-05-26

### Added
- `Counter::bulkSet(array $rows, bool $skipZero = false): int` — write many absolute counter values in a single batched UPSERT per ~200 rows. Designed for historical backfills and pre-aggregated seed data; orders of magnitude faster than looping `Counter::set()`. Always invalidates the matching cache key per row (including zero rows and rows dropped by `skipZero`) so a stale Redis delta can't survive the write.
- `ModelCounter::bulkSetValue(array $rows): void` — lower-level absolute-set primitive used by `Counter::bulkSet()`. Mirrors `bulkAddDelta()` but overwrites (`count = EXCLUDED.count`) instead of adding. Last-write-wins for duplicate hashes in the input. Supports MySQL / MariaDB / PostgreSQL / SQLite; falls back to per-row `setValue()` on other drivers.
- `ModelCounter::supportsBulkSetValue(?string $driver = null): bool` — driver-support probe matching `supportsBulkAddDelta()`.

## [2.0.0] - 2026-04-27

### Breaking Changes
- Dropped support for Laravel 11 and 12. Minimum supported version is now Laravel 13. Consumers on Laravel ≤12 should pin to `^1.0`.
- Bumped `larastan/larastan` to `^3.0` and `phpstan/phpstan` to `^2.0` (required by Laravel 13).
- Bumped `orchestra/testbench` to `^11.0`.

### Changed
- `Console\SyncCounters` no longer probes for `getPrefix()` via `method_exists`; it is guaranteed by the `Illuminate\Contracts\Cache\Store` contract on Laravel 13.
- Removed `checkMissingIterableValueType` and `checkGenericClassInNonGenericObjectType` from `phpstan.neon` (no-ops in PHPStan 2.x).
- CI matrix narrowed to Laravel 13 only across PHP 8.3 / 8.4 × prefer-lowest / prefer-stable.

## [1.0.0] - 2024-11-27

### Added
- Initial release
- Redis-backed atomic counter operations
- Scheduled database synchronization
- Polymorphic owner support for any Eloquent model
- `HasCounters` trait for easy integration
- `counter:sync` console command with dry-run mode
- Comprehensive test suite
- Full documentation and examples

### Features
- Lightning-fast increments using Redis atomic operations
- Efficient batch syncing to reduce database writes by 99%
- Thread-safe operations with race condition handling
- Support for increment, decrement, reset, and set operations
- Configurable cache store, prefix, and batch sizes
- Pattern-based syncing for selective counter updates
- Detailed sync reporting with success/error counts

