<?php

declare(strict_types=1);

use HelgeSverre\Prunekeeper\Support\FileCompressor;

it('compresses a file to zip format', function () {
    $compressor = new FileCompressor;

    // Create a temporary file with some content
    $tempFile = tempnam(sys_get_temp_dir(), 'test_');
    file_put_contents($tempFile, 'Hello, World! This is test content for compression.');

    $zipPath = $compressor->compress($tempFile, 'csv');

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
    $compressor = new FileCompressor;

    // Create a temporary file with repetitive content (highly compressible)
    $tempFile = tempnam(sys_get_temp_dir(), 'test_');
    file_put_contents($tempFile, str_repeat('Hello, World! ', 1000));

    $originalSize = filesize($tempFile);
    $zipPath = $compressor->compress($tempFile, 'csv');
    $compressedSize = filesize($zipPath);

    // Compressed file should be smaller for repetitive content
    expect($compressedSize)->toBeLessThan($originalSize);

    // Cleanup
    @unlink($tempFile);
    @unlink($zipPath);
});
