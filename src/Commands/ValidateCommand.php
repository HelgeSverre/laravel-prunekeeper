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
            return ArchivableModels::filter(
                $models,
                fn ($m, $msg) => $this->components->error($msg),
                fn ($m, $msg) => $this->components->warn($msg)
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
