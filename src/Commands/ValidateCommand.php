<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper\Commands;

use HelgeSverre\Prunekeeper\ArchivePrunedRecords;
use HelgeSverre\Prunekeeper\Contracts\Archivable;
use HelgeSverre\Prunekeeper\Prunekeeper;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Finder\Finder;

class ValidateCommand extends Command
{
    protected $signature = 'prunekeeper:validate
        {--model=* : The model(s) to validate}';

    protected $description = 'Validate column configuration for archivable models';

    public function __construct(
        protected Prunekeeper $prunekeeper
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $models = $this->getModels();

        if ($models->isEmpty()) {
            $this->components->warn('No archivable models found.');

            return self::SUCCESS;
        }

        $this->components->info('Validating archivable models...');
        $this->newLine();

        $hasErrors = false;
        $results = [];

        foreach ($models as $modelClass) {
            $result = $this->validateModel($modelClass);
            $results[] = $result;

            if ($result['status'] === 'error') {
                $hasErrors = true;
            }
        }

        $this->displayResults($results);

        if ($hasErrors) {
            $this->newLine();
            $this->components->error('Validation failed. Please fix the column configuration errors above.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->success('All models validated successfully.');

        return self::SUCCESS;
    }

    /**
     * Get the models to validate.
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
     * Validate a single model's column configuration.
     *
     * @param  class-string  $modelClass
     * @return array{model: string, table: string, status: string, columns: string, message: string}
     */
    protected function validateModel(string $modelClass): array
    {
        /** @var Model&Archivable $model */
        $model = new $modelClass;
        $table = $model->getTable();

        $columns = $this->prunekeeper->resolveColumns($model);

        if ($columns === null) {
            return [
                'model' => $modelClass,
                'table' => $table,
                'status' => 'ok',
                'columns' => 'all (default)',
                'message' => 'Using all columns',
            ];
        }

        $connection = $model->getConnectionName();
        $actualColumns = Schema::connection($connection)->getColumnListing($table);
        $invalidColumns = array_diff($columns, $actualColumns);

        if (! empty($invalidColumns)) {
            return [
                'model' => $modelClass,
                'table' => $table,
                'status' => 'error',
                'columns' => implode(', ', $columns),
                'message' => 'Invalid columns: '.implode(', ', $invalidColumns),
            ];
        }

        return [
            'model' => $modelClass,
            'table' => $table,
            'status' => 'ok',
            'columns' => implode(', ', $columns),
            'message' => 'All columns valid',
        ];
    }

    /**
     * Display validation results in a table format.
     *
     * @param  array<array{model: string, table: string, status: string, columns: string, message: string}>  $results
     */
    protected function displayResults(array $results): void
    {
        $rows = array_map(function ($result) {
            $statusIcon = $result['status'] === 'ok' ? '<fg=green>OK</>' : '<fg=red>ERROR</>';

            return [
                $result['model'],
                $result['table'],
                $statusIcon,
                $result['message'],
            ];
        }, $results);

        $this->table(
            ['Model', 'Table', 'Status', 'Message'],
            $rows
        );
    }
}
