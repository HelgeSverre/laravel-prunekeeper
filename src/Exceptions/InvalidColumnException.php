<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper\Exceptions;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class InvalidColumnException extends InvalidArgumentException
{
    /**
     * @param  array<string>  $invalidColumns
     * @param  array<string>  $availableColumns
     */
    public function __construct(
        public readonly Model $model,
        public readonly array $invalidColumns,
        public readonly array $availableColumns
    ) {
        $modelClass = $model::class;
        $table = $model->getTable();
        $invalid = implode(', ', $invalidColumns);
        $available = implode(', ', $availableColumns);

        parent::__construct(
            "Invalid column(s) specified for archiving {$modelClass} (table: {$table}): [{$invalid}]. ".
            "Available columns: [{$available}]"
        );
    }
}
