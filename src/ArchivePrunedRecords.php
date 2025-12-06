<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper;

use Illuminate\Database\Eloquent\Model;

/**
 * Marker trait to indicate that a model's records should be archived before pruning.
 *
 * Add this trait to any model that uses the Prunable or MassPrunable trait
 * to automatically export records to storage before they are deleted.
 *
 * @mixin Model
 */
trait ArchivePrunedRecords
{
    /**
     * Determine if the model should be archived before pruning.
     *
     * Override this method to conditionally disable archiving.
     * This is checked by both the event listener and the CLI command.
     */
    public function shouldArchiveBeforePruning(): bool
    {
        return true;
    }

    /**
     * Get the columns to include in the archive.
     *
     * Return null to include all columns, or an array of database column names
     * to limit which columns are exported. Use actual database column names,
     * not accessor names or relation names.
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
     *
     * @param  string  $format  The export format (e.g., "csv" or "sql")
     * @return string|null The filename, or null to use the default
     *
     * Note: If compression is enabled, Prunekeeper appends ".zip" to the
     * generated filename. When providing a custom filename, the returned
     * value is used as-is.
     */
    public function getArchiveFilename(string $format): ?string
    {
        return null;
    }
}
