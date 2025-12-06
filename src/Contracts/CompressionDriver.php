<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper\Contracts;

/**
 * Contract for compression drivers.
 *
 * Drivers compress files to various formats (zip, gzip, tar.gz, bzip2, etc.)
 * and can be extended via the CompressionManager.
 */
interface CompressionDriver
{
    /**
     * Compress a file.
     *
     * @param  string  $filePath  Path to the file to compress
     * @param  string|null  $innerFilename  Filename to use inside the archive (for formats that support it)
     * @return string Path to the compressed file
     *
     * @throws \HelgeSverre\Prunekeeper\Exceptions\CompressionException
     */
    public function compress(string $filePath, ?string $innerFilename = null): string;

    /**
     * Get the file extension this driver produces.
     *
     * @return string Extension without leading dot (e.g., 'zip', 'gz', 'tar.gz')
     */
    public function extension(): string;

    /**
     * Get the driver name.
     */
    public function name(): string;

    /**
     * Check if the required extensions/binaries are available.
     */
    public static function isAvailable(): bool;

    /**
     * Get list of requirements (for error messages when unavailable).
     *
     * @return array<string> List of requirements (e.g., ['ext-zip'])
     */
    public static function requirements(): array;
}
