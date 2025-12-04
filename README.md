# Prunekeeper 🍇 

**Your database's safety net.**

> **Don't just prune — preserve.** <br> Automatically archive old records to cloud storage before Laravel's
`model:prune` wipes them
> out.

## Installation

```bash
composer require helgeesverre/laravel-prunekeeper
```

Publish the configuration file:

```bash
php artisan vendor:publish --provider="HelgeSverre\Prunekeeper\PrunekeeperServiceProvider"
```

## Usage

Add the `ArchivePrunedRecords` trait to any model that uses Laravel's `Prunable` trait:

```php
<?php

namespace App\Models;

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

When you run `php artisan model:prune`, Prunekeeper will automatically:

1. Export matching records to CSV (or SQL)
2. Compress the export to ZIP
3. Upload to your configured storage disk
4. Allow Laravel to proceed with deletion

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
];
```

## Customization

### Custom Filename

```php
use HelgeSverre\Prunekeeper\Facades\Prunekeeper;

Prunekeeper::generateFilenameUsing(function ($model, $format) {
    return sprintf('archives/%s/%s-%s.%s',
        now()->format('Y/m'),
        now()->format('Y-m-d_His'),
        $model->getTable(),
        $format
    );
});
```

### Limit Exported Columns

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

### Before/After Callbacks

```php
Prunekeeper::beforeArchiving(function ($model) {
    Log::info("Starting archive for {$model->getTable()}");
});

Prunekeeper::afterArchiving(function ($model, $result) {
    Log::info("Archived {$result->recordCount} records to {$result->storagePath}");
});
```

### Conditionally Disable Archiving

```php
class Activity extends Model
{
    use Prunable, ArchivePrunedRecords;

    public function shouldArchiveBeforePruning(): bool
    {
        return app()->isProduction();
    }
}
```

## Manual Archiving

Archive records without deleting them:

```bash
# Archive all models with the trait
php artisan prunekeeper:archive

# Archive specific model
php artisan prunekeeper:archive --model="App\Models\Activity"

# Preview what would be archived
php artisan prunekeeper:archive --pretend

# Use SQL format
php artisan prunekeeper:archive --format=sql

# Skip compression
php artisan prunekeeper:archive --no-compress
```

## Scheduling

Add to your `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('model:prune', [
    '--model' => [Activity::class],
])->daily();
```

## Testing

```bash
composer test
```

## License

MIT License. See [LICENSE](LICENSE.md) for details.
