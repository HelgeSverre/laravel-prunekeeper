<?php

declare(strict_types=1);

use HelgeSverre\Prunekeeper\Exporters\CsvExporter;
use HelgeSverre\Prunekeeper\Tests\Fixtures\TestPrunableModel;
use HelgeSverre\Prunekeeper\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Integration tests for CsvExporter database portability.
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
function csvSkipIfUnavailable(string $driver, ?string $host, ?int $port, string $label): void
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
function csvConfigureDatabase(string $driver, ?string $host, ?int $port): void
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
function csvSetupTestTables(): void
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
function csvSeedTestData(): void
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
function csvGetDatabaseVersion(string $driver): string
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

it('exports records to CSV format', function (string $driver, ?string $host, ?int $port, string $label) {
    csvSkipIfUnavailable($driver, $host, $port, $label);
    csvConfigureDatabase($driver, $host, $port);
    csvSetupTestTables();
    csvSeedTestData();

    $version = csvGetDatabaseVersion($driver);
    echo "\n    [{$label}] {$version}";

    $exporter = new CsvExporter;
    $query = TestPrunableModel::query();

    $tempFile = $exporter->export($query);
    $content = file_get_contents($tempFile);

    expect($content)
        ->toContain('id,name,email,metadata')
        ->toContain('Integration Test User 1')
        ->toContain('Integration Test User 2')
        ->toContain('user1@example.com')
        ->toContain('user2@example.com');

    @unlink($tempFile);
})->with('databases');

it('includes correct CSV headers', function (string $driver, ?string $host, ?int $port, string $label) {
    csvSkipIfUnavailable($driver, $host, $port, $label);
    csvConfigureDatabase($driver, $host, $port);
    csvSetupTestTables();
    csvSeedTestData();

    $exporter = new CsvExporter;
    $query = TestPrunableModel::query();

    $tempFile = $exporter->export($query);
    $lines = explode("\n", trim(file_get_contents($tempFile)));

    // First line should be headers
    $headers = str_getcsv($lines[0]);

    expect($headers)
        ->toContain('id')
        ->toContain('name')
        ->toContain('email')
        ->toContain('metadata')
        ->toContain('created_at')
        ->toContain('updated_at');

    @unlink($tempFile);
})->with('databases');

it('handles special characters in CSV data', function (string $driver, ?string $host, ?int $port, string $label) {
    csvSkipIfUnavailable($driver, $host, $port, $label);
    csvConfigureDatabase($driver, $host, $port);
    csvSetupTestTables();

    DB::table('test_prunable_models')->insert([
        'name' => "Test's \"Special\" & <User>",
        'email' => 'special,comma@example.com',
        'metadata' => json_encode(['description' => "Contains 'quotes', \"double quotes\", and, commas"]),
        'created_at' => now()->subMonths(2),
        'updated_at' => now(),
    ]);

    $exporter = new CsvExporter;
    $query = TestPrunableModel::where('email', 'special,comma@example.com');

    $tempFile = $exporter->export($query);
    $content = file_get_contents($tempFile);

    // CSV should properly escape special characters
    expect($content)->toContain('special,comma@example.com');

    // Parse CSV and verify data integrity
    $lines = explode("\n", trim($content));
    expect(count($lines))->toBe(2); // header + 1 data row

    $data = str_getcsv($lines[1]);
    expect($data)->toContain("Test's \"Special\" & <User>");

    @unlink($tempFile);
})->with('databases');

it('handles JSON/array columns in CSV', function (string $driver, ?string $host, ?int $port, string $label) {
    csvSkipIfUnavailable($driver, $host, $port, $label);
    csvConfigureDatabase($driver, $host, $port);
    csvSetupTestTables();
    csvSeedTestData();

    $exporter = new CsvExporter;
    $query = TestPrunableModel::where('name', 'Integration Test User 1');

    $tempFile = $exporter->export($query);
    $content = file_get_contents($tempFile);

    // JSON should be serialized as a string in CSV
    expect($content)
        ->toContain('role')
        ->toContain('admin');

    @unlink($tempFile);
})->with('databases');

it('handles NULL values in CSV', function (string $driver, ?string $host, ?int $port, string $label) {
    csvSkipIfUnavailable($driver, $host, $port, $label);
    csvConfigureDatabase($driver, $host, $port);
    csvSetupTestTables();
    csvSeedTestData();

    $exporter = new CsvExporter;
    $query = TestPrunableModel::where('name', 'Integration Test User 2');

    $tempFile = $exporter->export($query);
    $lines = explode("\n", trim(file_get_contents($tempFile)));

    // Should have header and one data row
    expect(count($lines))->toBe(2);

    // Parse the data row - NULL values should be empty strings in CSV
    $data = str_getcsv($lines[1]);
    $headers = str_getcsv($lines[0]);

    $metadataIndex = array_search('metadata', $headers);
    expect($data[$metadataIndex])->toBe('');

    @unlink($tempFile);
})->with('databases');

it('exports only specified columns', function (string $driver, ?string $host, ?int $port, string $label) {
    csvSkipIfUnavailable($driver, $host, $port, $label);
    csvConfigureDatabase($driver, $host, $port);
    csvSetupTestTables();
    csvSeedTestData();

    $exporter = new CsvExporter;
    $query = TestPrunableModel::query();

    $tempFile = $exporter->export($query, ['id', 'name', 'email']);
    $lines = explode("\n", trim(file_get_contents($tempFile)));
    $headers = str_getcsv($lines[0]);

    expect($headers)
        ->toContain('id')
        ->toContain('name')
        ->toContain('email')
        ->not->toContain('metadata')
        ->not->toContain('created_at')
        ->not->toContain('updated_at');

    @unlink($tempFile);
})->with('databases');

it('produces valid CSV that can be parsed', function (string $driver, ?string $host, ?int $port, string $label) {
    csvSkipIfUnavailable($driver, $host, $port, $label);
    csvConfigureDatabase($driver, $host, $port);
    csvSetupTestTables();
    csvSeedTestData();

    $exporter = new CsvExporter;
    $query = TestPrunableModel::query();

    $tempFile = $exporter->export($query);

    // Parse the entire CSV file
    $handle = fopen($tempFile, 'r');
    $headers = fgetcsv($handle);
    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        $rows[] = array_combine($headers, $row);
    }
    fclose($handle);

    expect($rows)->toHaveCount(2);
    expect($rows[0]['name'])->toBe('Integration Test User 1');
    expect($rows[1]['name'])->toBe('Integration Test User 2');

    @unlink($tempFile);
})->with('databases');
