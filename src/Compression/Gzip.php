<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper\Compression;

use HelgeSverre\Prunekeeper\Contracts\CompressionDriver;
use HelgeSverre\Prunekeeper\Exceptions\CompressionException;

class Gzip implements CompressionDriver
{
    protected int $bufferSize = 65536; // 64KB

    /** @param array<string, mixed> $config */
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

        $gzPath = $filePath.'.gz';

        if (file_exists($gzPath) || is_link($gzPath)) {
            throw CompressionException::compressionFailed(
                $this->name(),
                $filePath,
                'Destination archive already exists'
            );
        }

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

        $destinationOpen = true;

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

                while ($chunk !== '') {
                    $written = gzwrite($destination, $chunk);
                    if ($written === false || $written === 0) {
                        throw CompressionException::compressionFailed(
                            $this->name(),
                            $filePath,
                            'Failed to write to gzip file'
                        );
                    }

                    $chunk = substr($chunk, $written);
                }
            }

            $destinationOpen = false;
            if (! gzclose($destination)) {
                throw CompressionException::compressionFailed(
                    $this->name(),
                    $filePath,
                    'Failed to finalize gzip file'
                );
            }

            return $gzPath;
        } catch (CompressionException $e) {
            if ($destinationOpen) {
                $destinationOpen = false;
                gzclose($destination);
            }
            @unlink($gzPath);
            throw $e;
        } catch (\Throwable $e) {
            if ($destinationOpen) {
                $destinationOpen = false;
                gzclose($destination);
            }
            @unlink($gzPath);
            throw CompressionException::compressionFailed(
                $this->name(),
                $filePath,
                $e->getMessage(),
                $e
            );
        } finally {
            fclose($source);
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
