<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper\Tests;

use HelgeSverre\Prunekeeper\PrunekeeperServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDatabase();
    }

    protected function getPackageProviders($app): array
    {
        return [
            PrunekeeperServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Use SQLite in-memory database for testing
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Use local disk for testing (instead of S3)
        $app['config']->set('prunekeeper.disk', 'local');
        $app['config']->set('prunekeeper.path', 'prunable-exports');
        $app['config']->set('prunekeeper.enabled', true);
        $app['config']->set('prunekeeper.compress', false); // Disable compression for easier testing
    }

    protected function setUpDatabase(): void
    {
        Schema::create('test_prunable_models', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('test_soft_deletable_models', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
}
