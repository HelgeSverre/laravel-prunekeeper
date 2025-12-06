<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

class ArchivableModels
{
    /**
     * Get all models using the ArchivePrunedRecords trait.
     *
     * @return Collection<int, class-string>
     */
    public static function get(): Collection
    {
        $modelsPath = app_path('Models');

        if (! File::isDirectory($modelsPath)) {
            return collect();
        }

        return collect((new Finder)->files()->name('*.php')->in($modelsPath))
            ->map(fn ($file) => 'App\\Models\\'.str_replace(
                ['/', '.php'],
                ['\\', ''],
                $file->getRelativePathname()
            ))
            ->filter(fn ($class) => class_exists($class))
            ->filter(fn ($class) => in_array(ArchivePrunedRecords::class, class_uses_recursive($class)))
            ->values();
    }

    /**
     * Validate and filter model class names.
     *
     * @param  array<string>  $models
     * @param  callable(string, string): void|null  $onError
     * @param  callable(string, string): void|null  $onWarning
     * @return Collection<int, class-string>
     */
    public static function filter(
        array $models,
        ?callable $onError = null,
        ?callable $onWarning = null
    ): Collection {
        return collect($models)->filter(function ($model) use ($onError, $onWarning) {
            if (! class_exists($model)) {
                if ($onError) {
                    $onError($model, "Model class not found: {$model}");
                }

                return false;
            }

            if (! in_array(ArchivePrunedRecords::class, class_uses_recursive($model))) {
                if ($onWarning) {
                    $onWarning($model, "Model does not use ArchivePrunedRecords trait: {$model}");
                }

                return false;
            }

            return true;
        })->values();
    }
}
