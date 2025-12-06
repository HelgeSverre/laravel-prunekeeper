<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper\Facades;

use Closure;
use HelgeSverre\Prunekeeper\Compression\CompressionManager;
use HelgeSverre\Prunekeeper\Contracts\CompressionDriver;
use Illuminate\Support\Facades\Facade;

/**
 * @method static CompressionDriver driver(?string $driver = null)
 * @method static string getDefaultDriver()
 * @method static CompressionManager extend(string $driver, Closure $callback)
 * @method static array getAvailableDrivers()
 *
 * @see CompressionManager
 */
class Compression extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CompressionManager::class;
    }
}
