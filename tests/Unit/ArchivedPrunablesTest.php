<?php

declare(strict_types=1);

use HelgeSverre\Prunekeeper\Exceptions\InvalidColumnException;
use HelgeSverre\Prunekeeper\Prunekeeper;
use HelgeSverre\Prunekeeper\Support\ArchiveResult;
use HelgeSverre\Prunekeeper\Tests\Fixtures\TestPrunableModel;

it('generates default filename with timestamp and table name', function () {
    $manager = new Prunekeeper;
    $model = new TestPrunableModel;

    config(['prunekeeper.compression.enabled' => false]);

    $filename = $manager->generateFilename($model, 'csv');

    expect($filename)
        ->toContain('prunable-exports/')
        ->toContain('test_prunable_models')
        ->toEndWith('.csv');
});

it('uses custom filename generator when set', function () {
    $manager = new Prunekeeper;
    $model = new TestPrunableModel;

    $manager->generateFilenameUsing(function ($model, $format) {
        return "custom/{$model->getTable()}.{$format}";
    });

    $filename = $manager->generateFilename($model, 'csv');

    expect($filename)->toBe('custom/test_prunable_models.csv');
});

it('adds compression extension when compression is enabled', function () {
    $manager = new Prunekeeper;
    $model = new TestPrunableModel;

    config(['prunekeeper.compression.enabled' => true]);
    config(['prunekeeper.compression.driver' => 'zip']);

    $filename = $manager->generateFilename($model, 'csv');

    expect($filename)->toEndWith('.csv.zip');
});

it('resolves columns from model method', function () {
    $manager = new Prunekeeper;

    $model = new class extends TestPrunableModel
    {
        public function getArchivableColumns(): ?array
        {
            return ['id', 'name', 'email'];
        }
    };

    $columns = $manager->resolveColumns($model);

    expect($columns)->toBe(['id', 'name', 'email']);
});

it('uses custom columns resolver when set', function () {
    $manager = new Prunekeeper;
    $model = new TestPrunableModel;

    $manager->resolveColumnsUsing(function ($model) {
        return ['custom_column'];
    });

    $columns = $manager->resolveColumns($model);

    expect($columns)->toBe(['custom_column']);
});

it('returns null columns when no resolver is set', function () {
    $manager = new Prunekeeper;
    $model = new TestPrunableModel;

    $columns = $manager->resolveColumns($model);

    expect($columns)->toBeNull();
});

it('respects enabled config', function () {
    $manager = new Prunekeeper;

    config(['prunekeeper.enabled' => true]);
    expect($manager->isEnabled())->toBeTrue();

    config(['prunekeeper.enabled' => false]);
    expect($manager->isEnabled())->toBeFalse();
});

it('respects fail_silently config', function () {
    $manager = new Prunekeeper;

    config(['prunekeeper.fail_silently' => false]);
    expect($manager->shouldFailSilently())->toBeFalse();

    config(['prunekeeper.fail_silently' => true]);
    expect($manager->shouldFailSilently())->toBeTrue();
});

it('returns configured format', function () {
    $manager = new Prunekeeper;

    config(['prunekeeper.format' => 'csv']);
    expect($manager->getFormat())->toBe('csv');

    config(['prunekeeper.format' => 'sql']);
    expect($manager->getFormat())->toBe('sql');
});

it('returns configured chunk size', function () {
    $manager = new Prunekeeper;

    config(['prunekeeper.chunk_size' => 500]);
    expect($manager->getChunkSize())->toBe(500);

    config(['prunekeeper.chunk_size' => 2000]);
    expect($manager->getChunkSize())->toBe(2000);
});

it('returns default chunk size when configured value is less than 1', function () {
    $manager = new Prunekeeper;

    config(['prunekeeper.chunk_size' => 0]);
    expect($manager->getChunkSize())->toBe(1000);

    config(['prunekeeper.chunk_size' => -5]);
    expect($manager->getChunkSize())->toBe(1000);
});

