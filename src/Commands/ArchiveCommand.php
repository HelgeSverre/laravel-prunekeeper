<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper\Commands;

use HelgeSverre\Prunekeeper\ArchivableModels;
use HelgeSverre\Prunekeeper\Contracts\Archivable;
use HelgeSverre\Prunekeeper\Events\ArchiveSkipped;
use HelgeSverre\Prunekeeper\Prunekeeper;
use HelgeSverre\Prunekeeper\Support\ArchiveResult;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Throwable;

class ArchiveCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'prunekeeper:archive
        {--model=* : The model(s) to archive}
        {--format= : Export format (csv or sql)}
        {--pretend : Display the number of records that would be archived}
        {--no-compress : Disable compression}
        {--compression= : Override compression driver (zip, gzip, targz, bzip2)}';

    /**
     * The console command description.
     */
    protected $description = 'Archive prunable model records without deleting them';

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
            return ArchivableModels::filter(
                $models,
                fn ($m, $msg) => $this->components->error($msg),
                fn ($m, $msg) => $this->components->warn($msg)
            );
        }

        return ArchivableModels::get();
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
            Prunekeeper::fireArchiveSkipped($model, ArchiveSkipped::REASON_NO_PRUNABLE_METHOD);

            return null;
        }

        if (! $model->shouldArchiveBeforePruning()) {
            $this->components->warn("{$modelClass} has archiving disabled.");
            Prunekeeper::fireArchiveSkipped($model, ArchiveSkipped::REASON_DISABLED);

            return null;
        }

        $query = Prunekeeper::makePrunableQuery($model);
        $count = $query->count();

        if ($count === 0) {
            $this->components->info("{$modelClass}: No prunable records found.");
            Prunekeeper::fireArchiveSkipped($model, ArchiveSkipped::REASON_NO_RECORDS);

            return null;
        }

        if ($this->option('pretend')) {
            $this->components->info("{$modelClass}: {$count} records would be archived.");
            Prunekeeper::fireArchiveSkipped($model, ArchiveSkipped::REASON_PRETEND_MODE);

            return null;
        }

        $result = null;

        Prunekeeper::fireBeforeArchive($model, $count);

        $this->components->task("Archiving {$count} records from {$modelClass}", function () use ($model, $query, &$result) {
            try {
                $result = $this->performArchive($model, clone $query);
                Prunekeeper::fireAfterArchive($model, $result);
            } catch (Throwable $e) {
                Prunekeeper::fireArchiveFailed($model, $e);
                throw $e;
            }
        });

        if ($result !== null) {
            $items = [
                "Path: {$result->storagePath}",
                "Records: {$result->recordCount}",
                "Size: {$result->humanFileSize()}",
            ];

            if ($result->compressed) {
                $driver = $this->getCompressionDriver();
                $items[] = "Compression: {$driver}";
            }

            $this->components->bulletList($items);
        }

        return $result;
    }

    /**
     * Perform the archive operation.
     *
     * @param  Model&Archivable  $model
     * @param  Builder<Model>  $query
     */
    protected function performArchive(Model $model, Builder $query): ArchiveResult
    {
        $format = $this->option('format');
        $exporter = Prunekeeper::makeExporter(is_string($format) ? $format : null);
        $shouldCompress = ! $this->option('no-compress') && Prunekeeper::shouldCompress();

        return Prunekeeper::archive($model, $query, $exporter, $shouldCompress);
    }

    /**
     * Get the compression driver to use.
     */
    protected function getCompressionDriver(): string
    {
        $driver = $this->option('compression');

        if (is_string($driver) && $driver !== '') {
            return $driver;
        }

        return Prunekeeper::compression()->getDefaultDriver();
    }
}
