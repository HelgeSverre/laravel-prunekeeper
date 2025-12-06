<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper\Support;

use RuntimeException;
use ZipArchive;

// TODO: support other compression formats (like tar.gz, 7z, etc) if needed in the future
class FileCompressor
{
    /**
     * Compress a file using ZIP compression.
     *
     * @param  string  $filePath  Path to the file to compress
     * @param  string  $extension  The file extension to use inside the zip
     * @return string Path to the compressed file
     *
     * @throws RuntimeException If compression fails
     */
    public static function compress(string $filePath, string $extension): string
    {
        $zipPath = $filePath.'.zip';
        $zip = new ZipArchive;

        $result = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($result !== true) {
            throw new RuntimeException("Failed to create zip archive: {$zipPath} (error code: {$result})");
        }

        // Generate a meaningful filename inside the zip
        $innerFilename = self::generateInnerFilename($filePath, $extension);

        if (! $zip->addFile($filePath, $innerFilename)) {
            $zip->close();
            throw new RuntimeException("Failed to add file to zip archive: {$filePath}");
        }

        $zip->close();

        return $zipPath;
    }

    /**
     * Generate a meaningful filename for the file inside the zip.
     */
    protected static function generateInnerFilename(string $filePath, string $extension): string
    {
        // Extract date and table name from the temp filename if possible
        // Default to a generic name with the proper extension
        return 'export.'.$extension;
    }
}
