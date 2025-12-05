# Prunekeeper

Automatically archive Laravel Prunable records to CSV or SQL before deletion.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/helgeesverre/laravel-prunekeeper.svg?style=flat-square)](https://packagist.org/packages/helgeesverre/laravel-prunekeeper)
[![Total Downloads](https://img.shields.io/packagist/dt/helgeesverre/laravel-prunekeeper.svg?style=flat-square)](https://packagist.org/packages/helgeesverre/laravel-prunekeeper)
[![License](https://img.shields.io/packagist/l/helgeesverre/laravel-prunekeeper.svg?style=flat-square)](https://packagist.org/packages/helgeesverre/laravel-prunekeeper)

## Requirements

- PHP 8.2+
- Laravel 11.x or 12.x

## Installation

```bash
composer require helgeesverre/laravel-prunekeeper
```

Publish the configuration file:

```bash
php artisan vendor:publish --provider="HelgeSverre\Prunekeeper\PrunekeeperServiceProvider"
```

## Quick Start

Add the `ArchivePrunedRecords` trait to any model using Laravel's `Prunable` or `MassPrunable` trait:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use HelgeSverre\Prunekeeper\ArchivePrunedRecords;

class Flight extends Model
{
    use Prunable;
    use ArchivePrunedRecords;

    /**
     * Get the prunable model query.
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<=', now()->subMonth());
    }

    /**
     * Prepare the model for pruning.
     */
    protected function pruning(): void
    {
        // Delete associated files, etc.
    }
}
```

### With MassPrunable

For models that use `MassPrunable` (bulk deletion without model events), Prunekeeper works the same way:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\MassPrunable;
use HelgeSverre\Prunekeeper\ArchivePrunedRecords;

class Flight extends Model
{
    use MassPrunable;
    use ArchivePrunedRecords;

    /**
     * Get the prunable model query.
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<=', now()->subMonth());
    }
}
```

When you run `php artisan model:prune`, Prunekeeper automatically:

1. Exports matching records to CSV (or SQL)
2. Compresses the export to ZIP
3. Uploads to your configured storage disk
4. Allows Laravel to proceed with deletion

## Configuration

```php
return [
    // Storage disk (any Laravel filesystem disk)
    'disk' => env('PRUNEKEEPER_DISK', 's3'),

    // Base path for archived files
    'path' => env('PRUNEKEEPER_PATH', 'prunable-exports'),

    // Export format: 'csv' or 'sql'
    'format' => env('PRUNEKEEPER_FORMAT', 'csv'),

    // Compress exports using ZIP
    'compress' => env('PRUNEKEEPER_COMPRESS', true),

    // Records per chunk when exporting
    'chunk_size' => env('PRUNEKEEPER_CHUNK_SIZE', 1000),

    // Enable/disable archiving globally
    'enabled' => env('PRUNEKEEPER_ENABLED', true),

    // Continue pruning if archiving fails
    'fail_silently' => env('PRUNEKEEPER_FAIL_SILENTLY', false),

    // Clean up temporary files after upload
    'cleanup_temp_files' => true,
];
```

### Export Formats

- **CSV** (default): Portable, database-agnostic format. Recommended for most use cases.
- **SQL**: Generates MySQL/MariaDB-compatible `INSERT` statements. Useful when you need to restore data directly to a MySQL database.

> **Note:** The SQL export format uses MySQL-specific syntax (backtick-quoted identifiers). For PostgreSQL, SQLite, or SQL Server databases, use the CSV format instead.

## Artisan Commands

### Archive Records

Archive records without deleting them:

```bash
# Archive all models with the trait
php artisan prunekeeper:archive

# Archive specific model
php artisan prunekeeper:archive --model="App\Models\Flight"

# Preview what would be archived
php artisan prunekeeper:archive --pretend

# Use SQL format instead of CSV
php artisan prunekeeper:archive --format=sql

# Skip compression
php artisan prunekeeper:archive --no-compress
```

### Validate Configuration

Validate that all archivable models have valid column configurations:

```bash
# Validate all models
php artisan prunekeeper:validate

# Validate specific model
php artisan prunekeeper:validate --model="App\Models\Flight"
```

This command checks that any custom columns specified via `getArchivableColumns()` actually exist in the database. Run this in CI/CD or before deployments to catch configuration errors early.

## Customization

### Limit Exported Columns

Export only specific columns instead of all columns:

```php
class Flight extends Model
{
    use Prunable, ArchivePrunedRecords;

    public function getArchivableColumns(): ?array
    {
        return ['id', 'number', 'destination', 'created_at'];
    }
}
```

**Note:** If you specify columns that don't exist in the database, Prunekeeper throws an `InvalidColumnException` with a helpful message showing the invalid columns and available columns. This prevents silent data loss from typos.

### Custom Filename

Override the default filename pattern:

```php
use HelgeSverre\Prunekeeper\Facades\Prunekeeper;

// In a service provider's boot() method
Prunekeeper::generateFilenameUsing(function ($model, $format) {
    return sprintf('archives/%s/%s-%s.%s',
        now()->format('Y/m'),
        now()->format('Y-m-d_His'),
        $model->getTable(),
        $format
    );
});
```

Or override per-model:

```php
class Flight extends Model
{
    use Prunable, ArchivePrunedRecords;

    public function getArchiveFilename(string $format): ?string
    {
        return "flight-logs/{$this->getTable()}-" . now()->format('Y-m-d') . ".{$format}";
    }
}
```

### Before/After Callbacks

Hook into the archiving process:

```php
use HelgeSverre\Prunekeeper\Facades\Prunekeeper;

Prunekeeper::beforeArchiving(function ($model) {
    Log::info("Starting archive for {$model->getTable()}");
});

Prunekeeper::afterArchiving(function ($model, $result) {
    Log::info("Archived {$result->recordCount} records to {$result->storagePath}");

    // Send notification, update metrics, etc.
});
```

### Conditionally Disable Archiving

Disable archiving for specific models or environments:

```php
class Flight extends Model
{
    use Prunable, ArchivePrunedRecords;

    public function shouldArchiveBeforePruning(): bool
    {
        // Only archive in production
        return app()->isProduction();
    }
}
```

### Global Column Resolver

Apply column filtering globally instead of per-model:

```php
Prunekeeper::resolveColumnsUsing(function ($model) {
    // Exclude sensitive columns from all exports
    $allColumns = Schema::getColumnListing($model->getTable());

    return array_diff($allColumns, ['password', 'remember_token', 'api_key']);
});
```

## Scheduling

Add to your `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('model:prune')->daily();
```

Or in Laravel 11+ with the scheduler in `bootstrap/app.php`:

```php
->withSchedule(function (Schedule $schedule) {
    $schedule->command('model:prune')->daily();
})
```

You may test your `prunable` query by executing the `model:prune` command with the `--pretend` option:

```bash
php artisan model:prune --pretend
```

## Performance Considerations

Prunekeeper is designed to handle large datasets efficiently:

- **Chunked processing**: Records are exported in configurable chunks (default: 1000) to limit memory usage
- **Streamed uploads**: Files are uploaded using streams, not loaded entirely into memory
- **Configurable chunk size**: Adjust `PRUNEKEEPER_CHUNK_SIZE` based on your memory constraints and record size

For very large tables (millions of records), consider:

- Running `prunekeeper:archive` during off-peak hours
- Using a dedicated queue worker for the prune command
- Increasing `chunk_size` if you have available memory (improves speed)

## Security Recommendations

When archiving data that may contain sensitive information:

1. **Use column filtering**: Implement `getArchivableColumns()` to exclude sensitive fields like passwords, tokens, or PII
2. **Use secure storage**: Configure your storage disk with appropriate access controls
3. **Run validation**: Use `prunekeeper:validate` in your CI/CD pipeline to catch configuration errors

```php
Prunekeeper::resolveColumnsUsing(function ($model) {
    $sensitiveColumns = ['password', 'remember_token', 'api_key', 'ssn'];
    $allColumns = Schema::getColumnListing($model->getTable());

    return array_diff($allColumns, $sensitiveColumns);
});
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## License

MIT License. See [LICENSE](LICENSE.md) for details.
