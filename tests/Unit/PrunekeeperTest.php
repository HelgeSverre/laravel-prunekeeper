<?php

declare(strict_types=1);

use HelgeSverre\Prunekeeper\Exceptions\InvalidColumnException;
use HelgeSverre\Prunekeeper\Prunekeeper;
use HelgeSverre\Prunekeeper\Support\ArchiveResult;
use HelgeSverre\Prunekeeper\Tests\Fixtures\TestPrunableModel;
use HelgeSverre\Prunekeeper\Tests\Fixtures\TestSoftDeletableModel;

it('generates default filename with timestamp and table name', function () {
    $model = new TestPrunableModel;

    config(['prunekeeper.compression.enabled' => false]);

    $filename = Prunekeeper::generateFilename($model, 'csv');

    expect($filename)
        ->toContain('prunable-exports/')
        ->toContain('test_prunable_models')
        ->toEndWith('.csv');
});

it('uses custom filename generator when set', function () {
    $model = new TestPrunableModel;

    Prunekeeper::generateFilenameUsing(function ($model, $format) {
        return "custom/{$model->getTable()}.{$format}";
    });

    $filename = Prunekeeper::generateFilename($model, 'csv');

    expect($filename)->toBe('custom/test_prunable_models.csv');
});

it('adds compression extension when compression is enabled', function () {
    $model = new TestPrunableModel;

    config(['prunekeeper.compression.enabled' => true]);
    config(['prunekeeper.compression.driver' => 'zip']);

    $filename = Prunekeeper::generateFilename($model, 'csv');

    expect($filename)->toEndWith('.csv.zip');
});

it('resolves columns from model method', function () {
    $model = new class extends TestPrunableModel
    {
        public function getArchivableColumns(): ?array
        {
            return ['id', 'name', 'email'];
        }
    };

    $columns = Prunekeeper::resolveColumns($model);

    expect($columns)->toBe(['id', 'name', 'email']);
});

it('uses custom columns resolver when set', function () {
    $model = new TestPrunableModel;

    Prunekeeper::resolveColumnsUsing(function ($model) {
        return ['custom_column'];
    });

    $columns = Prunekeeper::resolveColumns($model);

    expect($columns)->toBe(['custom_column']);
});

it('returns null columns when no resolver is set', function () {
    $model = new TestPrunableModel;

    $columns = Prunekeeper::resolveColumns($model);

    expect($columns)->toBeNull();
});

it('respects enabled config', function () {
    config(['prunekeeper.enabled' => true]);
    expect(Prunekeeper::isEnabled())->toBeTrue();

    config(['prunekeeper.enabled' => false]);
    expect(Prunekeeper::isEnabled())->toBeFalse();
});

it('respects fail_silently config', function () {
    config(['prunekeeper.fail_silently' => false]);
    expect(Prunekeeper::shouldFailSilently())->toBeFalse();

    config(['prunekeeper.fail_silently' => true]);
    expect(Prunekeeper::shouldFailSilently())->toBeTrue();
});

it('returns configured format', function () {
    config(['prunekeeper.format' => 'csv']);
    expect(Prunekeeper::getFormat())->toBe('csv');

    config(['prunekeeper.format' => 'sql']);
    expect(Prunekeeper::getFormat())->toBe('sql');
});

it('returns configured chunk size', function () {
    config(['prunekeeper.chunk_size' => 500]);
    expect(Prunekeeper::getChunkSize())->toBe(500);

    config(['prunekeeper.chunk_size' => 2000]);
    expect(Prunekeeper::getChunkSize())->toBe(2000);
});

it('returns default chunk size when configured value is less than 1', function () {
    config(['prunekeeper.chunk_size' => 0]);
    expect(Prunekeeper::getChunkSize())->toBe(1000);

    config(['prunekeeper.chunk_size' => -5]);
    expect(Prunekeeper::getChunkSize())->toBe(1000);
});

it('caps chunk size at 10000 when configured value exceeds maximum', function () {
    config(['prunekeeper.chunk_size' => 15000]);
    expect(Prunekeeper::getChunkSize())->toBe(10000);

    config(['prunekeeper.chunk_size' => 100000]);
    expect(Prunekeeper::getChunkSize())->toBe(10000);
});

it('uses custom table name resolver when set', function () {
    $model = new TestPrunableModel;

    Prunekeeper::resolveTableNameUsing(function ($model) {
        return 'custom_table_name';
    });

    expect(Prunekeeper::resolveTableName($model))->toBe('custom_table_name');
});

it('returns default table name when no resolver is set', function () {
    $model = new TestPrunableModel;

    expect(Prunekeeper::resolveTableName($model))->toBe('test_prunable_models');
});

it('uses custom temp file generator when set', function () {
    $customPath = sys_get_temp_dir().'/custom_temp_file_'.uniqid();

    Prunekeeper::createTempFileUsing(function ($prefix) use ($customPath) {
        file_put_contents($customPath, '');

        return $customPath;
    });

    $result = Prunekeeper::createTempFile('test_');
    expect($result)->toBe($customPath);

    @unlink($customPath);
});

