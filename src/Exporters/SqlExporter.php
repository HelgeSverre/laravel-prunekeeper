<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper\Exporters;

use HelgeSverre\Prunekeeper\Contracts\Exporter;
use HelgeSverre\Prunekeeper\Prunekeeper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

class SqlExporter implements Exporter
{
    public function export(Builder $query, ?array $columns = null): string
    {
        if ($columns === []) {
            throw new InvalidArgumentException('At least one column must be specified when limiting an archive export.');
        }

        $archiveColumns = $this->resolveArchiveColumns($query, $columns);

        $tempFile = Prunekeeper::createTempFile('prunekeeper_sql_');
        $handle = null;

        try {
            $handle = fopen($tempFile, Prunekeeper::getFileOpenMode());

            if ($handle === false) {
                throw new RuntimeException('Failed to open temporary file for writing');
            }

            $model = $query->getModel();
            $table = Prunekeeper::resolveTableName($model);
            $chunkSize = Prunekeeper::getChunkSize();

            $this->write($handle, sprintf("-- Created with Laravel Prunekeeper (version %s)\n", Prunekeeper::VERSION));
            $this->write($handle, sprintf("-- Table: %s\n", $table));
            $this->write($handle, sprintf("-- Generated: %s\n", now()->toIso8601String()));
            $this->write($handle, "-- Format: SQL INSERT statements\n\n");

            $connectionName = $model->getConnectionName();
            $grammar = $model->getConnection()->getQueryGrammar();

            $escapedTable = $grammar->wrapTable($table);
            $identityOverride = $model->getConnection()->getDriverName() === 'pgsql'
                ? ' OVERRIDING SYSTEM VALUE'
                : '';

            $query->chunk($chunkSize, function ($records) use ($handle, $escapedTable, $identityOverride, $archiveColumns, $connectionName, $grammar) {
                foreach ($records as $record) {
                    $data = $this->archiveData($record, $archiveColumns);

                    $escapedColumnNames = $grammar->columnize(array_keys($data));

                    $columnNames = array_keys($data);
                    $columnValues = array_values($data);
                    $values = implode(', ', array_map(
                        fn ($v, $i) => $this->escapeValue($v, $connectionName, $columnNames[$i]),
                        $columnValues,
                        array_keys($columnValues)
                    ));

                    $this->write($handle, "INSERT INTO {$escapedTable} ({$escapedColumnNames}){$identityOverride} VALUES ({$values});\n");
                }
            });

            if (! fflush($handle)) {
                throw new RuntimeException('Failed to flush SQL export file');
            }

            return $tempFile;
        } catch (\Throwable $e) {
            if (is_resource($handle)) {
                fclose($handle);
                $handle = null;
            }

            @unlink($tempFile);
            throw $e;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    public function extension(): string
    {
        return 'sql';
    }

    /**
     * Escape a value for SQL insertion.
     */
    protected function escapeValue(mixed $value, ?string $connection = null, ?string $column = null): string
    {
        if (is_null($value)) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return DB::connection($connection)->getDriverName() === 'pgsql'
                ? ($value ? 'TRUE' : 'FALSE')
                : ($value ? '1' : '0');
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new RuntimeException("SQL export does not support non-finite float values in column {$column}");
            }

            return sprintf('%.17H', $value);
        }

        if (is_numeric($value) && ! is_string($value)) {
            return (string) $value;
        }

        if (is_resource($value)) {
            throw new RuntimeException("SQL export does not support resource values in column {$column}");
        }

        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_THROW_ON_ERROR);
        }

        if (is_string($value) && str_contains($value, "\0")) {
            throw new RuntimeException("SQL export does not support values containing NUL bytes in column {$column}");
        }

        return DB::connection($connection)->getPdo()->quote((string) $value);
    }

    /**
     * @param  resource  $handle
     */
    protected function write($handle, string $contents): void
    {
        while ($contents !== '') {
            $written = fwrite($handle, $contents);

            if ($written === false || $written === 0) {
                throw new RuntimeException('Failed to write SQL export file');
            }

            $contents = substr($contents, $written);
        }
    }

    /**
     * @param  Builder<Model>  $query
     * @param  array<string>|null  $columns
     * @return array<string>
     */
    protected function resolveArchiveColumns(Builder $query, ?array $columns): array
    {
        $model = $query->getModel();
        $schemaColumns = Schema::connection($model->getConnectionName())->getColumns($model->getTable());
        $generatedColumns = array_values(array_map(
            fn (array $column): string => $column['name'],
            array_filter($schemaColumns, fn (array $column): bool => $column['generation'] !== null)
        ));

        if ($columns !== null) {
            Prunekeeper::validateColumns($model, $columns);

            $selectedGeneratedColumns = array_intersect($columns, $generatedColumns);

            if ($selectedGeneratedColumns !== []) {
                throw new InvalidArgumentException(
                    'Generated columns cannot be included in SQL archives: '.implode(', ', $selectedGeneratedColumns)
                );
            }

            return $columns;
        }

        return array_values(array_map(
            fn (array $column): string => $column['name'],
            array_filter($schemaColumns, fn (array $column): bool => $column['generation'] === null)
        ));
    }

    /**
     * @param  array<string>  $columns
     * @return array<string, mixed>
     */
    protected function archiveData(Model $record, array $columns): array
    {
        $attributes = $record->getRawOriginal();
        $missing = array_diff($columns, array_keys($attributes));

        if ($missing !== []) {
            throw new RuntimeException('Archive query did not load required column(s): '.implode(', ', $missing));
        }

        return Arr::only($attributes, $columns);
    }
}
