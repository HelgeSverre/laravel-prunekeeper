<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper\Exporters;

use HelgeSverre\Prunekeeper\Contracts\Exporter;
use HelgeSverre\Prunekeeper\Facades\Prunekeeper;
use Illuminate\Database\Eloquent\Builder;
use League\Csv\Writer;

class CsvExporter implements Exporter
{
    public function export(Builder $query, ?array $columns = null): string
    {
        $tempFile = Prunekeeper::createTempFile('prunekeeper_csv_');

        $writer = Writer::createFromPath($tempFile, 'w+');

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
    }

    public function extension(): string
    {
        return 'csv';
    }

    /**
     * Flatten nested arrays and objects for CSV output.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function flattenForCsv(array $data): array
    {
        return array_map(function ($value) {
            if (is_array($value) || is_object($value)) {
                return json_encode($value);
            }

            if (is_bool($value)) {
                return $value ? '1' : '0';
            }

            return $value;
        }, $data);
    }
}
