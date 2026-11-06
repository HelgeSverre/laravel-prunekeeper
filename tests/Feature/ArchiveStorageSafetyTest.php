<?php

declare(strict_types=1);

use HelgeSverre\Prunekeeper\Contracts\CompressionDriver;
use HelgeSverre\Prunekeeper\Exporters\CsvExporter;
use HelgeSverre\Prunekeeper\Prunekeeper;
use HelgeSverre\Prunekeeper\Tests\Fixtures\TestPrunableModel;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    TestPrunableModel::create(['name' => 'Retained data', 'created_at' => now()->subMonths(2)]);
});

it('preserves an existing archive instead of overwriting it', function () {
    Prunekeeper::generateFilenameUsing(fn () => 'archives/existing.csv');
    Storage::disk('local')->put('archives/existing.csv', 'original archive');

    try {
        Prunekeeper::archive(new TestPrunableModel, TestPrunableModel::query(), new CsvExporter, false);
        test()->fail('Expected an archive collision exception');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('already exists');
    }

    expect(Storage::disk('local')->get('archives/existing.csv'))->toBe('original archive');
});

it('rejects an empty output from a custom compression driver', function () {
    $compressed = tempnam(sys_get_temp_dir(), 'prunekeeper_empty_compressed_');
    $driver = Mockery::mock(CompressionDriver::class);
    $driver->shouldReceive('extension')->andReturn('custom');
    $driver->shouldReceive('compress')->andReturn($compressed);
    Prunekeeper::compression()->extend('empty-test', fn () => $driver);

    try {
        Prunekeeper::archive(new TestPrunableModel, TestPrunableModel::query(), new CsvExporter, true, compressionDriver: 'empty-test');
        test()->fail('Expected an empty compressed archive exception');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('Compression produced');
    } finally {
        if (file_exists($compressed)) {
            unlink($compressed);
        }
    }

    expect(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('cleans up a partial storage object when upload fails', function (bool $throw) {
    Prunekeeper::generateFilenameUsing(fn () => 'archives/partial.csv');
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('exists')->with('archives/partial.csv')->once()->andReturn(false);
    $upload = $disk->shouldReceive('put')->once();
    if ($throw) {
        $upload->andThrow(new RuntimeException('Storage stream failed'));
    } else {
        $upload->andReturn(false);
    }
    $disk->shouldReceive('delete')->with(Mockery::on(fn (string $path): bool => str_starts_with($path, 'archives/partial.csv.uploading-')))->once()->andReturn(true);
    Storage::shouldReceive('disk')->andReturn($disk);

    expect(fn () => Prunekeeper::archive(new TestPrunableModel, TestPrunableModel::query(), new CsvExporter, false))
        ->toThrow(RuntimeException::class, $throw ? 'Storage stream failed' : 'Failed to upload archive');
})->with([false, true]);

it('preserves the upload exception when partial object cleanup also fails', function () {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('exists')->andReturn(false);
    $disk->shouldReceive('put')->once()->andThrow(new RuntimeException('Original upload error'));
    $disk->shouldReceive('delete')->once()->andThrow(new RuntimeException('Cleanup error'));
    Storage::shouldReceive('disk')->andReturn($disk);

    expect(fn () => Prunekeeper::archive(new TestPrunableModel, TestPrunableModel::query(), new CsvExporter, false))
        ->toThrow(RuntimeException::class, 'Original upload error');
});

it('preserves another writers archive when the current upload fails', function () {
    Prunekeeper::generateFilenameUsing(fn () => 'archives/shared.csv');
    $objects = [];
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('exists')->with('archives/shared.csv')->once()->andReturn(false);
    $disk->shouldReceive('put')->once()->andReturnUsing(function (string $path) use (&$objects) {
        $objects['archives/shared.csv'] = 'another writers complete archive';
        $objects[$path] = 'partial upload';

        return false;
    });
    $disk->shouldReceive('delete')->once()->andReturnUsing(function (string $path) use (&$objects) {
        unset($objects[$path]);

        return true;
    });
    Storage::shouldReceive('disk')->andReturn($disk);

    expect(fn () => Prunekeeper::archive(new TestPrunableModel, TestPrunableModel::query(), new CsvExporter, false))
        ->toThrow(RuntimeException::class, 'Failed to upload archive');
    expect($objects)->toBe(['archives/shared.csv' => 'another writers complete archive']);
});

it('preserves another writers archive created while uploading', function () {
    Prunekeeper::generateFilenameUsing(fn () => 'archives/shared.csv');
    $objects = [];
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('exists')->with('archives/shared.csv')->twice()->andReturnUsing(function () use (&$objects) {
        return isset($objects['archives/shared.csv']);
    });
    $disk->shouldReceive('put')->once()->andReturnUsing(function (string $path) use (&$objects) {
        $objects['archives/shared.csv'] = 'another writers complete archive';
        $objects[$path] = 'current upload';

        return true;
    });
    $disk->shouldReceive('move')->never();
    $disk->shouldReceive('delete')->once()->andReturnUsing(function (string $path) use (&$objects) {
        unset($objects[$path]);

        return true;
    });
    Storage::shouldReceive('disk')->andReturn($disk);

    expect(fn () => Prunekeeper::archive(new TestPrunableModel, TestPrunableModel::query(), new CsvExporter, false))
        ->toThrow(RuntimeException::class, 'Archive already exists');
    expect($objects)->toBe(['archives/shared.csv' => 'another writers complete archive']);
});
