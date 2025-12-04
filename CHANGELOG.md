# Changelog

All notable changes to `laravel-prunekeeper` will be documented in this file.

## [Unreleased]

## [1.0.0] - 2024-XX-XX

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
