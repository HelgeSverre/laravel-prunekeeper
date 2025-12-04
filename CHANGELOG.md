# Changelog

All notable changes to `laravel-archived-prunables` will be documented in this file.

## [Unreleased]

## [1.0.0] - 2024-XX-XX

### Added
- Initial release
- `ArchivePrunedRecords` trait for models
- CSV and SQL export formats
- ZIP compression support
- S3 and local disk storage
- Custom filename generation via facade
- Before/after archiving callbacks
- `prunables:archive` artisan command for manual archiving
- Configurable failure behavior (abort vs continue)
- Support for Laravel 11 and 12
