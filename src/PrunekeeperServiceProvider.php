<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper;

use HelgeSverre\Prunekeeper\Commands\ArchiveCommand;
use HelgeSverre\Prunekeeper\Commands\ValidateCommand;
use HelgeSverre\Prunekeeper\Compression\CompressionManager;
use HelgeSverre\Prunekeeper\Contracts\CompressionDriver;
use HelgeSverre\Prunekeeper\Contracts\Exporter;
use HelgeSverre\Prunekeeper\Listeners\ArchiveBeforePruning;
use Illuminate\Database\Events\ModelPruningStarting;
use Illuminate\Support\Facades\Event;
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

        $this->app->bind(Exporter::class, fn ($app) => $app->make(Prunekeeper::class)->makeExporter());

        $this->app->singleton(CompressionManager::class, function ($app) {
            return new CompressionManager($app);
        });

        $this->app->bind(CompressionDriver::class, function ($app) {
            return $app->make(CompressionManager::class)->driver();
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
