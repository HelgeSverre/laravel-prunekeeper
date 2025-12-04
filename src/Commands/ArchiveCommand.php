<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper\Commands;

use HelgeSverre\Prunekeeper\ArchivePrunedRecords;
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
     */
    protected function archiveModel(string $modelClass): ?ArchiveResult
    {
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
     * @param  Model  $model
     * @param  Builder<Model>  $query
     */
    protected function performArchive($model, $query): ArchiveResult
    {
        $exporter = $this->getExporter();
        $columns = $this->prunekeeper->resolveColumns($model);
        $recordCount = $query->count();

        // Export to temporary file
        $tempFile = $exporter->export(clone $query, $columns);
        $format = $exporter->extension();

        // Determine the storage filename
        $filename = $model->getArchiveFilename($format)
            ?? $this->prunekeeper->generateFilename($model, $format);

        // Compress if enabled
        $shouldCompress = ! $this->option('no-compress') && $this->prunekeeper->shouldCompress();

        if ($shouldCompress) {
            $compressedFile = $this->compressor->compress($tempFile, $format);

            if ($this->prunekeeper->shouldCleanupTempFiles()) {
                @unlink($tempFile);
            }

            $tempFile = $compressedFile;
        }

        // Get file size before upload
        $fileSize = filesize($tempFile) ?: 0;

        // Upload to storage
        $contents = file_get_contents($tempFile);

        if ($contents === false) {
            throw new RuntimeException("Failed to read temporary file: {$tempFile}");
        }

        $this->prunekeeper->disk()->put($filename, $contents);

        // Cleanup temporary file
        if ($this->prunekeeper->shouldCleanupTempFiles()) {
            @unlink($tempFile);
        }

        return new ArchiveResult(
            modelClass: get_class($model),
            storagePath: $filename,
            recordCount: $recordCount,
            fileSize: $fileSize,
            format: $format,
            compressed: $shouldCompress
        );
    }

    /**
     * Get the exporter instance based on the format option.
     */
    protected function getExporter(): Exporter
    {
        $format = $this->option('format') ?: $this->prunekeeper->getFormat();

        return match ($format) {
            'sql' => app(SqlExporter::class),
            default => app(CsvExporter::class),
        };
    }
}
