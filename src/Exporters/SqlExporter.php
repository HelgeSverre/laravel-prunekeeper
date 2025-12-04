<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper\Exporters;

use HelgeSverre\Prunekeeper\Contracts\Exporter;
use HelgeSverre\Prunekeeper\Facades\Prunekeeper;
use HelgeSverre\Prunekeeper\Prunekeeper as PrunekeeperManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
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

        fwrite($handle, sprintf("-- Created with Laravel Prunekeeper (version %s)\n", PrunekeeperManager::version));
        fwrite($handle, sprintf("-- Table: %s\n", $table));
        fwrite($handle, sprintf("-- Generated: %s\n", now()->toIso8601String()));
        fwrite($handle, "-- Format: SQL INSERT statements\n\n");

        $query->chunk($chunkSize, function ($records) use ($handle, $table, $columns) {
            foreach ($records as $record) {
                $data = $columns !== null
                    ? collect($record->toArray())->only($columns)->all()
                    : $record->toArray();

                $columnNames = implode('`, `', array_keys($data));
                $values = implode(', ', array_map(
                    fn ($v) => $this->escapeValue($v),
                    array_values($data)
                ));

                fwrite($handle, "INSERT INTO `{$table}` (`{$columnNames}`) VALUES ({$values});\n");
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
    protected function escapeValue(mixed $value): string
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
            $value = json_encode($value);
        }

        return DB::connection()->getPdo()->quote((string) $value);
    }
}
