<?php

declare(strict_types=1);

use HelgeSverre\Prunekeeper\Compression\Zip;
use HelgeSverre\Prunekeeper\Exceptions\CompressionException;

it('compresses a file to zip format', function () {
    $compressor = new Zip;

    // Create a temporary file with some content
    $tempFile = tempnam(sys_get_temp_dir(), 'test_');
    file_put_contents($tempFile, 'Hello, World! This is test content for compression.');

    $zipPath = $compressor->compress($tempFile, 'export.csv');

    expect($zipPath)->toEndWith('.zip');
    expect(file_exists($zipPath))->toBeTrue();

    // Verify it's a valid zip file
    $zip = new ZipArchive;
    expect($zip->open($zipPath))->toBe(true);
    expect($zip->numFiles)->toBe(1);

    $zip->close();

    // Cleanup
    @unlink($tempFile);
    @unlink($zipPath);
});

it('creates a zip file smaller than or equal to original for compressible content', function () {
    $compressor = new Zip;

    // Create a temporary file with repetitive content (highly compressible)
    $tempFile = tempnam(sys_get_temp_dir(), 'test_');
    file_put_contents($tempFile, str_repeat('Hello, World! ', 1000));

    $originalSize = filesize($tempFile);
    $zipPath = $compressor->compress($tempFile, 'export.csv');
    $compressedSize = filesize($zipPath);

    // Compressed file should be smaller for repetitive content
    expect($compressedSize)->toBeLessThan($originalSize);

    // Cleanup
    @unlink($tempFile);
    @unlink($zipPath);
});

it('fails when source file does not exist', function () {
    $compressor = new Zip;
    $nonExistentFile = '/tmp/definitely_does_not_exist_'.uniqid().'.csv';

    $compressor->compress($nonExistentFile, 'export.csv');
})->throws(CompressionException::class);

it('reports an unavailable driver when used directly', function () {
    $compressor = new class extends Zip
    {
        public static function isAvailable(): bool
        {
            return false;
        }
    };
    $tempFile = tempnam(sys_get_temp_dir(), 'test_');
    file_put_contents($tempFile, 'test content');

    expect(fn () => $compressor->compress($tempFile, 'export.csv'))
        ->toThrow(CompressionException::class, "Compression driver 'zip' is not available");

    @unlink($tempFile);
});

it('fails when file is deleted before compression', function () {
    $compressor = new Zip;

    // Create a valid temp file
    $tempFile = tempnam(sys_get_temp_dir(), 'test_');
    file_put_contents($tempFile, 'test content');

    // Delete the temp file to simulate race condition
    unlink($tempFile);

    $compressor->compress($tempFile, 'export.csv');
})->throws(CompressionException::class);

it('uses correct inner filename', function () {
    $compressor = new Zip;

    $tempFile = tempnam(sys_get_temp_dir(), 'test_');
    file_put_contents($tempFile, 'test content');

    $zipPath = $compressor->compress($tempFile, 'export.sql');

    // Verify the inner file has the correct extension
    $zip = new ZipArchive;
    $zip->open($zipPath);

    $innerName = $zip->getNameIndex(0);
    expect($innerName)->toBe('export.sql');

    $zip->close();

    // Cleanup
    @unlink($tempFile);
    @unlink($zipPath);
});

it('preserves an existing zip archive', function () {
    $compressor = new Zip;

    $tempFile = tempnam(sys_get_temp_dir(), 'test_');
    file_put_contents($tempFile, 'new content');
    $zipPath = $tempFile.'.zip';
    file_put_contents($zipPath, 'existing archive');

    expect(fn () => $compressor->compress($tempFile, 'export.csv'))
        ->toThrow(CompressionException::class, 'Destination archive already exists');

    expect(file_get_contents($zipPath))->toBe('existing archive');

    // Cleanup
    @unlink($tempFile);
    @unlink($zipPath);
});

it('rejects a directory source', function () {
    $compressor = new Zip;

    expect(fn () => $compressor->compress(sys_get_temp_dir(), 'export.csv'))
        ->toThrow(CompressionException::class, 'not a regular file');
});

it('returns correct extension', function () {
    $compressor = new Zip;

    expect($compressor->extension())->toBe('zip');
    expect($compressor->name())->toBe('zip');
});

it('reports availability correctly', function () {
    expect(Zip::isAvailable())->toBe(extension_loaded('zip'));
    expect(Zip::requirements())->toBe(['ext-zip']);
});
