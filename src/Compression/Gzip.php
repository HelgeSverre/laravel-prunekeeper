<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper\Compression;

use HelgeSverre\Prunekeeper\Contracts\CompressionDriver;
use HelgeSverre\Prunekeeper\Exceptions\CompressionException;

class Gzip implements CompressionDriver
{
    protected int $bufferSize = 65536; // 64KB

    public function __construct(array $config = [])
    {
        if (isset($config['buffer_size'])) {
            $size = (int) $config['buffer_size'];
            $this->bufferSize = $size > 0 ? $size : 65536;
        }
    }

    public function compress(string $filePath, ?string $innerFilename = null): string
    {
        // Note: $innerFilename is ignored for gzip (single-file compression, no filename preservation)

        if (! file_exists($filePath)) {
            throw CompressionException::fileNotFound($this->name(), $filePath);
        }

        $gzPath = $filePath.'.gz';

        $source = fopen($filePath, 'rb');
        if ($source === false) {
            throw CompressionException::compressionFailed(
                $this->name(),
                $filePath,
                'Failed to open source file for reading'
            );
        }

        $destination = gzopen($gzPath, 'wb6'); // Level 6 is default

        if ($destination === false) {
            fclose($source);
            throw CompressionException::compressionFailed(
                $this->name(),
                $filePath,
                'Failed to create gzip file'
            );
        }

        try {
            while (! feof($source)) {
                $chunk = fread($source, $this->bufferSize);
                if ($chunk === false) {
                    throw CompressionException::compressionFailed(
                        $this->name(),
                        $filePath,
                        'Failed to read source file'
                    );
                }

                $written = gzwrite($destination, $chunk);
                if ($written === false) {
                    throw CompressionException::compressionFailed(
                        $this->name(),
                        $filePath,
                        'Failed to write to gzip file'
                    );
                }
            }

            return $gzPath;
        } catch (CompressionException $e) {
            @unlink($gzPath);
            throw $e;
        } catch (\Throwable $e) {
            @unlink($gzPath);
            throw CompressionException::compressionFailed(
                $this->name(),
                $filePath,
                $e->getMessage(),
                $e
            );
        } finally {
            fclose($source);
            gzclose($destination);
        }
    }

    public function extension(): string
    {
        return 'gz';
    }

    public function name(): string
    {
        return 'gzip';
    }

    public static function isAvailable(): bool
    {
        return extension_loaded('zlib') && function_exists('gzopen');
    }

    public static function requirements(): array
    {
        return ['ext-zlib'];
    }
}
