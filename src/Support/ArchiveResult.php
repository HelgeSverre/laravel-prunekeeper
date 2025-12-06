<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper\Support;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Number;

/**
 * Result of an archive operation.
 *
 * @implements Arrayable<string, string|int|bool>
 */
readonly class ArchiveResult implements Arrayable
{
    public function __construct(
        public string $modelClass,
        public string $storagePath,
        public int $recordCount,
        public int $fileSize,
        public string $format,
        public bool $compressed
    ) {}

    /**
     * Convert the result to an array.
     *
     * @return array{
     *     model_class: string,
     *     storage_path: string,
     *     record_count: int,
     *     file_size: int,
     *     format: string,
     *     compressed: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'model_class' => $this->modelClass,
            'storage_path' => $this->storagePath,
            'record_count' => $this->recordCount,
            'file_size' => $this->fileSize,
            'format' => $this->format,
            'compressed' => $this->compressed,
        ];
    }

    /**
     * Get a human-readable file size.
     */
    public function humanFileSize(): string
    {
        return Number::fileSize($this->fileSize, maxPrecision: 2);
    }
}
