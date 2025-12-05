<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper\Commands;

use HelgeSverre\Prunekeeper\ArchivePrunedRecords;
use HelgeSverre\Prunekeeper\Contracts\Archivable;
use HelgeSverre\Prunekeeper\Contracts\Exporter;
use HelgeSverre\Prunekeeper\Exporters\CsvExporter;
use HelgeSverre\Prunekeeper\Exporters\SqlExporter;
use HelgeSverre\Prunekeeper\Prunekeeper;
use HelgeSverre\Prunekeeper\Support\ArchiveResult;
use HelgeSverre\Prunekeeper\Support\FileCompressor;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Finder\Finder;

class ArchiveCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'prunekeeper:archive
        {--model=* : The model(s) to archive}
        {--format= : Export format (csv or sql)}
        {--pretend : Display the number of records that would be archived}
        {--no-compress : Disable compression}';

    /**
     * The console command description.
     */
    protected $description = 'Archive prunable model records without deleting them';

    public function __construct(
        protected Prunekeeper $prunekeeper,
        protected FileCompressor $compressor
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $models = $this->getModels();

        if ($models->isEmpty()) {
            $this->components->warn('No archivable models found.');

            return self::SUCCESS;
        }

        $this->components->info('Archiving prunable records...');

        $archived = 0;

        foreach ($models as $modelClass) {
            $result = $this->archiveModel($modelClass);

            if ($result !== null) {
                $archived++;
            }
        }

        if ($archived === 0) {
            $this->components->info('No records to archive.');
        }

        return self::SUCCESS;
    }

    /**
     * Get the models to archive.
     *
     * @return Collection<int, class-string>
     */
    protected function getModels(): Collection
    {
        $models = $this->option('model');

        if (! empty($models)) {
            return collect($models)->filter(function ($model) {
                if (! class_exists($model)) {
                    $this->components->error("Model class not found: {$model}");

                    return false;
                }

                if (! in_array(ArchivePrunedRecords::class, class_uses_recursive($model))) {
                    $this->components->warn("Model does not use ArchivePrunedRecords trait: {$model}");

                    return false;
                }

                return true;
            });
        }

        // Auto-discover models with ArchivePrunedRecords trait
        return $this->discoverModels();
    }

    /**
     * Discover models that use the ArchivePrunedRecords trait.
     *
     * @return Collection<int, class-string>
     */
    protected function discoverModels(): Collection
    {
        $modelsPath = app_path('Models');

        if (! File::isDirectory($modelsPath)) {
            return collect();
        }

        $finder = (new Finder)->files()->name('*.php')->in($modelsPath);

        return collect($finder)
            ->map(function ($file) {
                $className = 'App\\Models\\'.str_replace(
                    ['/', '.php'],
                    ['\\', ''],
                    $file->getRelativePathname()
                );

                return class_exists($className) ? $className : null;
            })
            ->filter()
            ->filter(function ($className) {
                return in_array(ArchivePrunedRecords::class, class_uses_recursive($className));
            })
            ->values();
    }

    /**
     * Archive a single model.
     *
     * @param  class-string  $modelClass
     */
    protected function archiveModel(string $modelClass): ?ArchiveResult
    {
        /** @var Model&Archivable $model */
        $model = new $modelClass;

        if (! method_exists($model, 'prunable')) {
            $this->components->warn("{$modelClass} does not have a prunable() method.");

            return null;
        }

        if (! $model->shouldArchiveBeforePruning()) {
            $this->components->warn("{$modelClass} has archiving disabled.");

            return null;
        }

        $query = $model->prunable();

        // Include soft-deleted records if applicable
        if (in_array(SoftDeletes::class, class_uses_recursive($modelClass))) {
            $query->withTrashed();
        }

        $count = $query->count();

        if ($count === 0) {
            $this->components->info("{$modelClass}: No prunable records found.");

            return null;
        }

        if ($this->option('pretend')) {
            $this->components->info("{$modelClass}: {$count} records would be archived.");

            return null;
        }

        $result = null;

        $this->components->task("Archiving {$count} records from {$modelClass}", function () use ($model, $query, &$result) {
            $result = $this->performArchive($model, clone $query);
        });

        if ($result !== null) {
            $this->components->bulletList([
                "Path: {$result->storagePath}",
                "Records: {$result->recordCount}",
                "Size: {$result->humanFileSize()}",
            ]);
        }

        return $result;
    }

    /**
     * Perform the archive operation.
     *
     * @param  Model&Archivable  $model
     * @param  Builder<Model>  $query
     */
    protected function performArchive(Model $model, $query): ArchiveResult
    {
        $exporter = $this->getExporter();
        $columns = $this->prunekeeper->resolveColumns($model);
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

            // Compress if enabled
            $shouldCompress = ! $this->option('no-compress') && $this->prunekeeper->shouldCompress();

            // Determine the storage filename
            $filename = $model->getArchiveFilename($format)
                ?? $this->prunekeeper->generateFilename($model, $format, $shouldCompress);

            $fileToUpload = $tempFile;

            if ($shouldCompress) {
                $compressedFile = $this->compressor->compress($tempFile, $format);
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

            $uploaded = $this->prunekeeper->disk()->put($filename, $stream);

            if ($uploaded === false) {
                throw new RuntimeException("Failed to upload archive to storage: {$filename}");
            }

            return new ArchiveResult(
                modelClass: get_class($model),
                storagePath: $filename,
                recordCount: $recordCount,
                fileSize: $fileSize,
                format: $format,
                compressed: $shouldCompress
            );
        } finally {
            // Always close stream
            if (is_resource($stream)) {
                fclose($stream);
            }

            // Cleanup temporary files
            if ($this->prunekeeper->shouldCleanupTempFiles()) {
                if ($tempFile !== null && file_exists($tempFile)) {
                    @unlink($tempFile);
                }
                if ($compressedFile !== null && file_exists($compressedFile)) {
                    @unlink($compressedFile);
                }
            }
        }
    }

    /**
     * Get the exporter instance based on the format option.
     */
    protected function getExporter(): Exporter
    {
        $format = $this->option('format') ?: $this->prunekeeper->getFormat();

        return match ($format) {
            'sql' => app(SqlExporter::class),
            'csv' => app(CsvExporter::class),
            default => throw new \InvalidArgumentException("Invalid export format: {$format}. Use 'csv' or 'sql'."),
        };
    }
}
