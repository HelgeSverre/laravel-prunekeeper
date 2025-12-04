<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

interface Exporter
{
    /**
     * Export records from the given query to a temporary file.
     *
     * @param  Builder<Model>  $query  The query to export records from
     * @param  array<string>|null  $columns  Specific columns to export, or null for all
     * @return string Path to the exported temporary file
     */
    public function export(Builder $query, ?array $columns = null): string;

    /**
     * Get the file extension for this export format.
     */
    public function extension(): string;
}
