<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper\Exporters;

use HelgeSverre\Prunekeeper\Contracts\Exporter;
use HelgeSverre\Prunekeeper\Prunekeeper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use League\Csv\Writer;
use RuntimeException;

class CsvExporter implements Exporter
{
    /**
     * Export query results to a CSV file.
     *
     * @param  Builder<Model>  $query
     * @param  array<string>|null  $columns
     * @return string Path to the temporary CSV file
     */
    public function export(Builder $query, ?array $columns = null): string
    {
        if ($columns === []) {
            throw new InvalidArgumentException('At least one column must be specified when limiting an archive export.');
        }

        $archiveColumns = $this->resolveArchiveColumns($query, $columns);

        $tempFile = Prunekeeper::createTempFile('prunekeeper_csv_');
        $mode = Prunekeeper::getFileOpenMode() ?: 'w';
        $writer = null;

        try {
            $writer = Writer::createFromPath($tempFile, $mode);

            $chunkSize = Prunekeeper::getChunkSize();
            $headerWritten = false;

            $query->chunk($chunkSize, function ($records) use ($writer, $archiveColumns, &$headerWritten) {
                foreach ($records as $record) {
                    $data = $this->archiveData($record, $archiveColumns);

                    if (! $headerWritten) {
                        $writer->insertOne(array_keys($data));
                        $headerWritten = true;
                    }

                    $writer->insertOne(array_values($this->flattenForCsv($data)));
                }
            });

            return $tempFile;
        } catch (\Throwable $e) {
            unset($writer);
            @unlink($tempFile);
            throw $e;
        }
    }

    public function extension(): string
    {
        return 'csv';
    }

    /**
     * Flatten nested arrays and objects for CSV output.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, scalar|null>
     */
    protected function flattenForCsv(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_resource($value)) {
                throw new RuntimeException("CSV export does not support resource values in column {$key}");
            }

            if (is_float($value)) {
                if (! is_finite($value)) {
                    throw new RuntimeException("CSV export does not support non-finite float values in column {$key}");
                }

                $result[$key] = sprintf('%.17H', $value);

                continue;
            }

            if (is_array($value) || is_object($value)) {
                $result[$key] = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                continue;
            }

            if (is_bool($value)) {
                $result[$key] = $value ? '1' : '0';

                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * @param  Builder<Model>  $query
     * @param  array<string>|null  $columns
     * @return array<string>
     */
    protected function resolveArchiveColumns(Builder $query, ?array $columns): array
    {
        $model = $query->getModel();

        if ($columns !== null) {
            Prunekeeper::validateColumns($model, $columns);

            return $columns;
        }

        return Schema::connection($model->getConnectionName())->getColumnListing($model->getTable());
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
