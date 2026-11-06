<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper\Listeners;

use HelgeSverre\Prunekeeper\ArchivePrunedRecords;
use HelgeSverre\Prunekeeper\Contracts\Archivable;
use HelgeSverre\Prunekeeper\Events\ArchiveSkipped;
use HelgeSverre\Prunekeeper\Prunekeeper;
use HelgeSverre\Prunekeeper\Support\ArchiveResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Events\ModelPruningStarting;
use Illuminate\Support\Facades\Log;
use ReflectionClass;
use Throwable;

class ArchiveBeforePruning
{
    /**
     * Handle the ModelPruningStarting event.
     */
    public function handle(ModelPruningStarting $event): void
    {
        foreach (array_unique($event->models) as $modelClass) {
            $this->archiveModel($modelClass);
        }
    }

    /**
     * Archive a single model class if it uses the ArchivePrunedRecords trait.
     */
    protected function archiveModel(string $modelClass): void
    {
        if (! is_subclass_of($modelClass, Model::class) || ! (new ReflectionClass($modelClass))->isInstantiable()) {
            return;
        }

        if (! in_array(ArchivePrunedRecords::class, class_uses_recursive($modelClass))) {
            return;
        }

        /** @var Model&Archivable $model */
        $model = new $modelClass;

        if (! Prunekeeper::isEnabled()) {
            Log::debug("[Prunekeeper] Skipping {$modelClass} - archiving disabled");
            Prunekeeper::fireArchiveSkipped($model, ArchiveSkipped::REASON_DISABLED);

            return;
        }

        if (! method_exists($model, 'prunable')) {
            Log::warning("[Prunekeeper] {$modelClass} uses ArchivePrunedRecords but has no prunable() method.");
            Prunekeeper::fireArchiveSkipped($model, ArchiveSkipped::REASON_NO_PRUNABLE_METHOD);

            return;
        }

        $traits = class_uses_recursive($modelClass);

        if (! isset($traits[Prunable::class]) && ! isset($traits[MassPrunable::class])) {
            Log::warning("[Prunekeeper] {$modelClass} uses ArchivePrunedRecords but is not prunable by Laravel.");

            return;
        }

        if (! $model->shouldArchiveBeforePruning()) {
            Log::debug("[Prunekeeper] Skipping {$modelClass} - archiving disabled");
            Prunekeeper::fireArchiveSkipped($model, ArchiveSkipped::REASON_DISABLED);

            return;
        }

        try {
            $query = Prunekeeper::makePrunableQuery($model);
            $count = $query->count();

            if ($count === 0) {
                Log::debug("[Prunekeeper] No prunable records for {$modelClass}");
                Prunekeeper::fireArchiveSkipped($model, ArchiveSkipped::REASON_NO_RECORDS);

                return;
            }

            Log::info("[Prunekeeper] Archiving {$count} records from {$modelClass}");

            Prunekeeper::fireBeforeArchive($model, $count);
            $result = $this->performArchive($model, clone $query);
            Prunekeeper::fireAfterArchive($model, $result);

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

            Prunekeeper::fireArchiveFailed($model, $e);

            if (! Prunekeeper::shouldFailSilently()) {
                throw $e;
            }
        }
    }

    /**
     * Perform the actual archive operation.
     *
     * @param  Model&Archivable  $model
     * @param  Builder<Model>  $query
     */
    protected function performArchive(Model $model, Builder $query): ArchiveResult
    {
        return Prunekeeper::archive(
            $model,
            $query,
            Prunekeeper::makeExporter(),
            Prunekeeper::shouldCompress(),
            fn (string $file) => Log::warning("[Prunekeeper] Failed to delete temporary file: {$file}")
        );
    }
}
