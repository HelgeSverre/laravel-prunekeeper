<?php

declare(strict_types=1);

use HelgeSverre\Prunekeeper\ArchivePrunedRecords;
use HelgeSverre\Prunekeeper\Contracts\Exporter;
use HelgeSverre\Prunekeeper\Exporters\CsvExporter;
use HelgeSverre\Prunekeeper\Listeners\ArchiveBeforePruning;
use HelgeSverre\Prunekeeper\Prunekeeper;
use HelgeSverre\Prunekeeper\Tests\Fixtures\TestMassPrunableModel;
use HelgeSverre\Prunekeeper\Tests\Fixtures\TestPrunableModel;
use HelgeSverre\Prunekeeper\Tests\Fixtures\TestSoftDeletableModel;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Events\ModelPruningStarting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

it('archives prunable records before pruning', function () {
    // Create some old records that should be pruned
    TestPrunableModel::create([
        'name' => 'Old Record 1',
        'email' => 'old1@example.com',
        'created_at' => now()->subMonths(2),
    ]);

    TestPrunableModel::create([
        'name' => 'Old Record 2',
        'email' => 'old2@example.com',
        'created_at' => now()->subMonths(3),
    ]);

    // Create a recent record that should NOT be pruned
    TestPrunableModel::create([
        'name' => 'New Record',
        'email' => 'new@example.com',
        'created_at' => now(),
    ]);

    $listener = app(ArchiveBeforePruning::class);
    $event = new ModelPruningStarting([TestPrunableModel::class]);

    $listener->handle($event);

    // Check that a file was created in storage
    $files = Storage::disk('local')->files('prunable-exports');
    expect($files)->toHaveCount(1);

    // Check that the file contains the old records
    $content = Storage::disk('local')->get($files[0]);
    expect($content)
        ->toContain('Old Record 1')
        ->toContain('Old Record 2')
        ->not->toContain('New Record');
});

it('skips archiving when disabled', function () {
    config(['prunekeeper.enabled' => false]);

    TestPrunableModel::create([
        'name' => 'Old Record',
        'created_at' => now()->subMonths(2),
    ]);

    $listener = app(ArchiveBeforePruning::class);
    $event = new ModelPruningStarting([TestPrunableModel::class]);

    $listener->handle($event);

    $files = Storage::disk('local')->files('prunable-exports');
    expect($files)->toBeEmpty();
});

it('skips models without the ArchivePrunedRecords trait', function () {
    // Create a model class without the trait for this test
    $modelWithoutTrait = new class extends Model
    {
        use Prunable;

        protected $table = 'test_prunable_models';

        public function prunable()
        {
            return self::where('created_at', '<=', now()->subMonth());
        }
    };

    TestPrunableModel::create([
        'name' => 'Old Record',
        'created_at' => now()->subMonths(2),
    ]);

    $listener = app(ArchiveBeforePruning::class);
    $event = new ModelPruningStarting([get_class($modelWithoutTrait)]);

    $listener->handle($event);

    $files = Storage::disk('local')->files('prunable-exports');
    expect($files)->toBeEmpty();
});

it('skips archiving when no prunable records exist', function () {
    // Create only recent records
    TestPrunableModel::create([
        'name' => 'New Record',
        'created_at' => now(),
    ]);

    $listener = app(ArchiveBeforePruning::class);
    $event = new ModelPruningStarting([TestPrunableModel::class]);

    $listener->handle($event);

    $files = Storage::disk('local')->files('prunable-exports');
    expect($files)->toBeEmpty();
});

it('uses custom filename generator', function () {
    Prunekeeper::generateFilenameUsing(function ($model, $format) {
        return "custom-path/custom-name.{$format}";
    });

    TestPrunableModel::create([
        'name' => 'Old Record',
        'created_at' => now()->subMonths(2),
    ]);

    $listener = app(ArchiveBeforePruning::class);
    $event = new ModelPruningStarting([TestPrunableModel::class]);

    $listener->handle($event);

    expect(Storage::disk('local')->exists('custom-path/custom-name.csv'))->toBeTrue();
});

