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
        $connection = env('DB_CONNECTION', 'sqlite');

        $app['config']->set('database.default', $connection);

        // Configure database connections based on environment
        match ($connection) {
            'mysql' => $app['config']->set('database.connections.mysql', [
                'driver' => 'mysql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '3306'),
                'database' => env('DB_DATABASE', 'prunekeeper'),
                'username' => env('DB_USERNAME', 'prunekeeper'),
                'password' => env('DB_PASSWORD', 'secret'),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
            ]),
            'pgsql' => $app['config']->set('database.connections.pgsql', [
                'driver' => 'pgsql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '5432'),
                'database' => env('DB_DATABASE', 'prunekeeper'),
                'username' => env('DB_USERNAME', 'prunekeeper'),
                'password' => env('DB_PASSWORD', 'secret'),
                'charset' => 'utf8',
                'prefix' => '',
                'schema' => 'public',
            ]),
            default => $app['config']->set('database.connections.sqlite', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ]),
        };

        // Use local disk for testing (instead of S3)
        $app['config']->set('prunekeeper.disk', 'local');
        $app['config']->set('prunekeeper.path', 'prunable-exports');
        $app['config']->set('prunekeeper.enabled', true);
        $app['config']->set('prunekeeper.compression.enabled', false); // Disable compression for easier testing
        $app['config']->set('prunekeeper.compression.driver', 'zip');
    }

    protected function setUpDatabase(): void
    {
        // Drop tables if they exist (for persistent databases like MySQL/PostgreSQL)
        Schema::dropIfExists('test_soft_deletable_models');
        Schema::dropIfExists('test_prunable_models');

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
