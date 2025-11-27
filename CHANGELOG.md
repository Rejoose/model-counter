# Changelog

All notable changes to `model-counter` will be documented in this file.

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

