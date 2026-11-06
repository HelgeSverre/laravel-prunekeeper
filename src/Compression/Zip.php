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
        if (! is_file($filePath)) {
            throw CompressionException::compressionFailed(
                $this->name(),
                $filePath,
                'Source path does not exist or is not a regular file'
            );
        }

        if (! static::isAvailable()) {
            throw CompressionException::driverNotAvailable($this->name(), static::requirements());
        }

        $zipPath = $filePath.'.zip';

        if (file_exists($zipPath) || is_link($zipPath)) {
            throw CompressionException::compressionFailed(
                $this->name(),
                $filePath,
                'Destination archive already exists'
            );
        }

        $zip = new ZipArchive;
        $archiveOpen = false;

        try {
            $result = $zip->open($zipPath, ZipArchive::CREATE);

            if ($result !== true) {
                throw CompressionException::compressionFailed(
                    $this->name(),
                    $filePath,
                    "Failed to create zip archive (error code: {$result})"
                );
            }

            $archiveOpen = true;
            $entryName = $innerFilename ?? basename($filePath);

            if (! $zip->addFile($filePath, $entryName)) {
                throw CompressionException::compressionFailed(
                    $this->name(),
                    $filePath,
                    'Failed to add file to zip archive'
                );
            }

            $archiveOpen = false;
            if (! $zip->close()) {
                throw CompressionException::compressionFailed(
                    $this->name(),
                    $filePath,
                    'Failed to finalize zip archive'
                );
            }

            return $zipPath;
        } catch (CompressionException $e) {
            if ($archiveOpen) {
                $zip->close();
            }
            @unlink($zipPath);
            throw $e;
        } catch (\Throwable $e) {
            if ($archiveOpen) {
                $zip->close();
            }
            @unlink($zipPath);
            throw CompressionException::compressionFailed(
                $this->name(),
                $filePath,
                $e->getMessage(),
                $e
            );
        }
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