it('fires before and after callbacks', function () {
    $beforeCalled = false;
    $afterCalled = false;
    $afterResult = null;

    Prunekeeper::beforeArchiving(function ($model) use (&$beforeCalled) {
        $beforeCalled = true;
        expect($model)->toBeInstanceOf(TestPrunableModel::class);
    });

    Prunekeeper::afterArchiving(function ($model, $result) use (&$afterCalled, &$afterResult) {
        $afterCalled = true;
        $afterResult = $result;
    });

    TestPrunableModel::create([
        'name' => 'Old Record',
        'created_at' => now()->subMonths(2),
    ]);

    $listener = app(ArchiveBeforePruning::class);
    $event = new ModelPruningStarting([TestPrunableModel::class]);

    $listener->handle($event);

    expect($beforeCalled)->toBeTrue();
    expect($afterCalled)->toBeTrue();
    expect($afterResult->recordCount)->toBe(1);
});

it('handles JSON columns correctly in CSV export', function () {
    TestPrunableModel::create([
        'name' => 'Old Record',
        'metadata' => ['key' => 'value', 'nested' => ['a' => 1]],
        'created_at' => now()->subMonths(2),
    ]);

    $listener = app(ArchiveBeforePruning::class);
    $event = new ModelPruningStarting([TestPrunableModel::class]);

    $listener->handle($event);

    $files = Storage::disk('local')->files('prunable-exports');
    $content = Storage::disk('local')->get($files[0]);

    // JSON should be encoded as a string in CSV (quotes are escaped as "" per RFC 4180)
    expect($content)->toContain('key');
    expect($content)->toContain('value');
    expect($content)->toContain('nested');
});

it('skips models with ArchivePrunedRecords but without prunable method', function () {
    // Create a model class with the trait but without Prunable
    $modelWithTraitButNoPrunable = new class extends Model
    {
        use ArchivePrunedRecords;

        protected $table = 'test_prunable_models';
    };

    TestPrunableModel::create([
        'name' => 'Old Record',
        'created_at' => now()->subMonths(2),
    ]);

    $listener = app(ArchiveBeforePruning::class);
    $event = new ModelPruningStarting([get_class($modelWithTraitButNoPrunable)]);

    // Should not throw, just skip gracefully
    $listener->handle($event);

    $files = Storage::disk('local')->files('prunable-exports');
    expect($files)->toBeEmpty();
});

it('archives MassPrunable records before pruning', function () {
    // Create some old records that should be pruned
    TestMassPrunableModel::create([
        'name' => 'Old Mass Record 1',
        'email' => 'old1@example.com',
        'created_at' => now()->subMonths(2),
    ]);

    TestMassPrunableModel::create([
        'name' => 'Old Mass Record 2',
        'email' => 'old2@example.com',
        'created_at' => now()->subMonths(3),
    ]);

    // Create a recent record that should NOT be pruned
    TestMassPrunableModel::create([
        'name' => 'New Mass Record',
        'email' => 'new@example.com',
        'created_at' => now(),
    ]);

    $listener = app(ArchiveBeforePruning::class);
    $event = new ModelPruningStarting([TestMassPrunableModel::class]);

    $listener->handle($event);

    // Check that a file was created in storage
    $files = Storage::disk('local')->files('prunable-exports');
    expect($files)->toHaveCount(1);

    // Check that the file contains the old records
    $content = Storage::disk('local')->get($files[0]);
    expect($content)
        ->toContain('Old Mass Record 1')
        ->toContain('Old Mass Record 2')
        ->not->toContain('New Mass Record');
});

it('skips archiving when model shouldArchiveBeforePruning returns false', function () {
    // Create a model class that returns false from shouldArchiveBeforePruning
    $modelClass = new class extends Model
    {
        use ArchivePrunedRecords;
        use Prunable;

        protected $table = 'test_prunable_models';

        protected $guarded = [];

        public function shouldArchiveBeforePruning(): bool
        {
            return false;
        }

        public function prunable()
        {
            return self::where('created_at', '<=', now()->subMonth());
        }
    };

    // Create a record that would be pruned
    TestPrunableModel::create([
        'name' => 'Old Record',
        'created_at' => now()->subMonths(2),
    ]);

    $listener = app(ArchiveBeforePruning::class);
    $event = new ModelPruningStarting([get_class($modelClass)]);

    $listener->handle($event);

    $files = Storage::disk('local')->files('prunable-exports');
    expect($files)->toBeEmpty();
});

