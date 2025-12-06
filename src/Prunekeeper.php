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
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class Prunekeeper
{
    public const VERSION = '1.0.0';

    /** @var (callable(Model, string): string)|null */
    protected $filenameGenerator = null;

    /** @var (callable(Model): (array<string>|null))|null */
    protected $columnsResolver = null;

    /** @var (callable(Model): void)|null */
    protected $beforeArchive = null;

    /** @var (callable(Model, ArchiveResult): void)|null */
    protected $afterArchive = null;

    /** @var (callable(string): string)|null */
    protected $tempFileGenerator = null;

    /** @var (callable(Model): string)|null */
    protected $tableNameResolver = null;

    /**
     * Register a custom filename generator callback.
     *
     * @param  (callable(Model, string): string)|null  $callback
     * @return $this
     */
    public function generateFilenameUsing(?callable $callback): self
    {
        $this->filenameGenerator = $callback;

        return $this;
    }

    /**
     * Register a custom columns resolver callback.
     *
     * @param  (callable(Model): (array<string>|null))|null  $callback
     * @return $this
     */
    public function resolveColumnsUsing(?callable $callback): self
    {
        $this->columnsResolver = $callback;

        return $this;
    }

    /**
     * Register a callback to run before archiving.
     *
     * @param  (callable(Model): void)|null  $callback
     * @return $this
     */
    public function beforeArchiving(?callable $callback): self
    {
        $this->beforeArchive = $callback;

        return $this;
    }

    /**
     * Register a callback to run after archiving.
     *
     * @param  (callable(Model, ArchiveResult): void)|null  $callback
     * @return $this
     */
    public function afterArchiving(?callable $callback): self
    {
        $this->afterArchive = $callback;

        return $this;
    }

    /**
     * Generate the storage filename for an archived model.
     *
     * @param  bool|null  $compressed  Override compression setting (null uses config)
     */
    public function generateFilename(Model $model, string $format, ?bool $compressed = null): string
    {
        if ($this->filenameGenerator) {
            return call_user_func($this->filenameGenerator, $model, $format);
        }

        $compressed ??= $this->shouldCompress();

        $extension = $compressed
            ? "{$format}.{$this->getCompressionExtension()}"
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
    public function resolveColumns(Model $model): ?array
    {
        // Check if model defines custom columns (returns non-null array)
        if (method_exists($model, 'getArchivableColumns')) {
            $columns = $model->getArchivableColumns();

            if ($columns !== null) {
                return $columns;
            }
        }

        // Fall back to resolver callback if set
        if ($this->columnsResolver) {
            return call_user_func($this->columnsResolver, $model);
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
    public function validateColumns(Model $model, array $columns): void
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
    public function fireBeforeArchive(Model $model, int $recordCount = 0): void
    {
        // Fire legacy callback (backward compatible)
        if ($this->beforeArchive) {
            call_user_func($this->beforeArchive, $model);
        }

        // Dispatch Laravel event
        ArchiveStarting::dispatch($model, $recordCount);
    }

    /**
     * Fire the after archive callback and dispatch event.
     */
    public function fireAfterArchive(Model $model, ArchiveResult $result): void
    {
        // Fire legacy callback (backward compatible)
        if ($this->afterArchive) {
            call_user_func($this->afterArchive, $model, $result);
        }

        // Dispatch Laravel event
        ArchiveCompleted::dispatch($model, $result);
    }

    /**
     * Fire the archive failed event.
     */
    public function fireArchiveFailed(Model $model, Throwable $exception): void
    {
        ArchiveFailed::dispatch($model, $exception);
    }

    /**
     * Fire the archive skipped event.
     */
    public function fireArchiveSkipped(Model $model, string $reason): void
    {
        ArchiveSkipped::dispatch($model, $reason);
    }

    /**
     * Register a custom temp file generator callback.
     *
     * @param  (callable(string): string)|null  $callback  Receives prefix, returns file path
     * @return $this
     */
    public function createTempFileUsing(?callable $callback): self
    {
        $this->tempFileGenerator = $callback;

        return $this;
    }

    /**
     * Create a temporary file for export.
     *
     * @throws RuntimeException
     */
    public function createTempFile(string $prefix = 'prunekeeper_export_'): string
    {
        if ($this->tempFileGenerator) {
            return call_user_func($this->tempFileGenerator, $prefix);
        }

        $tempFile = tempnam(sys_get_temp_dir(), $prefix);

        if ($tempFile === false) {
            throw new RuntimeException('Failed to create temporary file for export');
        }

        return $tempFile;
    }

    /**
     * Register a custom table name resolver callback.
     *
     * @param  (callable(Model): string)|null  $callback
     * @return $this
     */
    public function resolveTableNameUsing(?callable $callback): self
    {
        $this->tableNameResolver = $callback;

        return $this;
    }

    /**
     * Resolve the table name for a model.
     */
    public function resolveTableName(Model $model): string
    {
        if ($this->tableNameResolver) {
            return call_user_func($this->tableNameResolver, $model);
        }

        return $model->getTable();
    }

    /**
     * Get the configured file open mode.
     */
    public function getFileOpenMode(): string
    {
        return config('prunekeeper.file_open_mode', 'w');
    }

    /**
     * Get the configured storage disk.
     */
    public function disk(): Filesystem
    {
        return Storage::disk(config('prunekeeper.disk', 's3'));
    }

    /**
     * Check if archiving is enabled.
     */
    public function isEnabled(): bool
    {
        return (bool) config('prunekeeper.enabled', true);
    }

    /**
     * Check if failures should be silent.
     */
    public function shouldFailSilently(): bool
    {
        return (bool) config('prunekeeper.fail_silently', false);
    }

    /**
     * Get the configured export format.
     */
    public function getFormat(): string
    {
        return config('prunekeeper.format', 'csv');
    }

    /**
     * Create an exporter instance for the given format.
     *
     * @throws InvalidArgumentException
     */
    public function makeExporter(?string $format = null): Exporter
    {
        $format = strtolower($format ?? $this->getFormat());

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
    public function getChunkSize(): int
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
    public function shouldCompress(): bool
    {
        return (bool) config('prunekeeper.compression.enabled', true);
    }

    /**
     * Get the compression manager instance.
     */
    public function compression(): CompressionManager
    {
        return app(CompressionManager::class);
    }

    /**
     * Get the compression extension for the current driver.
     */
    public function getCompressionExtension(): string
    {
        return $this->compression()->driver()->extension();
    }

    /**
     * Check if temp files should be cleaned up.
     */
    public function shouldCleanupTempFiles(): bool
    {
        return (bool) config('prunekeeper.cleanup_temp_files', true);
    }

    /**
     * Archive records from a prunable query.
     *
     * @param  Model&Archivable  $model
     * @param  Builder<Model>  $query
     * @param  callable(string): void|null  $onCleanupError  Callback when temp file cleanup fails
     */
    public function archive(
        Model $model,
        Builder $query,
        Exporter $exporter,
        bool $shouldCompress = true,
        ?callable $onCleanupError = null
    ): ArchiveResult {
        $columns = $this->resolveColumns($model);
        $recordCount = $query->count();

        $tempFile = null;
        $compressedFile = null;
        $stream = null;

        try {
            // Export to temporary file
            $tempFile = $exporter->export(clone $query, $columns);
            $format = $exporter->extension();

            // Validate export produced content
            if (! file_exists($tempFile) || filesize($tempFile) === 0) {
                $modelClass = $model::class;
                throw new RuntimeException(
                    "Export produced empty file for {$modelClass}. Expected {$recordCount} records."
                );
            }

            // Determine the storage filename
            $filename = $model->getArchiveFilename($format)
                ?? $this->generateFilename($model, $format, $shouldCompress);

            // Get compression extension and append to custom filenames when compression is enabled
            $compressionExtension = $shouldCompress ? $this->getCompressionExtension() : null;

            if ($shouldCompress && $compressionExtension && ! str_ends_with($filename, '.'.$compressionExtension)) {
                $filename .= '.'.$compressionExtension;
            }

            $fileToUpload = $tempFile;

            if ($shouldCompress) {
                $compressedFile = $this->compression()->driver()->compress($tempFile, 'export.'.$format);
                $fileToUpload = $compressedFile;
            }

            // Get file size before upload
            $fileSize = filesize($fileToUpload) ?: 0;

            // Upload to storage using stream for memory efficiency
            $stream = fopen($fileToUpload, 'r');

            if ($stream === false) {
                $error = error_get_last();
                throw new RuntimeException(
                    "Failed to open temporary file: {$fileToUpload}. ".
                    'Error: '.($error['message'] ?? 'Unknown error')
                );
            }

            $uploaded = $this->disk()->put($filename, $stream);

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

            if ($this->shouldCleanupTempFiles()) {
                $this->cleanupTempFile($tempFile, $onCleanupError);
                $this->cleanupTempFile($compressedFile, $onCleanupError);
            }
        }
    }

    /**
     * Clean up a temporary file.
     */
    private function cleanupTempFile(?string $file, ?callable $onError): void
    {
        if ($file === null || ! file_exists($file)) {
            return;
        }

        if (! @unlink($file) && $onError !== null) {
            $onError($file);
        }
    }
}
