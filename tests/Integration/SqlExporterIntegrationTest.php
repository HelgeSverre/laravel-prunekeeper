<?php

declare(strict_types=1);

use HelgeSverre\Prunekeeper\Exporters\SqlExporter;
use HelgeSverre\Prunekeeper\Tests\Fixtures\TestPrunableModel;
use HelgeSverre\Prunekeeper\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Integration tests for SqlExporter database portability.
 *
 * These tests use Pest datasets to run against all available databases.
 * Start databases with: docker-compose up -d
 * Run tests with: ./vendor/bin/pest tests/Integration
 */
uses(TestCase::class);

/**
 * Skip test if database is unavailable.
 * - In CI: Fails (databases should be available)
 * - Locally: Skips with clear message
 */
function skipIfUnavailable(string $driver, ?string $host, ?int $port, string $label): void
{
    if ($driver === 'sqlite') {
        return; // SQLite is always available
    }

    if (! canConnectToDatabase($driver, $host, $port)) {
        // In CI, fail - databases should be available
        if (getenv('CI') || getenv('GITHUB_ACTIONS')) {
            throw new RuntimeException("Database {$label} unavailable in CI - check service configuration");
        }

        // Locally, skip with clear message
        test()->markTestSkipped("Database {$label} not available (Docker not running?)");
    }
}

/**
 * Configure and connect to a specific database for testing.
 */
function configureDatabase(string $driver, ?string $host, ?int $port): void
{
    if ($driver === 'sqlite') {
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);
    } else {
        config(['database.default' => $driver]);
        config(["database.connections.{$driver}" => [
            'driver' => $driver,
            'host' => $host,
            'port' => $port,
            'database' => 'prunekeeper',
            'username' => 'prunekeeper',
            'password' => 'secret',
            'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8',
            'collation' => $driver === 'mysql' ? 'utf8mb4_unicode_ci' : null,
            'prefix' => '',
            'schema' => $driver === 'pgsql' ? 'public' : null,
        ]]);
    }

    // Purge existing connections to force reconnection
    DB::purge();
}

/**
 * Set up test tables for a database.
 */
function setupTestTables(): void
{
    Schema::dropIfExists('test_prunable_models');

    Schema::create('test_prunable_models', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email')->nullable();
        $table->json('metadata')->nullable();
        $table->timestamps();
    });
}

/**
 * Seed test data.
 */
function seedTestData(): void
{
    DB::table('test_prunable_models')->insert([
        'name' => 'Integration Test User 1',
        'email' => 'user1@example.com',
        'metadata' => json_encode(['role' => 'admin', 'level' => 5]),
        'created_at' => now()->subMonths(2),
        'updated_at' => now(),
    ]);

    DB::table('test_prunable_models')->insert([
        'name' => 'Integration Test User 2',
        'email' => 'user2@example.com',
        'metadata' => null,
        'created_at' => now()->subMonths(3),
        'updated_at' => now(),
    ]);
}

/**
 * Get database version string.
 */
function getDatabaseVersion(string $driver): string
{
    try {
        return match ($driver) {
            'mysql' => DB::selectOne('SELECT VERSION() as version')->version ?? '',
            'pgsql' => DB::selectOne('SELECT version() as version')->version ?? '',
            'sqlite' => DB::selectOne('SELECT sqlite_version() as version')->version ?? '',
            default => 'unknown',
        };
    } catch (Throwable) {
        return 'unknown';
    }
}

it('generates valid INSERT statements', function (string $driver, ?string $host, ?int $port, string $label) {
    skipIfUnavailable($driver, $host, $port, $label);
    configureDatabase($driver, $host, $port);
    setupTestTables();
    seedTestData();

    $version = getDatabaseVersion($driver);
    echo "\n    [{$label}] {$version}";

    $exporter = new SqlExporter;
    $query = TestPrunableModel::query();

    $tempFile = $exporter->export($query);
    $content = file_get_contents($tempFile);

    expect($content)
        ->toContain('INSERT INTO')
        ->toContain('test_prunable_models')
        ->toContain('Integration Test User 1')
        ->toContain('Integration Test User 2');

    @unlink($tempFile);
})->with('databases');

