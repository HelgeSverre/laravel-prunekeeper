<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper\Exporters;

use HelgeSverre\Prunekeeper\Contracts\Exporter;
use HelgeSverre\Prunekeeper\Prunekeeper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use League\Csv\Writer;

class CsvExporter implements Exporter
{
    /**
     * Export query results to a CSV file.
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<string>|null  $columns
     * @return string Path to the temporary CSV file
     */
    public function export(Builder $query, ?array $columns = null): string
    {
        if ($columns !== null) {
            Prunekeeper::validateColumns($query->getModel(), $columns);
        }

        $tempFile = Prunekeeper::createTempFile('prunekeeper_csv_');
        $mode = Prunekeeper::getFileOpenMode() ?: 'w';

        try {
            $writer = Writer::createFromPath($tempFile, $mode);

            $chunkSize = Prunekeeper::getChunkSize();
            $headerWritten = false;

            $query->chunk($chunkSize, function ($records) use ($writer, $columns, &$headerWritten) {
                foreach ($records as $record) {
                    $data = $columns !== null
                        ? collect($record->toArray())->only($columns)->all()
                        : $record->toArray();

                    if (! $headerWritten) {
                        $writer->insertOne(array_keys($data));
                        $headerWritten = true;
                    }

                    $writer->insertOne(array_values($this->flattenForCsv($data)));
                }
            });

            return $tempFile;
        } catch (\Throwable $e) {
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
            if (is_array($value) || is_object($value)) {
                $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                if ($json === false) {
                    Log::warning('Prunekeeper: JSON encoding failed for column', [
                        'column' => $key,
                        'error' => json_last_error_msg(),
                    ]);

                    $result[$key] = null;

                    continue;
                }

                $result[$key] = $json;

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
}
