<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper;

use HelgeSverre\Prunekeeper\Commands\ArchiveCommand;
use HelgeSverre\Prunekeeper\Commands\ValidateCommand;
use HelgeSverre\Prunekeeper\Contracts\Exporter;
use HelgeSverre\Prunekeeper\Exporters\CsvExporter;
use HelgeSverre\Prunekeeper\Exporters\SqlExporter;
use HelgeSverre\Prunekeeper\Listeners\ArchiveBeforePruning;
use Illuminate\Database\Events\ModelPruningStarting;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class PrunekeeperServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-prunekeeper')
            ->hasConfigFile('prunekeeper')
            ->hasCommand(ArchiveCommand::class)
            ->hasCommand(ValidateCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Prunekeeper::class);

        $this->app->bind(Exporter::class, function ($app) {
            $format = config('prunekeeper.format', 'csv');

            if (is_string($format)) {
                $format = strtolower($format);
            }

            return match ($format) {
                'sql' => $app->make(SqlExporter::class),
                'csv' => $app->make(CsvExporter::class),
                default => throw new InvalidArgumentException("Unsupported export format: {$format}"),
            };
        });
    }

    public function packageBooted(): void
    {
        Event::listen(
            ModelPruningStarting::class,
            ArchiveBeforePruning::class
        );
    }
}
