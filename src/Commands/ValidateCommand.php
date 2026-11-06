<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper\Commands;

use HelgeSverre\Prunekeeper\ArchivableModels;
use HelgeSverre\Prunekeeper\Contracts\Archivable;
use HelgeSverre\Prunekeeper\Prunekeeper;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ValidateCommand extends Command
{
    protected bool $hasInvalidModelSelection = false;

    protected $signature = 'prunekeeper:validate
        {--model=* : The model(s) to validate}';

    protected $description = 'Validate column configuration for archivable models';

    public function handle(): int
    {
        $models = $this->getModels();

        if ($models->isEmpty()) {
            if ($this->hasInvalidModelSelection) {
                return self::FAILURE;
            }

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

        if ($hasErrors || $this->hasInvalidModelSelection) {
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
        $this->hasInvalidModelSelection = false;
        $models = $this->option('model');

        if (! empty($models)) {
            return ArchivableModels::filter(
                $models,
                function ($model, $message): void {
                    $this->hasInvalidModelSelection = true;
                    $this->components->error($message);
                },
                function ($model, $message): void {
                    $this->hasInvalidModelSelection = true;
                    $this->components->warn($message);
                }
            );
        }

        return ArchivableModels::get();
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

        if (! method_exists($model, 'prunable')) {
            return $this->errorResult($modelClass, $table, 'Missing prunable() method');
        }

        try {
            Prunekeeper::makePrunableQuery($model);

            $columns = Prunekeeper::resolveColumns($model);

            if ($columns === []) {
                return $this->errorResult(
                    $modelClass,
                    $table,
                    'At least one column must be specified when limiting an archive export'
                );
            }

            $connection = $model->getConnectionName();

            if (! Schema::connection($connection)->hasTable($table)) {
                return $this->errorResult($modelClass, $table, 'Table does not exist');
            }

            $actualColumns = Schema::connection($connection)->getColumnListing($table);
        } catch (Throwable $e) {
            if ($e instanceof \LogicException) {
                return $this->errorResult($modelClass, $table, 'Could not build prunable query: '.$e->getMessage());
            }

            return $this->errorResult($modelClass, $table, 'Could not inspect table: '.$e->getMessage());
        }

        if ($columns === null) {
            return [
                'model' => $modelClass,
                'table' => $table,
                'status' => 'ok',
                'columns' => 'all (default)',
                'message' => 'Using all columns',
            ];
        }

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
     * @return array{model: string, table: string, status: string, columns: string, message: string}
     */
    private function errorResult(string $modelClass, string $table, string $message): array
    {
        return [
            'model' => $modelClass,
            'table' => $table,
            'status' => 'error',
            'columns' => '',
            'message' => $message,
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
