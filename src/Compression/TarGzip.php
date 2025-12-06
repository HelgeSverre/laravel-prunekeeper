<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper\Compression;

use HelgeSverre\Prunekeeper\Contracts\CompressionDriver;
use HelgeSverre\Prunekeeper\Exceptions\CompressionException;
use Phar;
use PharData;
use Throwable;

class TarGzip implements CompressionDriver
{
    public function compress(string $filePath, ?string $innerFilename = null): string
    {
        if (! file_exists($filePath)) {
            throw CompressionException::fileNotFound($this->name(), $filePath);
        }

        $tarPath = $filePath.'.tar';
        $tarGzPath = $filePath.'.tar.gz';

        try {
            // Create the tar archive
            $tar = new PharData($tarPath);

            $entryName = $innerFilename ?? basename($filePath);
            $tar->addFile($filePath, $entryName);

            // Compress the tar with gzip
            $tar->compress(Phar::GZ);

            // PharData::compress() creates the .tar.gz file
            // We need to clean up the intermediate .tar file
            if (file_exists($tarPath)) {
                @unlink($tarPath);
            }

            if (! file_exists($tarGzPath)) {
                throw CompressionException::compressionFailed(
                    $this->name(),
                    $filePath,
                    'Compressed tar.gz file was not created'
                );
            }

            return $tarGzPath;
        } catch (CompressionException $e) {
            throw $e;
        } catch (Throwable $e) {
            // Clean up partial files on error
            @unlink($tarPath);
            @unlink($tarGzPath);

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
        return 'tar.gz';
    }

    public function name(): string
    {
        return 'targz';
    }

    public static function isAvailable(): bool
    {
        if (! extension_loaded('phar') || ! extension_loaded('zlib') || ! class_exists(PharData::class)) {
            return false;
        }

        $readonly = ini_get('phar.readonly');

        return $readonly === '' || $readonly === '0' || $readonly === false;
    }

    public static function requirements(): array
    {
        return ['ext-phar', 'ext-zlib', 'phar.readonly=0'];
    }
}