it('creates temp file in system temp directory by default', function () {
    $tempFile = Prunekeeper::createTempFile('prunekeeper_test_');

    // Use realpath to handle macOS symlinks (/var -> /private/var)
    $tempDir = realpath(sys_get_temp_dir());
    $actualPath = realpath(dirname($tempFile));

    expect($actualPath)->toBe($tempDir);
    expect(file_exists($tempFile))->toBeTrue();

    @unlink($tempFile);
});

it('respects shouldCompress config', function () {
    config(['prunekeeper.compression.enabled' => true]);
    expect(Prunekeeper::shouldCompress())->toBeTrue();

    config(['prunekeeper.compression.enabled' => false]);
    expect(Prunekeeper::shouldCompress())->toBeFalse();
});

it('respects shouldCleanupTempFiles config', function () {
    config(['prunekeeper.cleanup_temp_files' => true]);
    expect(Prunekeeper::shouldCleanupTempFiles())->toBeTrue();

    config(['prunekeeper.cleanup_temp_files' => false]);
    expect(Prunekeeper::shouldCleanupTempFiles())->toBeFalse();
});

it('returns configured file open mode', function () {
    config(['prunekeeper.file_open_mode' => 'w']);
    expect(Prunekeeper::getFileOpenMode())->toBe('w');

    config(['prunekeeper.file_open_mode' => 'a']);
    expect(Prunekeeper::getFileOpenMode())->toBe('a');
});

it('validates columns against model table', function () {
    $model = new TestPrunableModel;

    // Valid columns should not throw
    Prunekeeper::validateColumns($model, ['id', 'name', 'email']);

    expect(true)->toBeTrue();
});

it('throws InvalidColumnException for invalid columns', function () {
    $model = new TestPrunableModel;

    Prunekeeper::validateColumns($model, ['id', 'invalid_column']);
})->throws(InvalidColumnException::class);

it('fires before archive callback', function () {
    $model = new TestPrunableModel;
    $callbackFired = false;

    Prunekeeper::beforeArchiving(function ($m) use (&$callbackFired) {
        $callbackFired = true;
        expect($m)->toBeInstanceOf(TestPrunableModel::class);
    });

    Prunekeeper::fireBeforeArchive($model);

    expect($callbackFired)->toBeTrue();
});

it('fires after archive callback with result', function () {
    $model = new TestPrunableModel;
    $callbackFired = false;
    $receivedResult = null;

    $result = new ArchiveResult(
        modelClass: TestPrunableModel::class,
        storagePath: 'test/path.csv',
        recordCount: 10,
        fileSize: 1024,
        format: 'csv',
        compressed: false
    );

    Prunekeeper::afterArchiving(function ($m, $r) use (&$callbackFired, &$receivedResult) {
        $callbackFired = true;
        $receivedResult = $r;
    });

    Prunekeeper::fireAfterArchive($model, $result);

    expect($callbackFired)->toBeTrue();
    expect($receivedResult)->toBe($result);
});

it('flushes all registered callbacks and resolvers', function () {
    Prunekeeper::generateFilenameUsing(fn ($model, $format) => 'custom/path.'.$format);
    Prunekeeper::resolveColumnsUsing(fn ($model) => ['custom_column']);
    Prunekeeper::beforeArchiving(fn () => null);
    Prunekeeper::afterArchiving(fn () => null);
    Prunekeeper::createTempFileUsing(fn ($prefix) => sys_get_temp_dir().'/custom_temp');
    Prunekeeper::resolveTableNameUsing(fn ($model) => 'custom_table');

    // Verify callbacks are set
    $model = new TestPrunableModel;
    expect(Prunekeeper::resolveColumns($model))->toBe(['custom_column']);
    expect(Prunekeeper::resolveTableName($model))->toBe('custom_table');

    Prunekeeper::flushState();

    // After flush, these should use defaults
    config(['prunekeeper.compression.enabled' => false]);
    $filename = Prunekeeper::generateFilename($model, 'csv');
    expect($filename)->toContain('test_prunable_models');

    expect(Prunekeeper::resolveColumns($model))->toBeNull();
    expect(Prunekeeper::resolveTableName($model))->toBe('test_prunable_models');
});

it('includes soft-deleted records in prunable query for SoftDeletes models', function () {
    $old = TestSoftDeletableModel::create([
        'name' => 'Regular Old Record',
        'created_at' => now()->subMonths(2),
    ]);
    $softDeleted = TestSoftDeletableModel::create([
        'name' => 'Soft Deleted Record',
        'created_at' => now()->subMonths(2),
    ]);
    $softDeleted->delete();

    $query = Prunekeeper::makePrunableQuery(new TestSoftDeletableModel);

    $results = $query->get()->pluck('name')->all();
    expect($results)->toContain('Regular Old Record', 'Soft Deleted Record');
});

it('does not include withTrashed for non-SoftDeletes models', function () {
    TestPrunableModel::create([
        'name' => 'Old Record',
        'created_at' => now()->subMonths(2),
    ]);

    $query = Prunekeeper::makePrunableQuery(new TestPrunableModel);

    // Should work without error (no withTrashed call on non-soft-delete model)
    expect($query->count())->toBe(1);
});

it('respects configured storage disk', function () {
    config(['prunekeeper.disk' => 'local']);

    $disk = Prunekeeper::disk();

    expect($disk)->toBeInstanceOf(\Illuminate\Contracts\Filesystem\Filesystem::class);
});
