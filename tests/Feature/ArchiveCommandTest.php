<?php

declare(strict_types=1);

use HelgeSverre\Prunekeeper\ArchivePrunedRecords;
use HelgeSverre\Prunekeeper\Tests\Fixtures\TestPrunableModel;
use HelgeSverre\Prunekeeper\Tests\Fixtures\TestSoftDeletableModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    // Clean up any existing model files in the test app/Models directory
    $modelsPath = app_path('Models');
    if (is_dir($modelsPath)) {
        array_map('unlink', glob("{$modelsPath}/*.php"));
    }
});

it('archives prunable records with default options', function () {
    TestPrunableModel::create([
        'name' => 'Old Record',
        'email' => 'old@example.com',
        'created_at' => now()->subMonths(2),
    ]);

    $this->artisan('prunekeeper:archive', ['--model' => [TestPrunableModel::class]])
        ->expectsOutputToContain('Archiving prunable records')
        ->assertExitCode(0);

    $files = Storage::disk('local')->files('prunable-exports');
    expect($files)->toHaveCount(1);
});

it('shows pretend mode output without creating files', function () {
    TestPrunableModel::create([
        'name' => 'Old Record',
        'email' => 'old@example.com',
        'created_at' => now()->subMonths(2),
    ]);

    $this->artisan('prunekeeper:archive', [
        '--model' => [TestPrunableModel::class],
        '--pretend' => true,
    ])
        ->expectsOutputToContain('1 records would be archived')
        ->assertExitCode(0);

    $files = Storage::disk('local')->files('prunable-exports');
    expect($files)->toBeEmpty();
});

it('uses csv format by default', function () {
    TestPrunableModel::create([
        'name' => 'Old Record',
        'created_at' => now()->subMonths(2),
    ]);

    $this->artisan('prunekeeper:archive', ['--model' => [TestPrunableModel::class]])
        ->assertExitCode(0);

    $files = Storage::disk('local')->files('prunable-exports');
    expect($files[0])->toEndWith('.csv');
});

it('uses sql format when specified', function () {
    TestPrunableModel::create([
        'name' => 'Old Record',
        'created_at' => now()->subMonths(2),
    ]);

    $this->artisan('prunekeeper:archive', [
        '--model' => [TestPrunableModel::class],
        '--format' => 'sql',
    ])
        ->assertExitCode(0);

    $files = Storage::disk('local')->files('prunable-exports');
    expect($files[0])->toEndWith('.sql');
});

it('disables compression with no-compress flag', function () {
    config(['prunekeeper.compression.enabled' => true]);

    TestPrunableModel::create([
        'name' => 'Old Record',
        'created_at' => now()->subMonths(2),
    ]);

    $this->artisan('prunekeeper:archive', [
        '--model' => [TestPrunableModel::class],
        '--no-compress' => true,
    ])
        ->assertExitCode(0);

    $files = Storage::disk('local')->files('prunable-exports');
    // Should NOT have .zip extension
    expect($files[0])->not->toEndWith('.zip');
});

it('compresses files when compression is enabled and no-compress is not set', function () {
    config(['prunekeeper.compression.enabled' => true]);

    TestPrunableModel::create([
        'name' => 'Old Record',
        'created_at' => now()->subMonths(2),
    ]);

    $this->artisan('prunekeeper:archive', ['--model' => [TestPrunableModel::class]])
        ->assertExitCode(0);

    $files = Storage::disk('local')->files('prunable-exports');
    expect($files[0])->toEndWith('.zip');
});

it('shows no records message when no prunable records exist', function () {
    // Create only recent records
    TestPrunableModel::create([
        'name' => 'New Record',
        'created_at' => now(),
    ]);

    $this->artisan('prunekeeper:archive', ['--model' => [TestPrunableModel::class]])
        ->expectsOutputToContain('No prunable records found')
        ->assertExitCode(0);
});

