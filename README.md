# Prunekeeper

**Archive prunable Eloquent records before deletion.**

[![Latest Version on Packagist](https://img.shields.io/packagist/v/helgeesverre/laravel-prunekeeper.svg?style=flat-square)](https://packagist.org/packages/helgeesverre/laravel-prunekeeper)
[![Total Downloads](https://img.shields.io/packagist/dt/helgeesverre/laravel-prunekeeper.svg?style=flat-square)](https://packagist.org/packages/helgeesverre/laravel-prunekeeper)
[![License](https://img.shields.io/packagist/l/helgeesverre/laravel-prunekeeper.svg?style=flat-square)](https://packagist.org/packages/helgeesverre/laravel-prunekeeper)

Laravel's `Prunable` trait lets you automatically clean up old database records. But once they're gone, they're gone forever.

Prunekeeper hooks into Laravel's pruning process to export records to CSV or SQL before deletion. Archives are compressed and uploaded to any Laravel filesystem disk (S3, local, etc.), giving you a safety net for compliance, auditing, or "just in case."

```php
class Flight extends Model
{
    use Prunable;
    use ArchivePrunedRecords; // Add this trait

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

## Installation

```bash
composer require helgeesverre/laravel-prunekeeper
```

Optionally publish the configuration:

```bash
php artisan vendor:publish --tag="prunekeeper-config"
```

**Requirements:** PHP 8.2+ and Laravel 11 or 12.

## Basic Usage

Add the `ArchivePrunedRecords` trait to any model that uses Laravel's `Prunable` or `MassPrunable` trait:

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use HelgeSverre\Prunekeeper\ArchivePrunedRecords;

class Flight extends Model
{
    use Prunable;
    use ArchivePrunedRecords;

    public function prunable(): Builder
    {
        return static::where('created_at', '<=', now()->subMonth());
    }
}
```

That's it. When Laravel prunes the model, Prunekeeper archives the records first.

### Using with MassPrunable

Works the same way with `MassPrunable` (bulk deletion without model events):

```php
use Illuminate\Database\Eloquent\MassPrunable;
use HelgeSverre\Prunekeeper\ArchivePrunedRecords;

class Flight extends Model
{
    use MassPrunable;
    use ArchivePrunedRecords;

    public function prunable(): Builder
    {
        return static::where('created_at', '<=', now()->subMonth());
    }
}
```

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

| Format            | Description                       | Best For                                       |
| ----------------- | --------------------------------- | ---------------------------------------------- |
| **CSV** (default) | Portable, database-agnostic       | General archiving, analytics, data portability |
| **SQL**           | MySQL/MariaDB `INSERT` statements | Direct database restoration (MySQL only)       |

> **Note:** SQL export uses MySQL-specific syntax. For PostgreSQL, SQLite, or SQL Server, use CSV.

## Artisan Commands

### Archive without deleting

Archive records without triggering deletion:

```bash
# Archive all models with the trait
php artisan prunekeeper:archive

# Archive a specific model
php artisan prunekeeper:archive --model="App\Models\Flight"

# Preview what would be archived
php artisan prunekeeper:archive --pretend

# Override format
php artisan prunekeeper:archive --format=sql

# Skip compression
php artisan prunekeeper:archive --no-compress
```

### Validate configuration

Validate that column configurations are correct:

```bash
php artisan prunekeeper:validate
```

Run this in CI/CD to catch configuration errors before deployment.

## Customization

### Export specific columns

By default, all columns are exported. To limit which columns are archived:

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

If you specify columns that don't exist, Prunekeeper throws an `InvalidColumnException` with a helpful message showing available columns.

### Exclude sensitive columns globally

Apply column filtering across all models:

```php
use HelgeSverre\Prunekeeper\Facades\Prunekeeper;

Prunekeeper::resolveColumnsUsing(function ($model) {
    $allColumns = Schema::getColumnListing($model->getTable());

    return array_diff($allColumns, [
        'password',
        'remember_token',
        'api_key',
        'ssn',
    ]);
});
```

### Custom filename

Override the default filename pattern globally:

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

Or per-model:

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

### Lifecycle hooks

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

### Disable archiving conditionally

Disable archiving for specific models or environments:

```php
class Flight extends Model
{
    use Prunable, ArchivePrunedRecords;

    public function shouldArchiveBeforePruning(): bool
    {
        return app()->isProduction();
    }
}
```

## Scheduling

Add pruning to your scheduler in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('model:prune')->daily();
```

Or in Laravel 11+ with `bootstrap/app.php`:

```php
->withSchedule(function (Schedule $schedule) {
    $schedule->command('model:prune')->daily();
})
```

Preview what will be pruned:

```bash
php artisan model:prune --pretend
```

## Performance

Prunekeeper handles large datasets efficiently:

- **Chunked processing**: Records are exported in configurable chunks (default: 1000)
- **Streamed uploads**: Files are streamed to storage, not loaded entirely into memory
- **Configurable chunk size**: Adjust `PRUNEKEEPER_CHUNK_SIZE` based on your constraints

For very large tables (millions of records):

- Run `prunekeeper:archive` during off-peak hours
- Use a dedicated queue worker for the prune command
- Increase `chunk_size` if memory allows (improves speed)

## Security

When archiving data that may contain sensitive information:

1. **Use column filtering**: Implement `getArchivableColumns()` or use `resolveColumnsUsing()` to exclude sensitive fields
2. **Use secure storage**: Configure your storage disk with appropriate access controls and encryption
3. **Run validation**: Use `prunekeeper:validate` in CI/CD to catch configuration errors

## Testing

```bash
composer test
```

## Changelog

See [CHANGELOG](CHANGELOG.md) for version history.

## Contributing

Contributions are welcome! Please see the repository for guidelines.

## Security

If you discover a security vulnerability, please email helge.sverre@gmail.com instead of using the issue tracker.

## Credits

- [Helge Sverre](https://github.com/HelgeSverre)

## License

The MIT License (MIT). See [LICENSE](LICENSE.md) for details.
