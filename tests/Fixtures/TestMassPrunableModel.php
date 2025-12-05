<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper\Tests\Fixtures;

use HelgeSverre\Prunekeeper\ArchivePrunedRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

class TestMassPrunableModel extends Model
{
    use ArchivePrunedRecords;
    use MassPrunable;

    protected $table = 'test_prunable_models';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /**
     * Get the prunable model query.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<=', now()->subMonth());
    }
}
