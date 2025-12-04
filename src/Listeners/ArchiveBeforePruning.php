<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper\Listeners;

use HelgeSverre\Prunekeeper\ArchivePrunedRecords;
use HelgeSverre\Prunekeeper\Contracts\Exporter;
use HelgeSverre\Prunekeeper\Prunekeeper;
use HelgeSverre\Prunekeeper\Support\ArchiveResult;
use HelgeSverre\Prunekeeper\Support\FileCompressor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Events\ModelPruningStarting;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ArchiveBeforePruning
{
    public function __construct(
        protected Prunekeeper $archivedPrunables,
        protected Exporter $exporter,
        protected FileCompressor $compressor
    ) {}

    /**
     * Handle the ModelPruningStarting event.
     */
    public function handle(ModelPruningStarting $event): void
    {
        if (! $this->archivedPrunables->isEnabled()) {
            return;
        }

        foreach ($event->models as $modelClass) {
            $this->archiveModel($modelClass);
        }
    }

    /**
     * Archive a single model class if it uses the ArchivePrunedRecords trait.
     */
    protected function archiveModel(string $modelClass): void
    {
        if (! in_array(ArchivePrunedRecords::class, class_uses_recursive($modelClass))) {
            return;
        }

        $model = new $modelClass;

        if (! $model->shouldArchiveBeforePruning()) {
            Log::debug("[Prunekeeper] Skipping {$modelClass} - archiving disabled");

            return;
        }

        $query = $model->prunable();

        // Include soft-deleted records if the model uses SoftDeletes
        if (in_array(SoftDeletes::class, class_uses_recursive($modelClass))) {
            $query->withTrashed();
        }

        $count = $query->count();

        if ($count === 0) {
            Log::debug("[Prunekeeper] No prunable records for {$modelClass}");

            return;
        }

        Log::info("[Prunekeeper] Archiving {$count} records from {$modelClass}");

        $this->archivedPrunables->fireBeforeArchive($model);

        try {
            $result = $this->performArchive($model, clone $query);
            $this->archivedPrunables->fireAfterArchive($model, $result);

            Log::info("[Prunekeeper] Successfully archived {$modelClass}", [
                'path' => $result->storagePath,
                'records' => $result->recordCount,
                'size' => $result->humanFileSize(),
            ]);
        } catch (Throwable $e) {
            Log::error("[Prunekeeper] Failed to archive {$modelClass}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            if (! $this->archivedPrunables->shouldFailSilently()) {
                throw $e;
            }
        }
    }

    /**
     * Perform the actual archive operation.
     *
     * @param  Model  $model
     * @param  Builder<Model>  $query
     */
    protected function performArchive($model, $query): ArchiveResult
    {
        $columns = $this->archivedPrunables->resolveColumns($model);
        $recordCount = $query->count();

        // Export to temporary file
        $tempFile = $this->exporter->export(clone $query, $columns);
        $format = $this->exporter->extension();

        // Determine the storage filename
        $filename = $model->getArchiveFilename($format)
            ?? $this->archivedPrunables->generateFilename($model, $format);

        // Compress if enabled
        $shouldCompress = $this->archivedPrunables->shouldCompress();

        if ($shouldCompress) {
            $compressedFile = $this->compressor->compress($tempFile, $format);

            if ($this->archivedPrunables->shouldCleanupTempFiles()) {
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

        $this->archivedPrunables->disk()->put($filename, $contents);

        // Cleanup temporary file
        if ($this->archivedPrunables->shouldCleanupTempFiles()) {
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
}
