<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper;

use Closure;
use HelgeSverre\Prunekeeper\Support\ArchiveResult;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class Prunekeeper
{
    const string version = '1.0.0';

    protected ?Closure $filenameGenerator = null;

    protected ?Closure $columnsResolver = null;

    protected ?Closure $beforeArchive = null;

    protected ?Closure $afterArchive = null;

    protected ?Closure $tempFileGenerator = null;

    protected ?Closure $tableNameResolver = null;

    /**
     * Register a custom filename generator callback.
     *
     * @param  callable(Model, string): string  $callback
     */
    public function generateFilenameUsing(?callable $callback): self
    {
        $this->filenameGenerator = $callback;

        return $this;
    }

    /**
     * Register a custom columns resolver callback.
     *
     * @param  callable(Model): array<string>|null  $callback
     */
    public function resolveColumnsUsing(?callable $callback): self
    {
        $this->columnsResolver = $callback;

        return $this;
    }

    /**
     * Register a callback to run before archiving.
     *
     * @param  callable(Model): void  $callback
     */
    public function beforeArchiving(?callable $callback): self
    {
        $this->beforeArchive = $callback;

        return $this;
    }

    /**
     * Register a callback to run after archiving.
     *
     * @param  callable(Model, ArchiveResult): void  $callback
     */
    public function afterArchiving(?callable $callback): self
    {
        $this->afterArchive = $callback;

        return $this;
    }

    /**
     * Generate the storage filename for an archived model.
     */
    public function generateFilename(Model $model, string $format): string
    {
        if ($this->filenameGenerator) {
            return call_user_func($this->filenameGenerator, $model, $format);
        }

        $extension = config('prunekeeper.compress', true)
            ? "{$format}.zip"
            : $format;

        return sprintf(
            '%s/%s-%s.%s',
            config('prunekeeper.path', 'prunable-exports'),
            now()->format('Y-m-d_His'),
            $model->getTable(),
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
     * Fire the before archive callback.
     */
    public function fireBeforeArchive(Model $model): void
    {
        if ($this->beforeArchive) {
            call_user_func($this->beforeArchive, $model);
        }
    }

    /**
     * Fire the after archive callback.
     */
    public function fireAfterArchive(Model $model, ArchiveResult $result): void
    {
        if ($this->afterArchive) {
            call_user_func($this->afterArchive, $model, $result);
        }
    }

    /**
     * Register a custom temp file generator callback.
     *
     * @param  callable(string): string  $callback  Receives prefix, returns file path
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
     * @param  callable(Model): string  $callback
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
     * Get the configured chunk size.
     */
    public function getChunkSize(): int
    {
        return (int) config('prunekeeper.chunk_size', 1000);
    }

    /**
     * Check if compression is enabled.
     */
    public function shouldCompress(): bool
    {
        return (bool) config('prunekeeper.compress', true);
    }

    /**
     * Check if temp files should be cleaned up.
     */
    public function shouldCleanupTempFiles(): bool
    {
        return (bool) config('prunekeeper.cleanup_temp_files', true);
    }
}
