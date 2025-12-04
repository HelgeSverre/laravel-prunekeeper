<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper;

use Illuminate\Database\Eloquent\Model;

/**
 * Marker trait to indicate that a model's records should be archived before pruning.
 *
 * Add this trait to any model that uses the Prunable trait to automatically
 * export records to cloud storage before they are deleted.
 *
 * @mixin Model
 */
trait ArchivePrunedRecords
{
    /**
     * Determine if the model should be archived before pruning.
     *
     * Override this method to conditionally disable archiving.
     */
    public function shouldArchiveBeforePruning(): bool
    {
        return true;
    }

    /**
     * Get the columns to include in the archive.
     *
     * Return null to include all columns, or an array of column names
     * to limit which columns are exported.
     *
     * @return array<string>|null
     */
    public function getArchivableColumns(): ?array
    {
        return null;
    }

    /**
     * Get a custom filename for this model's archive.
     *
     * Return null to use the default filename generator.
     */
    public function getArchiveFilename(string $format): ?string
    {
        return null;
    }
}
