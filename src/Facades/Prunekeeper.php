<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper\Facades;

use HelgeSverre\Prunekeeper\Compression\CompressionManager;
use HelgeSverre\Prunekeeper\Contracts\Exporter;
use HelgeSverre\Prunekeeper\Prunekeeper as PrunekeeperManager;
use HelgeSverre\Prunekeeper\Support\ArchiveResult;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Throwable;

/**
 * @method static void generateFilenameUsing(?callable $callback)
 * @method static void resolveColumnsUsing(?callable $callback)
 * @method static void beforeArchiving(?callable $callback)
 * @method static void afterArchiving(?callable $callback)
 * @method static void createTempFileUsing(?callable $callback)
 * @method static void resolveTableNameUsing(?callable $callback)
 * @method static string generateFilename(Model $model, string $format, ?bool $compressed = null)
 * @method static array<string>|null resolveColumns(Model $model)
 * @method static void validateColumns(Model $model, array<string> $columns)
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
 * @method static Exporter makeExporter(?string $format = null)
 * @method static int getChunkSize()
 * @method static bool shouldCompress()
 * @method static CompressionManager compression()
 * @method static string getCompressionExtension()
 * @method static bool shouldCleanupTempFiles()
 * @method static Builder<Model> makePrunableQuery(Model $model)
 * @method static ArchiveResult archive(Model $model, Builder<Model> $query, Exporter $exporter, bool $shouldCompress = true, ?callable $onCleanupError = null, ?string $compressionDriver = null)
 * @method static void flushState()
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
