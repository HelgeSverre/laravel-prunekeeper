# Changelog

All notable changes to `laravel-prunekeeper` will be documented in this file.

## [2.1.0] - 2026-11-06

### Added

- Laravel 13 support, with Testbench 11 and PHP 8.3+ compatibility.
- Fresh-install Docker smoke tests for Laravel 11, 12, and 13, including real pruning, failed-storage protection, SQL restoration, and compression.
- CI coverage for supported Laravel/PHP combinations through PHP 8.5.

### Fixed

- CSV and SQL archives now preserve stored database attributes, including hidden columns, without applying Eloquent casts, accessors, or appended attributes.
- Partial query projections, empty column selections, and unsupported binary/resource SQL values fail explicitly instead of silently producing incomplete archives.
- SQL exports preserve database timestamp formats, support PostgreSQL booleans, and detect failed or partial writes.
- SQL dumps omit generated columns by default and reject explicitly selected generated columns so dumps remain restorable.
- CSV and SQL preserve floating-point precision independently of application PHP precision settings.
- Compression driver overrides now select the requested algorithm and filename extension.
- Compression drivers reject non-file sources and preserve existing output files; gzip/bzip2 check writes and finalization failures.
- Storage uploads use unique staging objects, refuse existing archive paths, clean up their own failed uploads, and reject empty compression output.
- Model selections are deduplicated and invalid explicit selections return failing command statuses.
- Model discovery uses declared namespaces, including custom application namespaces, and excludes non-instantiable models.
- Validation checks pruning implementations, table existence, and empty column selections.
- Archive lifecycle failures and globally disabled skips dispatch the corresponding events consistently.
- Export resources close before failure cleanup, including Windows temporary-file cleanup.
- Docker test dependencies are resolved inside their PHP container instead of reusing host dependencies.
- Declare the Intl extension required by archive size reporting so incompatible installations fail during dependency resolution.

### Upgrade notes

- Archives include hidden database fields. Use explicit archive columns when sensitive fields must be excluded.
- Custom filenames must be unique: archive paths already present when checked are rejected. Concurrent runs using custom names still require external coordination; the filesystem API does not provide an atomic reservation.
- SQL values containing NUL bytes or stream resources are rejected; partial projections must load every configured archive column.
- Archiving and Laravel pruning remain separate queries. Coordinate concurrent writers and use a stable cutoff when exact archive coverage is required.

## [2.0.0] - 2025-06-06

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
- Exporters now clean up temp files on failure (prevents orphaned files in long-running workers)

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
