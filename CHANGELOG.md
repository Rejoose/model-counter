# Changelog

All notable changes to `model-counter` will be documented in this file.

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

