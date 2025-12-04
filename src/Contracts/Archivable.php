<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper\Contracts;

/**
 * Interface for models that should be archived before pruning.
 */
interface Archivable
{
    /**
     * Determine if the model should be archived before pruning.
     */
    public function shouldArchiveBeforePruning(): bool;

    /**
     * Get the columns to include in the archive.
     *
     * @return array<string>|null
     */
    public function getArchivableColumns(): ?array;

    /**
     * Get a custom filename for this model's archive.
     */
    public function getArchiveFilename(string $format): ?string;
}
