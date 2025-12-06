<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper\Compression;

use HelgeSverre\Prunekeeper\Contracts\CompressionDriver;
use HelgeSverre\Prunekeeper\Exceptions\CompressionException;
use ZipArchive;

class Zip implements CompressionDriver
{
    public function compress(string $filePath, ?string $innerFilename = null): string
    {
        if (! file_exists($filePath)) {
            throw CompressionException::fileNotFound($this->name(), $filePath);
        }

        $zipPath = $filePath.'.zip';
        $zip = new ZipArchive;

        $result = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($result !== true) {
            throw CompressionException::compressionFailed(
                $this->name(),
                $filePath,
                "Failed to create zip archive (error code: {$result})"
            );
        }

        $entryName = $innerFilename ?? basename($filePath);

        if (! $zip->addFile($filePath, $entryName)) {
            $zip->close();
            @unlink($zipPath);
            throw CompressionException::compressionFailed(
                $this->name(),
                $filePath,
                'Failed to add file to zip archive'
            );
        }

        if (! $zip->close()) {
            @unlink($zipPath);
            throw CompressionException::compressionFailed(
                $this->name(),
                $filePath,
                'Failed to finalize zip archive'
            );
        }

        return $zipPath;
    }

    public function extension(): string
    {
        return 'zip';
    }

    public function name(): string
    {
        return 'zip';
    }

    public static function isAvailable(): bool
    {
        return extension_loaded('zip') && class_exists(ZipArchive::class);
    }

    public static function requirements(): array
    {
        return ['ext-zip'];
    }
}
