<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper;

use HelgeSverre\Prunekeeper\Compression\CompressionManager;
use HelgeSverre\Prunekeeper\Contracts\Archivable;
use HelgeSverre\Prunekeeper\Contracts\Exporter;
use HelgeSverre\Prunekeeper\Events\ArchiveCompleted;
use HelgeSverre\Prunekeeper\Events\ArchiveFailed;
use HelgeSverre\Prunekeeper\Events\ArchiveSkipped;
use HelgeSverre\Prunekeeper\Events\ArchiveStarting;
use HelgeSverre\Prunekeeper\Exceptions\InvalidColumnException;
use HelgeSverre\Prunekeeper\Exporters\CsvExporter;
use HelgeSverre\Prunekeeper\Exporters\SqlExporter;
use HelgeSverre\Prunekeeper\Support\ArchiveResult;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class Prunekeeper
{
    public const VERSION = '2.0.0';

    /** @var (callable(Model, string): string)|null */
    protected static $filenameGenerator = null;

    /** @var (callable(Model): (array<string>|null))|null */
    protected static $columnsResolver = null;

    /** @var (callable(Model): void)|null */
    protected static $beforeArchive = null;

    /** @var (callable(Model, ArchiveResult): void)|null */
    protected static $afterArchive = null;

    /** @var (callable(string): string)|null */
    protected static $tempFileGenerator = null;

    /** @var (callable(Model): string)|null */
    protected static $tableNameResolver = null;

    /**
     * Register a custom filename generator callback.
     *
     * @param  (callable(Model, string): string)|null  $callback
     */
    public static function generateFilenameUsing(?callable $callback): void
    {
        static::$filenameGenerator = $callback;
    }

    /**
     * Register a custom columns resolver callback.
     *
     * @param  (callable(Model): (array<string>|null))|null  $callback
     */
    public static function resolveColumnsUsing(?callable $callback): void
    {
        static::$columnsResolver = $callback;
    }

    /**
     * Register a callback to run before archiving.
     *
     * @param  (callable(Model): void)|null  $callback
     */
    public static function beforeArchiving(?callable $callback): void
    {
        static::$beforeArchive = $callback;
    }

    /**
     * Register a callback to run after archiving.
     *
     * @param  (callable(Model, ArchiveResult): void)|null  $callback
     */
    public static function afterArchiving(?callable $callback): void
    {
        static::$afterArchive = $callback;
    }

    /**
     * Register a custom temp file generator callback.
     *
     * @param  (callable(string): string)|null  $callback  Receives prefix, returns file path
     */
    public static function createTempFileUsing(?callable $callback): void
    {
        static::$tempFileGenerator = $callback;
    }

    /**
     * Register a custom table name resolver callback.
     *
     * @param  (callable(Model): string)|null  $callback
     */
    public static function resolveTableNameUsing(?callable $callback): void
    {
        static::$tableNameResolver = $callback;
    }

    /**
     * Generate the storage filename for an archived model.
     *
     * @param  bool|null  $compressed  Override compression setting (null uses config)
     */
    public static function generateFilename(Model $model, string $format, ?bool $compressed = null): string
    {
        if (static::$filenameGenerator) {
            return call_user_func(static::$filenameGenerator, $model, $format);
        }

        $compressed ??= static::shouldCompress();

        $extension = $compressed
            ? "{$format}.".static::getCompressionExtension()
            : $format;

        return sprintf(
            '%s/%s-%s-%s.%s',
            config('prunekeeper.path', 'prunable-exports'),
            now()->format('Y-m-d_His'),
            $model->getTable(),
            substr(uniqid(), -6),
            $extension
        );
    }

    /**
     * Resolve which columns to export for a model.
     *
     * @return array<string>|null
     */
    public static function resolveColumns(Model $model): ?array
    {
        if (method_exists($model, 'getArchivableColumns')) {
            $columns = $model->getArchivableColumns();

            if ($columns !== null) {
                return $columns;
            }
        }

        if (static::$columnsResolver) {
            return call_user_func(static::$columnsResolver, $model);
        }

        return null;
    }

    /**
     * Validate that the specified columns exist on the model's table.
     *
     * @param  array<string>  $columns
     *
     * @throws InvalidColumnException
     */
    public static function validateColumns(Model $model, array $columns): void
    {
        $table = $model->getTable();
        $connection = $model->getConnectionName();
        $actualColumns = Schema::connection($connection)->getColumnListing($table);

        $invalidColumns = array_diff($columns, $actualColumns);

        if (! empty($invalidColumns)) {
            throw new InvalidColumnException($model, array_values($invalidColumns), $actualColumns);
        }
    }

    /**
     * Fire the before archive callback and dispatch event.
     */
    public static function fireBeforeArchive(Model $model, int $recordCount = 0): void
    {
        if (static::$beforeArchive) {
            call_user_func(static::$beforeArchive, $model);
        }

        ArchiveStarting::dispatch($model, $recordCount);
    }

    /**
     * Fire the after archive callback and dispatch event.
     */
    public static function fireAfterArchive(Model $model, ArchiveResult $result): void
    {
        if (static::$afterArchive) {
            call_user_func(static::$afterArchive, $model, $result);
        }

        ArchiveCompleted::dispatch($model, $result);
    }

    /**
     * Fire the archive failed event.
     */
    public static function fireArchiveFailed(Model $model, Throwable $exception): void
    {
        ArchiveFailed::dispatch($model, $exception);
    }

    /**
     * Fire the archive skipped event.
     */
    public static function fireArchiveSkipped(Model $model, string $reason): void
    {
        ArchiveSkipped::dispatch($model, $reason);
    }

    /**
     * Create a temporary file for export.
     *
     * @throws RuntimeException
     */
    public static function createTempFile(string $prefix = 'prunekeeper_export_'): string
    {
        if (static::$tempFileGenerator) {
            return call_user_func(static::$tempFileGenerator, $prefix);
        }

        $tempFile = tempnam(sys_get_temp_dir(), $prefix);

        if ($tempFile === false) {
            throw new RuntimeException('Failed to create temporary file for export');
        }

        return $tempFile;
    }

    /**
     * Resolve the table name for a model.
     */
    public static function resolveTableName(Model $model): string
    {
        if (static::$tableNameResolver) {
            return call_user_func(static::$tableNameResolver, $model);
        }

        return $model->getTable();
    }

    /**
     * Get the configured file open mode.
     */
    public static function getFileOpenMode(): string
    {
        return config('prunekeeper.file_open_mode', 'w');
    }

    /**
     * Get the configured storage disk.
     */
    public static function disk(): Filesystem
    {
        return Storage::disk(config('prunekeeper.disk', 's3'));
    }

    /**
     * Check if archiving is enabled.
     */
    public static function isEnabled(): bool
    {
        return (bool) config('prunekeeper.enabled', true);
    }

    /**
     * Check if failures should be silent.
     */
    public static function shouldFailSilently(): bool
    {
        return (bool) config('prunekeeper.fail_silently', false);
    }

    /**
     * Get the configured export format.
     */
    public static function getFormat(): string
    {
        return config('prunekeeper.format', 'csv');
    }

    /**
     * Create an exporter instance for the given format.
     *
     * @throws InvalidArgumentException
     */
    public static function makeExporter(?string $format = null): Exporter
    {
        $format = strtolower($format ?? static::getFormat());

        return match ($format) {
            'csv' => app(CsvExporter::class),
            'sql' => app(SqlExporter::class),
            default => throw new InvalidArgumentException(
                "Invalid export format: {$format}. Use 'csv' or 'sql'."
            ),
        };
    }

    /**
     * Get the configured chunk size.
     *
     * Returns a value between 1 and 10000. Invalid or out-of-range
     * configuration values are clamped to this range, defaulting to 1000.
     */
    public static function getChunkSize(): int
    {
        $chunkSize = (int) config('prunekeeper.chunk_size', 1000);

        if ($chunkSize < 1) {
            return 1000;
        }

        if ($chunkSize > 10000) {
            return 10000;
        }

        return $chunkSize;
    }

    /**
     * Check if compression is enabled.
     */
    public static function shouldCompress(): bool
    {
        return (bool) config('prunekeeper.compression.enabled', true);
    }

    /**
     * Get the compression manager instance.
     */
    public static function compression(): CompressionManager
    {
        return app(CompressionManager::class);
    }

    /**
     * Get the compression extension for the current driver.
     */
    public static function getCompressionExtension(): string
    {
        return static::compression()->driver()->extension();
    }

    /**
     * Check if temp files should be cleaned up.
     */
    public static function shouldCleanupTempFiles(): bool
    {
        return (bool) config('prunekeeper.cleanup_temp_files', true);
    }

    /**
     * Build the prunable query for a model, including soft-deleted records if applicable.
     *
     * Models must use the Prunable or MassPrunable trait.
     *
     * @return Builder<Model>
     */
    public static function makePrunableQuery(Model $model): Builder
    {
        /** @var Builder<Model> $query */
        $query = $model->prunable(); // @phpstan-ignore method.notFound (Model uses Prunable or MassPrunable trait)

        if (in_array(SoftDeletes::class, class_uses_recursive($model::class))) {
            $query->withTrashed(); // @phpstan-ignore method.notFound (Model uses SoftDeletes trait)
        }

        return $query;
    }

    /**
     * Archive records from a prunable query.
     *
     * @param  Model&Archivable  $model
     * @param  Builder<Model>  $query
     * @param  callable(string): void|null  $onCleanupError  Callback when temp file cleanup fails
     */
    public static function archive(
        Model $model,
        Builder $query,
        Exporter $exporter,
        bool $shouldCompress = true,
        ?callable $onCleanupError = null
    ): ArchiveResult {
        $columns = static::resolveColumns($model);
        $recordCount = $query->count();

        $tempFile = null;
        $compressedFile = null;
        $stream = null;

        try {
            $tempFile = $exporter->export(clone $query, $columns);
            $format = $exporter->extension();

            if (! file_exists($tempFile) || filesize($tempFile) === 0) {
                $modelClass = $model::class;
                throw new RuntimeException(
                    "Export produced empty file for {$modelClass}. Expected {$recordCount} records."
                );
            }

            $filename = $model->getArchiveFilename($format)
                ?? static::generateFilename($model, $format, $shouldCompress);

            $compressionExtension = $shouldCompress ? static::getCompressionExtension() : null;

            if ($shouldCompress && $compressionExtension && ! str_ends_with($filename, '.'.$compressionExtension)) {
                $filename .= '.'.$compressionExtension;
            }

            $fileToUpload = $tempFile;

            if ($shouldCompress) {
                $compressedFile = static::compression()->driver()->compress($tempFile, 'export.'.$format);
                $fileToUpload = $compressedFile;
            }

            $fileSize = filesize($fileToUpload) ?: 0;

            $stream = fopen($fileToUpload, 'r');

            if ($stream === false) {
                $error = error_get_last();
                throw new RuntimeException(
                    "Failed to open temporary file: {$fileToUpload}. ".
                    'Error: '.($error['message'] ?? 'Unknown error')
                );
            }

            $uploaded = static::disk()->put($filename, $stream);

            if ($uploaded === false) {
                throw new RuntimeException("Failed to upload archive to storage: {$filename}");
            }

            return new ArchiveResult(
                modelClass: $model::class,
                storagePath: $filename,
                recordCount: $recordCount,
                fileSize: $fileSize,
                format: $format,
                compressed: $shouldCompress
            );
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }

            if (static::shouldCleanupTempFiles()) {
                self::cleanupTempFile($tempFile, $onCleanupError);
                self::cleanupTempFile($compressedFile, $onCleanupError);
            }
        }
    }

    /**
     * Reset all callbacks (for testing).
     *
     * @internal
     */
    public static function flushState(): void
    {
        static::$filenameGenerator = null;
        static::$columnsResolver = null;
        static::$beforeArchive = null;
        static::$afterArchive = null;
        static::$tempFileGenerator = null;
        static::$tableNameResolver = null;
    }

    /**
     * Clean up a temporary file.
     */
    private static function cleanupTempFile(?string $file, ?callable $onError): void
    {
        if ($file === null || ! file_exists($file)) {
            return;
        }

        if (! @unlink($file) && $onError !== null) {
            $onError($file);
        }
    }
}
