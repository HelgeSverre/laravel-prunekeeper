<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper\Facades;

use HelgeSverre\Prunekeeper\Prunekeeper as PrunekeeperManager;
use HelgeSverre\Prunekeeper\Support\ArchiveResult;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Throwable;

/**
 * @method static PrunekeeperManager generateFilenameUsing(?callable $callback)
 * @method static PrunekeeperManager resolveColumnsUsing(?callable $callback)
 * @method static PrunekeeperManager beforeArchiving(?callable $callback)
 * @method static PrunekeeperManager afterArchiving(?callable $callback)
 * @method static PrunekeeperManager createTempFileUsing(?callable $callback)
 * @method static PrunekeeperManager resolveTableNameUsing(?callable $callback)
 * @method static string generateFilename(Model $model, string $format, ?bool $compressed = null)
 * @method static array|null resolveColumns(Model $model)
 * @method static void validateColumns(Model $model, array $columns)
 * @method static void fireBeforeArchive(Model $model, int $recordCount = 0)
 * @method static void fireAfterArchive(Model $model, ArchiveResult $result)
 * @method static void fireArchiveFailed(Model $model, Throwable $exception)
 * @method static void fireArchiveSkipped(Model $model, string $reason)
 * @method static string createTempFile(string $prefix = 'prunekeeper_export_')
 * @method static string resolveTableName(Model $model)
 * @method static string getFileOpenMode()
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
