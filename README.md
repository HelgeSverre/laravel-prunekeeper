# Prunekeeper

Automatically archive Laravel Prunable records to CSV or SQL before deletion.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/helgeesverre/laravel-prunekeeper.svg?style=flat-square)](https://packagist.org/packages/helgeesverre/laravel-prunekeeper)
[![Total Downloads](https://img.shields.io/packagist/dt/helgeesverre/laravel-prunekeeper.svg?style=flat-square)](https://packagist.org/packages/helgeesverre/laravel-prunekeeper)
[![License](https://img.shields.io/packagist/l/helgeesverre/laravel-prunekeeper.svg?style=flat-square)](https://packagist.org/packages/helgeesverre/laravel-prunekeeper)

## Installation

```bash
composer require helgeesverre/laravel-prunekeeper
```

Publish the configuration file:

```bash
php artisan vendor:publish --provider="HelgeSverre\Prunekeeper\PrunekeeperServiceProvider"
```

## Quick Start

Add the `ArchivePrunedRecords` trait to any model using Laravel's `Prunable` trait:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use HelgeSverre\Prunekeeper\ArchivePrunedRecords;

class Activity extends Model
{
    use Prunable;
    use ArchivePrunedRecords;

    public function prunable(): Builder
    {
        return static::where('created_at', '<=', now()->subYear());
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

## Artisan Commands

### Archive Records

Archive records without deleting them:

```bash
# Archive all models with the trait
php artisan prunekeeper:archive

# Archive specific model
php artisan prunekeeper:archive --model="App\Models\Activity"

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
php artisan prunekeeper:validate --model="App\Models\Activity"
```

This command checks that any custom columns specified via `getArchivableColumns()` actually exist in the database. Run this in CI/CD or before deployments to catch configuration errors early.

## Customization

### Limit Exported Columns

Export only specific columns instead of all columns:

```php
class Activity extends Model
{
    use Prunable, ArchivePrunedRecords;

    public function getArchivableColumns(): ?array
    {
        return ['id', 'log_name', 'description', 'created_at'];
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
class Activity extends Model
{
    use Prunable, ArchivePrunedRecords;

    public function getArchiveFilename(string $format): ?string
    {
        return "activity-logs/{$this->getTable()}-" . now()->format('Y-m-d') . ".{$format}";
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
class Activity extends Model
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

Schedule::command('model:prune', [
    '--model' => [Activity::class, AuditLog::class],
])->daily();
```

Or in Laravel 11+ with the scheduler in `bootstrap/app.php`:

```php
->withSchedule(function (Schedule $schedule) {
    $schedule->command('model:prune')->daily();
})
```

## Testing

```bash
composer test
```

## License

MIT License. See [LICENSE](LICENSE.md) for details.