it('uses correct identifier quoting', function (string $driver, ?string $host, ?int $port, string $label) {
    skipIfUnavailable($driver, $host, $port, $label);
    configureDatabase($driver, $host, $port);
    setupTestTables();
    seedTestData();

    $exporter = new SqlExporter;
    $query = TestPrunableModel::query();

    $tempFile = $exporter->export($query);
    $content = file_get_contents($tempFile);

    // Check for driver-specific quoting
    $expectedPattern = match ($driver) {
        'mysql' => '/INSERT INTO `test_prunable_models`/',
        'pgsql', 'sqlite' => '/INSERT INTO "test_prunable_models"/',
        'sqlsrv' => '/INSERT INTO \[test_prunable_models\]/',
        default => '/INSERT INTO/',
    };

    expect($content)->toMatch($expectedPattern);

    @unlink($tempFile);
})->with('databases');

it('handles special characters in data', function (string $driver, ?string $host, ?int $port, string $label) {
    skipIfUnavailable($driver, $host, $port, $label);
    configureDatabase($driver, $host, $port);
    setupTestTables();

    DB::table('test_prunable_models')->insert([
        'name' => "Test's \"Special\" & <User>",
        'email' => "special'quotes@example.com",
        'metadata' => json_encode(['description' => "Contains 'quotes' and \"double quotes\""]),
        'created_at' => now()->subMonths(2),
        'updated_at' => now(),
    ]);

    $exporter = new SqlExporter;
    $query = TestPrunableModel::where('email', "special'quotes@example.com");

    $tempFile = $exporter->export($query);
    $content = file_get_contents($tempFile);

    expect($content)->toContain('INSERT INTO');

    $statements = array_filter(
        explode("\n", $content),
        fn ($line) => str_starts_with(trim($line), 'INSERT INTO')
    );

    expect(count($statements))->toBe(1);

    @unlink($tempFile);
})->with('databases');

it('handles JSON/array columns', function (string $driver, ?string $host, ?int $port, string $label) {
    skipIfUnavailable($driver, $host, $port, $label);
    configureDatabase($driver, $host, $port);
    setupTestTables();
    seedTestData();

    $exporter = new SqlExporter;
    $query = TestPrunableModel::where('name', 'Integration Test User 1');

    $tempFile = $exporter->export($query);
    $content = file_get_contents($tempFile);

    expect($content)
        ->toContain('INSERT INTO')
        ->toContain('role')
        ->toContain('admin');

    @unlink($tempFile);
})->with('databases');

it('handles NULL values', function (string $driver, ?string $host, ?int $port, string $label) {
    skipIfUnavailable($driver, $host, $port, $label);
    configureDatabase($driver, $host, $port);
    setupTestTables();
    seedTestData();

    $exporter = new SqlExporter;
    $query = TestPrunableModel::where('name', 'Integration Test User 2');

    $tempFile = $exporter->export($query);
    $content = file_get_contents($tempFile);

    expect($content)->toContain('NULL');

    @unlink($tempFile);
})->with('databases');

it('can re-import exported SQL (PostgreSQL/SQLite only)', function (string $driver, ?string $host, ?int $port, string $label) {
    skipIfUnavailable($driver, $host, $port, $label);

    // Skip re-import test for MySQL/MariaDB - datetime format issues
    if ($driver === 'mysql') {
        test()->markTestSkipped('MySQL re-import requires datetime format conversion');
    }

    configureDatabase($driver, $host, $port);
    setupTestTables();
    seedTestData();

    $exporter = new SqlExporter;
    $query = TestPrunableModel::query();

    $tempFile = $exporter->export($query);
    $content = file_get_contents($tempFile);

    $statements = array_filter(
        explode("\n", $content),
        fn ($line) => str_starts_with(trim($line), 'INSERT INTO')
    );

    // Delete existing records
    DB::table('test_prunable_models')->delete();
    expect(DB::table('test_prunable_models')->count())->toBe(0);

    // Re-import
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (! empty($statement)) {
            DB::statement($statement);
        }
    }

    expect(DB::table('test_prunable_models')->count())->toBe(2);

    @unlink($tempFile);
})->with('databases');
