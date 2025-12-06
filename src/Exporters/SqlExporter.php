<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper\Exporters;

use HelgeSverre\Prunekeeper\Contracts\Exporter;
use HelgeSverre\Prunekeeper\Facades\Prunekeeper;
use HelgeSverre\Prunekeeper\Prunekeeper as PrunekeeperManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SqlExporter implements Exporter
{
    public function export(Builder $query, ?array $columns = null): string
    {
        if ($columns !== null) {
            Prunekeeper::validateColumns($query->getModel(), $columns);
        }

        $tempFile = Prunekeeper::createTempFile('prunekeeper_sql_');

        $handle = fopen($tempFile, Prunekeeper::getFileOpenMode());

        if ($handle === false) {
            throw new RuntimeException('Failed to open temporary file for writing');
        }

        $model = $query->getModel();
        $table = Prunekeeper::resolveTableName($model);
        $chunkSize = Prunekeeper::getChunkSize();

        fwrite($handle, sprintf("-- Created with Laravel Prunekeeper (version %s)\n", PrunekeeperManager::VERSION));
        fwrite($handle, sprintf("-- Table: %s\n", $table));
        fwrite($handle, sprintf("-- Generated: %s\n", now()->toIso8601String()));
        fwrite($handle, "-- Format: SQL INSERT statements\n\n");

        $connectionName = $model->getConnectionName();
        $grammar = $model->getConnection()->getQueryGrammar();

        $escapedTable = $grammar->wrapTable($table);

        $query->chunk($chunkSize, function ($records) use ($handle, $escapedTable, $columns, $connectionName, $grammar) {
            foreach ($records as $record) {
                $data = $columns !== null
                    ? collect($record->toArray())->only($columns)->all()
                    : $record->toArray();

                $escapedColumnNames = $grammar->columnize(array_keys($data));

                $columnNames = array_keys($data);
                $columnValues = array_values($data);
                $values = implode(', ', array_map(
                    fn ($v, $i) => $this->escapeValue($v, $connectionName, $columnNames[$i]),
                    $columnValues,
                    array_keys($columnValues)
                ));

                fwrite($handle, "INSERT INTO {$escapedTable} ({$escapedColumnNames}) VALUES ({$values});\n");
            }
        });

        fclose($handle);

        return $tempFile;
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
            return $value ? '1' : '0';
        }

        if (is_numeric($value) && ! is_string($value)) {
            return (string) $value;
        }

        if (is_array($value) || is_object($value)) {
            $json = json_encode($value);

            if ($json === false) {
                Log::warning('Prunekeeper: JSON encoding failed for column', [
                    'column' => $column,
                    'error' => json_last_error_msg(),
                ]);

                return 'NULL';
            }

            $value = $json;
        }

        return DB::connection($connection)->getPdo()->quote((string) $value);
    }
}