it('shows warning for model without prunable method', function () {
    $modelClass = new class extends Model
    {
        use ArchivePrunedRecords;

        protected $table = 'test_prunable_models';
    };

    $this->artisan('prunekeeper:archive', ['--model' => [get_class($modelClass)]])
        ->expectsOutputToContain('does not have a prunable() method')
        ->assertExitCode(0);
});

it('shows warning for model with archiving disabled', function () {
    $modelClass = new class extends Model
    {
        use ArchivePrunedRecords;
        use Prunable;

        protected $table = 'test_prunable_models';

        public function shouldArchiveBeforePruning(): bool
        {
            return false;
        }

        public function prunable()
        {
            return self::where('created_at', '<=', now()->subMonth());
        }
    };

    TestPrunableModel::create([
        'name' => 'Old Record',
        'created_at' => now()->subMonths(2),
    ]);

    $this->artisan('prunekeeper:archive', ['--model' => [get_class($modelClass)]])
        ->expectsOutputToContain('has archiving disabled')
        ->assertExitCode(0);
});

it('shows error for non-existent model class', function () {
    $this->artisan('prunekeeper:archive', ['--model' => ['App\\Models\\NonExistentModel']])
        ->expectsOutputToContain('Model class not found')
        ->assertExitCode(0);
});

it('shows no archivable models warning when none found', function () {
    $this->artisan('prunekeeper:archive')
        ->expectsOutputToContain('No archivable models found')
        ->assertExitCode(0);
});

it('archives multiple models', function () {
    TestPrunableModel::create([
        'name' => 'Old Prunable',
        'created_at' => now()->subMonths(2),
    ]);

    TestSoftDeletableModel::create([
        'name' => 'Old Soft Deletable',
        'created_at' => now()->subMonths(2),
    ]);

    $this->artisan('prunekeeper:archive', [
        '--model' => [
            TestPrunableModel::class,
            TestSoftDeletableModel::class,
        ],
    ])
        ->assertExitCode(0);

    $files = Storage::disk('local')->files('prunable-exports');
    expect($files)->toHaveCount(2);
});

it('includes soft-deleted records in archive', function () {
    // Create a regular old record
    TestSoftDeletableModel::create([
        'name' => 'Regular Record',
        'created_at' => now()->subMonths(2),
    ]);

    // Create and soft-delete a record
    $softDeleted = TestSoftDeletableModel::create([
        'name' => 'Soft Deleted',
        'created_at' => now()->subMonths(2),
    ]);
    $softDeleted->delete();

    $this->artisan('prunekeeper:archive', ['--model' => [TestSoftDeletableModel::class]])
        ->assertExitCode(0);

    $files = Storage::disk('local')->files('prunable-exports');
    $content = Storage::disk('local')->get($files[0]);

    expect($content)
        ->toContain('Regular Record')
        ->toContain('Soft Deleted');
});

it('displays archive details after successful archive', function () {
    TestPrunableModel::create([
        'name' => 'Old Record',
        'created_at' => now()->subMonths(2),
    ]);

    $this->artisan('prunekeeper:archive', ['--model' => [TestPrunableModel::class]])
        ->expectsOutputToContain('Archiving')
        ->assertExitCode(0);

    // Verify the archive was created with correct content
    $files = Storage::disk('local')->files('prunable-exports');
    expect($files)->toHaveCount(1);

    $content = Storage::disk('local')->get($files[0]);
    expect($content)->toContain('Old Record');
});

it('throws error for invalid format option', function () {
    TestPrunableModel::create([
        'name' => 'Old Record',
        'created_at' => now()->subMonths(2),
    ]);

    $this->artisan('prunekeeper:archive', [
        '--model' => [TestPrunableModel::class],
        '--format' => 'invalid',
    ]);
})->throws(InvalidArgumentException::class, "Invalid export format: invalid. Use 'csv' or 'sql'.");
