<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper\Facades;

use HelgeSverre\Prunekeeper\Prunekeeper as PrunekeeperManager;
use HelgeSverre\Prunekeeper\Support\ArchiveResult;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;

/**
 * @method static PrunekeeperManager generateFilenameUsing(callable $callback)
 * @method static PrunekeeperManager resolveColumnsUsing(callable $callback)
 * @method static PrunekeeperManager beforeArchiving(callable $callback)
 * @method static PrunekeeperManager afterArchiving(callable $callback)
 * @method static string generateFilename(Model $model, string $format)
 * @method static array|null resolveColumns(Model $model)
 * @method static void fireBeforeArchive(Model $model)
 * @method static void fireAfterArchive(Model $model, ArchiveResult $result)
 * @method static Filesystem disk()
 * @method static bool isEnabled()
 * @method static bool shouldFailSilently()
 * @method static string getFormat()
 * @method static int getChunkSize()
 * @method static bool shouldCompress()
 * @method static bool shouldCleanupTempFiles()
 *
 * @see PrunekeeperManager
 */
class Prunekeeper extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PrunekeeperManager::class;
    }
}
