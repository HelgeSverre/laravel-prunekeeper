# Changelog

All notable changes to `laravel-prunekeeper` will be documented in this file.

## [Unreleased]

## [1.0.0] - 2025-01-XX

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
- Invalid format validation in archive command