it('uses model getArchiveFilename method for custom filename', function () {
    // Create a model class with custom filename
    $modelClass = new class extends Model
    {
        use ArchivePrunedRecords;
        use Prunable;

        protected $table = 'test_prunable_models';

        protected $guarded = [];

        public function getArchiveFilename(string $format): ?string
        {
            return "custom-model-path/my-archive.{$format}";
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

    $listener = app(ArchiveBeforePruning::class);
    $event = new ModelPruningStarting([get_class($modelClass)]);

    $listener->handle($event);

    expect(Storage::disk('local')->exists('custom-model-path/my-archive.csv'))->toBeTrue();
});

it('includes soft-deleted records when model uses SoftDeletes', function () {
    $softDeletableModel = new TestSoftDeletableModel;

    // Create a regular old record
    TestSoftDeletableModel::create([
        'name' => 'Regular Old Record',
        'email' => 'regular@example.com',
        'created_at' => now()->subMonths(2),
    ]);

    // Create a soft-deleted old record
    $softDeleted = TestSoftDeletableModel::create([
        'name' => 'Soft Deleted Record',
        'email' => 'deleted@example.com',
        'created_at' => now()->subMonths(2),
    ]);
    $softDeleted->delete();

    // Create a recent record that should NOT be pruned
    TestSoftDeletableModel::create([
        'name' => 'New Record',
        'email' => 'new@example.com',
        'created_at' => now(),
    ]);

    $listener = app(ArchiveBeforePruning::class);
    $event = new ModelPruningStarting([TestSoftDeletableModel::class]);

    $listener->handle($event);

    $files = Storage::disk('local')->files('prunable-exports');
    expect($files)->toHaveCount(1);

    $content = Storage::disk('local')->get($files[0]);
    expect($content)
        ->toContain('Regular Old Record')
        ->toContain('Soft Deleted Record')
        ->not->toContain('New Record');
});

it('throws exception when fail_silently is false and archive fails', function () {
    config(['prunekeeper.fail_silently' => false]);

    // Mock the exporter to throw an exception - bind to CsvExporter which makeExporter() resolves
    $mockExporter = Mockery::mock(Exporter::class);
    $mockExporter->shouldReceive('export')->andThrow(new RuntimeException('Export failed'));
    $mockExporter->shouldReceive('extension')->andReturn('csv');

    app()->instance(CsvExporter::class, $mockExporter);

    TestPrunableModel::create([
        'name' => 'Old Record',
        'created_at' => now()->subMonths(2),
    ]);

    $listener = app(ArchiveBeforePruning::class);
    $event = new ModelPruningStarting([TestPrunableModel::class]);

    $listener->handle($event);
})->throws(RuntimeException::class, 'Export failed');

it('catches exception and logs when fail_silently is true', function () {
    config(['prunekeeper.fail_silently' => true]);

    // Mock the exporter to throw an exception - bind to CsvExporter which makeExporter() resolves
    $mockExporter = Mockery::mock(Exporter::class);
    $mockExporter->shouldReceive('export')->andThrow(new RuntimeException('Export failed'));
    $mockExporter->shouldReceive('extension')->andReturn('csv');

    app()->instance(CsvExporter::class, $mockExporter);

    Log::shouldReceive('info')->once();
    Log::shouldReceive('error')
        ->once()
        ->withArgs(function ($message, $context) {
            return str_contains($message, 'Failed to archive') &&
                str_contains($context['error'], 'Export failed');
        });

    TestPrunableModel::create([
        'name' => 'Old Record',
        'created_at' => now()->subMonths(2),
    ]);

    $listener = app(ArchiveBeforePruning::class);
    $event = new ModelPruningStarting([TestPrunableModel::class]);

    // Should not throw
    $listener->handle($event);

    $files = Storage::disk('local')->files('prunable-exports');
    expect($files)->toBeEmpty();
});

it('cleans up temp files when cleanup_temp_files is true', function () {
    config(['prunekeeper.cleanup_temp_files' => true]);

    // Clean up any existing temp files first
    array_map('unlink', glob(sys_get_temp_dir().'/prunekeeper_csv_*'));
    array_map('unlink', glob(sys_get_temp_dir().'/prunekeeper_sql_*'));

    TestPrunableModel::create([
        'name' => 'Old Record',
        'created_at' => now()->subMonths(2),
    ]);

    $listener = app(ArchiveBeforePruning::class);
    $event = new ModelPruningStarting([TestPrunableModel::class]);

    $listener->handle($event);

    // Verify archive was created
    $files = Storage::disk('local')->files('prunable-exports');
    expect($files)->toHaveCount(1);

    // Temp files should be cleaned up - check for csv/sql specific temp files
    $csvTempFiles = glob(sys_get_temp_dir().'/prunekeeper_csv_*');
    $sqlTempFiles = glob(sys_get_temp_dir().'/prunekeeper_sql_*');
    expect($csvTempFiles)->toBeEmpty();
    expect($sqlTempFiles)->toBeEmpty();
});

it('throws exception when storage upload fails', function () {
    config(['prunekeeper.fail_silently' => false]);

    // Mock the storage disk to return false on put
    Storage::shouldReceive('disk')
        ->andReturn(Mockery::mock(Filesystem::class, function ($mock) {
            $mock->shouldReceive('put')
                ->andReturn(false);
        }));

    TestPrunableModel::create([
        'name' => 'Old Record',
        'created_at' => now()->subMonths(2),
    ]);

    $listener = app(ArchiveBeforePruning::class);
    $event = new ModelPruningStarting([TestPrunableModel::class]);

    $listener->handle($event);
})->throws(RuntimeException::class, 'Failed to upload archive to storage');

it('logs error when storage upload fails with fail_silently enabled', function () {
    config(['prunekeeper.fail_silently' => true]);

    // Mock the storage disk to return false on put
    Storage::shouldReceive('disk')
        ->andReturn(Mockery::mock(Filesystem::class, function ($mock) {
            $mock->shouldReceive('put')
                ->andReturn(false);
        }));

    Log::shouldReceive('info')->once();
    Log::shouldReceive('error')
        ->once()
        ->withArgs(function ($message, $context) {
            return str_contains($message, 'Failed to archive') &&
                str_contains($context['error'], 'Failed to upload archive to storage');
        });

    TestPrunableModel::create([
        'name' => 'Old Record',
        'created_at' => now()->subMonths(2),
    ]);

    $listener = app(ArchiveBeforePruning::class);
    $event = new ModelPruningStarting([TestPrunableModel::class]);

    // Should not throw
    $listener->handle($event);
});

it('throws exception when export produces empty file', function () {
    config(['prunekeeper.fail_silently' => false]);

    // Mock the exporter to create an empty file - bind to CsvExporter which makeExporter() resolves
    $mockExporter = Mockery::mock(Exporter::class);
    $mockExporter->shouldReceive('export')->andReturnUsing(function () {
        $tempFile = tempnam(sys_get_temp_dir(), 'empty_');
        // File exists but is empty (0 bytes)
        file_put_contents($tempFile, '');

        return $tempFile;
    });
    $mockExporter->shouldReceive('extension')->andReturn('csv');

    app()->instance(CsvExporter::class, $mockExporter);

    TestPrunableModel::create([
        'name' => 'Old Record',
        'created_at' => now()->subMonths(2),
    ]);

    $listener = app(ArchiveBeforePruning::class);
    $event = new ModelPruningStarting([TestPrunableModel::class]);

    $listener->handle($event);
})->throws(RuntimeException::class, 'Export produced empty file');

it('includes expected record count in empty file error message', function () {
    config(['prunekeeper.fail_silently' => false]);

    // Mock the exporter to create an empty file - bind to CsvExporter which makeExporter() resolves
    $mockExporter = Mockery::mock(Exporter::class);
    $mockExporter->shouldReceive('export')->andReturnUsing(function () {
        $tempFile = tempnam(sys_get_temp_dir(), 'empty_');
        file_put_contents($tempFile, '');

        return $tempFile;
    });
    $mockExporter->shouldReceive('extension')->andReturn('csv');

    app()->instance(CsvExporter::class, $mockExporter);

    // Create 3 old records to verify count in error message
    for ($i = 0; $i < 3; $i++) {
        TestPrunableModel::create([
            'name' => "Old Record $i",
            'created_at' => now()->subMonths(2),
        ]);
    }

    $listener = app(ArchiveBeforePruning::class);
    $event = new ModelPruningStarting([TestPrunableModel::class]);

    try {
        $listener->handle($event);
        $this->fail('Expected RuntimeException to be thrown');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('Expected 3 records');
    }
});

it('throws exception when export file does not exist', function () {
    config(['prunekeeper.fail_silently' => false]);

    // Mock the exporter to return a non-existent file path - bind to CsvExporter which makeExporter() resolves
    $mockExporter = Mockery::mock(Exporter::class);
    $mockExporter->shouldReceive('export')->andReturn('/nonexistent/path/to/file.csv');
    $mockExporter->shouldReceive('extension')->andReturn('csv');

    app()->instance(CsvExporter::class, $mockExporter);

    TestPrunableModel::create([
        'name' => 'Old Record',
        'created_at' => now()->subMonths(2),
    ]);

    $listener = app(ArchiveBeforePruning::class);
    $event = new ModelPruningStarting([TestPrunableModel::class]);

    $listener->handle($event);
})->throws(RuntimeException::class, 'Export produced empty file');