it('caps chunk size at 10000 when configured value exceeds maximum', function () {
    $manager = new Prunekeeper;

    config(['prunekeeper.chunk_size' => 15000]);
    expect($manager->getChunkSize())->toBe(10000);

    config(['prunekeeper.chunk_size' => 100000]);
    expect($manager->getChunkSize())->toBe(10000);
});

it('uses custom table name resolver when set', function () {
    $manager = new Prunekeeper;
    $model = new TestPrunableModel;

    $manager->resolveTableNameUsing(function ($model) {
        return 'custom_table_name';
    });

    expect($manager->resolveTableName($model))->toBe('custom_table_name');
});

it('returns default table name when no resolver is set', function () {
    $manager = new Prunekeeper;
    $model = new TestPrunableModel;

    expect($manager->resolveTableName($model))->toBe('test_prunable_models');
});

it('uses custom temp file generator when set', function () {
    $manager = new Prunekeeper;
    $customPath = sys_get_temp_dir().'/custom_temp_file_'.uniqid();

    $manager->createTempFileUsing(function ($prefix) use ($customPath) {
        file_put_contents($customPath, '');

        return $customPath;
    });

    $result = $manager->createTempFile('test_');
    expect($result)->toBe($customPath);

    @unlink($customPath);
});

it('creates temp file in system temp directory by default', function () {
    $manager = new Prunekeeper;

    $tempFile = $manager->createTempFile('prunekeeper_test_');

    // Use realpath to handle macOS symlinks (/var -> /private/var)
    $tempDir = realpath(sys_get_temp_dir());
    $actualPath = realpath(dirname($tempFile));

    expect($actualPath)->toBe($tempDir);
    expect(file_exists($tempFile))->toBeTrue();

    @unlink($tempFile);
});

it('respects shouldCompress config', function () {
    $manager = new Prunekeeper;

    config(['prunekeeper.compression.enabled' => true]);
    expect($manager->shouldCompress())->toBeTrue();

    config(['prunekeeper.compression.enabled' => false]);
    expect($manager->shouldCompress())->toBeFalse();
});

it('respects shouldCleanupTempFiles config', function () {
    $manager = new Prunekeeper;

    config(['prunekeeper.cleanup_temp_files' => true]);
    expect($manager->shouldCleanupTempFiles())->toBeTrue();

    config(['prunekeeper.cleanup_temp_files' => false]);
    expect($manager->shouldCleanupTempFiles())->toBeFalse();
});

it('returns configured file open mode', function () {
    $manager = new Prunekeeper;

    config(['prunekeeper.file_open_mode' => 'w']);
    expect($manager->getFileOpenMode())->toBe('w');

    config(['prunekeeper.file_open_mode' => 'a']);
    expect($manager->getFileOpenMode())->toBe('a');
});

it('validates columns against model table', function () {
    $manager = new Prunekeeper;
    $model = new TestPrunableModel;

    // Valid columns should not throw
    $manager->validateColumns($model, ['id', 'name', 'email']);

    expect(true)->toBeTrue();
});

it('throws InvalidColumnException for invalid columns', function () {
    $manager = new Prunekeeper;
    $model = new TestPrunableModel;

    $manager->validateColumns($model, ['id', 'invalid_column']);
})->throws(InvalidColumnException::class);

it('fires before archive callback', function () {
    $manager = new Prunekeeper;
    $model = new TestPrunableModel;
    $callbackFired = false;

    $manager->beforeArchiving(function ($m) use (&$callbackFired) {
        $callbackFired = true;
        expect($m)->toBeInstanceOf(TestPrunableModel::class);
    });

    $manager->fireBeforeArchive($model);

    expect($callbackFired)->toBeTrue();
});

it('fires after archive callback with result', function () {
    $manager = new Prunekeeper;
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

    $manager->afterArchiving(function ($m, $r) use (&$callbackFired, &$receivedResult) {
        $callbackFired = true;
        $receivedResult = $r;
    });

    $manager->fireAfterArchive($model, $result);

    expect($callbackFired)->toBeTrue();
    expect($receivedResult)->toBe($result);
});
