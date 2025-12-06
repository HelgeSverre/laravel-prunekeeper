# Prunekeeper - Agent Instructions

## Commands
- `just test` - Run all tests (requires Docker for database containers)
- `just test-unit` - Run unit tests only (no Docker needed)
- `just e2e` - Run end-to-end Laravel installation test
- `vendor/bin/pest --filter="test name"` - Run a single test
- `just format` - Format code with Laravel Pint
- `just analyse` - Run PHPStan level 6

## Architecture
Laravel package that archives Eloquent records before pruning. Static API pattern (like Telescope/Horizon).
- `src/Prunekeeper.php` - Main static class with all configuration callbacks
- `src/Exporters/` - CSV and SQL export implementations
- `src/Compression/` - Zip, Gzip, Bzip2, TarGzip drivers
- `src/Listeners/ArchiveBeforePruning.php` - Hooks into Laravel's ModelPruningStarting event
- `tests/` - Pest tests (Unit, Feature, Integration suites)

## Code Style
- PHP 8.2+, Laravel 11/12, strict types everywhere
- Use `Prunekeeper::` static calls, not dependency injection
- No comments in code unless complex logic requires explanation
- Follow existing patterns: look at neighboring files before adding new code
