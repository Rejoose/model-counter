# Changelog

All notable changes to `model-counter` will be documented in this file.

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

