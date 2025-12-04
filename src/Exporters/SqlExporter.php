<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper\Exporters;

use HelgeSverre\Prunekeeper\Contracts\Exporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SqlExporter implements Exporter
{
    public function export(Builder $query, ?array $columns = null): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'prunable_export_');

        if ($tempFile === false) {
            throw new RuntimeException('Failed to create temporary file for export');
        }

        $handle = fopen($tempFile, 'w');

        if ($handle === false) {
            throw new RuntimeException('Failed to open temporary file for writing');
        }

        $table = $query->getModel()->getTable();
        $chunkSize = (int) config('prunekeeper.chunk_size', 1000);

        fwrite($handle, "-- Prunable archive export\n");
        fwrite($handle, "-- Table: {$table}\n");
        fwrite($handle, '-- Generated: '.now()->toIso8601String()."\n");
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
