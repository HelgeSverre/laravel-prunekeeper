# Changelog

All notable changes to `laravel-prunekeeper` will be documented in this file.

## [Unreleased]

### Changed

- **Breaking:** Refactored `Prunekeeper` class to use static methods following Laravel first-party package patterns (Telescope, Horizon, Sanctum, Scout)
- Removed dependency injection of `Prunekeeper` in commands and listeners - now uses static method calls directly
- `beforeArchiving()`, `afterArchiving()`, `generateFilenameUsing()`, `resolveColumnsUsing()`, `createTempFileUsing()`, and `resolveTableNameUsing()` now return `void` instead of `$this`
- Exporters now import `HelgeSverre\Prunekeeper\Prunekeeper` directly instead of the Facade

### Added

- `Prunekeeper::makePrunableQuery()` static helper to build prunable queries with soft-delete support
- `Prunekeeper::flushState()` method for resetting callbacks between tests
- Gzip compression driver (`gzip`)
- Bzip2 compression driver (`bzip2`)
- TarGzip compression driver (`targz`)
- Configurable compression drivers via `prunekeeper.compression.driver` config
- Buffer size configuration for Gzip and Bzip2 drivers
- Partial file cleanup on compression failure

### Fixed

- TarGzip driver now checks `phar.readonly` INI setting in `isAvailable()`
- Compression drivers clean up partial files on failure

### Removed

- `Prunekeeper` singleton binding from service provider (class is now fully static)

## [1.0.0] - 2025-06-06

### Added

- Initial release
- `ArchivePrunedRecords` trait for models
- CSV and SQL export formats
- ZIP compression support
- Storage to any Laravel filesystem disk (S3, local, etc.)
- Custom filename generation via facade or model method
- Column selection with `getArchivableColumns()` method
- Column validation with `InvalidColumnException` for invalid columns
- Before/after archiving callbacks
- `prunekeeper:archive` command for manual archiving
- `prunekeeper:validate` command for configuration validation
- Configurable failure behavior (abort vs continue)
- Support for Laravel 11 and 12
- Support for both `Prunable` and `MassPrunable` traits
- Streamed uploads for memory-efficient handling of large exports
- Unique filename generation to prevent collisions
- Model connection-aware SQL escaping
- SQL identifier escaping to prevent injection in table/column names
- Empty file validation before upload
- Upload verification with error handling
- Proper resource cleanup with try-finally blocks
- Chunk size bounds validation (1-10000)
- Detailed error messages with context
- Case-insensitive format option (`--format=CSV` and `--format=csv` both work)
- Configurable file open mode for exporters
- Safer JSON encoding with unicode support
