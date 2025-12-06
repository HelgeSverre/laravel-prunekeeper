<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper\Compression;

use HelgeSverre\Prunekeeper\Contracts\CompressionDriver;
use HelgeSverre\Prunekeeper\Exceptions\CompressionException;

class Bzip2 implements CompressionDriver
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
        // Note: $innerFilename is ignored for bzip2 (single-file compression, no filename preservation)

        if (! file_exists($filePath)) {
            throw CompressionException::fileNotFound($this->name(), $filePath);
        }

        $bz2Path = $filePath.'.bz2';

        $source = fopen($filePath, 'rb');
        if ($source === false) {
            throw CompressionException::compressionFailed(
                $this->name(),
                $filePath,
                'Failed to open source file for reading'
            );
        }

        $destination = bzopen($bz2Path, 'w');

        if ($destination === false) {
            fclose($source);
            throw CompressionException::compressionFailed(
                $this->name(),
                $filePath,
                'Failed to create bzip2 file'
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

                $written = bzwrite($destination, $chunk);
                if ($written === false) {
                    throw CompressionException::compressionFailed(
                        $this->name(),
                        $filePath,
                        'Failed to write to bzip2 file'
                    );
                }
            }

            return $bz2Path;
        } catch (CompressionException $e) {
            @unlink($bz2Path);
            throw $e;
        } catch (\Throwable $e) {
            @unlink($bz2Path);
            throw CompressionException::compressionFailed(
                $this->name(),
                $filePath,
                $e->getMessage(),
                $e
            );
        } finally {
            fclose($source);
            bzclose($destination);
        }
    }

    public function extension(): string
    {
        return 'bz2';
    }

    public function name(): string
    {
        return 'bzip2';
    }

    public static function isAvailable(): bool
    {
        return extension_loaded('bz2') && function_exists('bzopen');
    }

    public static function requirements(): array
    {
        return ['ext-bz2'];
    }
}
