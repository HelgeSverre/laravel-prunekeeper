<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper;

use Illuminate\Support\Collection;
use Symfony\Component\Finder\Finder;

class ArchivableModels
{
    /**
     * Get all models using the ArchivePrunedRecords trait.
     *
     * Scans configured paths (supports glob patterns) for PHP files
     * and filters to only those using the ArchivePrunedRecords trait.
     *
     * @return Collection<int, class-string>
     */
    public static function get(): Collection
    {
        $paths = config('prunekeeper.models_path') ?? ['app/Models', 'app'];
        $paths = is_array($paths) ? $paths : [$paths];
        $paths = array_filter($paths); // Remove any null/empty values

        return collect($paths)
            ->flatMap(fn (string $pattern) => self::discoverInPath($pattern))
            ->unique()
            ->filter(fn (string $class) => class_exists($class))
            ->filter(fn (string $class) => in_array(ArchivePrunedRecords::class, class_uses_recursive($class)))
            ->values();
    }

    /**
     * Discover model classes in a given path pattern.
     *
     * Supports simple paths, single wildcard patterns (asterisk),
     * and recursive wildcard patterns (double asterisk).
     *
     * @return array<int, string>
     */
    private static function discoverInPath(string $pattern): array
    {
        $basePath = base_path();
        $directories = self::expandPathPattern($basePath, $pattern);

        $models = [];
        foreach ($directories as $dir) {
            if (! is_dir($dir)) {
                continue;
            }

            // Calculate namespace from relative path: app/Foo/Bar → App\Foo\Bar
            $relativePath = str_replace($basePath.'/', '', $dir);
            $namespace = self::pathToNamespace($relativePath);

            try {
                $finder = (new Finder)->files()->name('*.php')->in($dir);

                foreach ($finder as $file) {
                    $className = $namespace.'\\'.str_replace(
                        ['/', '.php'],
                        ['\\', ''],
                        $file->getRelativePathname()
                    );
                    $models[] = $className;
                }
            } catch (\Exception) {
                // Directory not readable or other Finder error - skip silently
                continue;
            }
        }

        return $models;
    }

    /**
     * Expand a path pattern to actual directory paths.
     *
     * @return array<int, string>
     */
    private static function expandPathPattern(string $basePath, string $pattern): array
    {
        $fullPattern = $basePath.'/'.$pattern;

        // Handle recursive wildcard ** patterns
        if (str_contains($pattern, '**')) {
            return self::expandRecursivePattern($basePath, $pattern);
        }

        // Handle simple wildcard * patterns
        if (str_contains($pattern, '*')) {
            $matches = glob($fullPattern, GLOB_ONLYDIR);

            return $matches !== false ? $matches : [];
        }

        // No wildcards - return path if it exists
        return is_dir($fullPattern) ? [$fullPattern] : [];
    }

    /**
     * Expand recursive wildcard patterns (double asterisk) using Symfony Finder.
     *
     * Finds all matching directories recursively under the search base.
     *
     * @return array<int, string>
     */
    private static function expandRecursivePattern(string $basePath, string $pattern): array
    {
        // Split pattern at ** to get base path and target directory name
        // e.g., 'app/Modules/**/Models' → base: 'app/Modules', target: 'Models'
        $parts = preg_split('/\*\*\/?/', $pattern, 2);

        if ($parts === false || count($parts) < 2) {
            return [];
        }

        $searchBase = rtrim($basePath.'/'.trim($parts[0], '/'), '/');
        $targetName = trim($parts[1], '/');

        if (! is_dir($searchBase) || empty($targetName)) {
            return [];
        }

        try {
            $finder = (new Finder)
                ->directories()
                ->name($targetName)
                ->in($searchBase);

            $directories = [];
            foreach ($finder as $dir) {
                $directories[] = $dir->getRealPath();
            }

            return $directories;
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * Convert a relative path to a PSR-4 style namespace.
     *
     * Example: 'app/Domain/Users/Models' → 'App\Domain\Users\Models'
     */
    private static function pathToNamespace(string $relativePath): string
    {
        return collect(explode('/', $relativePath))
            ->map(fn (string $segment) => ucfirst($segment))
            ->implode('\\');
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
