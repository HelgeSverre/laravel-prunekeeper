<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper\Exceptions;

use RuntimeException;
use Throwable;

class CompressionException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $driver,
        public readonly ?string $filePath = null,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Create exception for unavailable driver.
     *
     * @param  array<string>  $requirements
     */
    public static function driverNotAvailable(string $driver, array $requirements): self
    {
        $reqs = implode(', ', $requirements);

        return new self(
            "Compression driver '{$driver}' is not available. Requirements: {$reqs}",
            $driver
        );
    }

    /**
     * Create exception for compression failure.
     */
    public static function compressionFailed(
        string $driver,
        string $filePath,
        string $reason,
        ?Throwable $previous = null
    ): self {
        return new self(
            "Failed to compress file using '{$driver}' driver: {$reason}",
            $driver,
            $filePath,
            0,
            $previous
        );
    }

    /**
     * Create exception for missing source file.
     */
    public static function fileNotFound(string $driver, string $filePath): self
    {
        return new self(
            "Source file not found for compression: {$filePath}",
            $driver,
            $filePath
        );
    }
}
