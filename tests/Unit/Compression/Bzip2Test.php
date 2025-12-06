<?php

declare(strict_types=1);

use HelgeSverre\Prunekeeper\Compression\Bzip2;
use HelgeSverre\Prunekeeper\Exceptions\CompressionException;

beforeEach(function () {
    if (! Bzip2::isAvailable()) {
        $this->markTestSkipped('ext-bz2 not available');
    }
});

it('compresses a file to bzip2 format', function () {
    $compressor = new Bzip2;

    $tempFile = tempnam(sys_get_temp_dir(), 'test_');
    file_put_contents($tempFile, 'Hello, World! This is test content for compression.');

    $bz2Path = $compressor->compress($tempFile);

    expect($bz2Path)->toEndWith('.bz2');
    expect(file_exists($bz2Path))->toBeTrue();

    // Verify it can be decompressed
    $bzHandle = bzopen($bz2Path, 'r');
    $content = '';
    while (! feof($bzHandle)) {
        $content .= bzread($bzHandle, 4096);
    }
    bzclose($bzHandle);

    expect($content)->toBe('Hello, World! This is test content for compression.');

    // Cleanup
    @unlink($tempFile);
    @unlink($bz2Path);
});

it('creates a bzip2 file smaller than original for compressible content', function () {
    $compressor = new Bzip2;

    $tempFile = tempnam(sys_get_temp_dir(), 'test_');
    file_put_contents($tempFile, str_repeat('Hello, World! ', 1000));

    $originalSize = filesize($tempFile);
    $bz2Path = $compressor->compress($tempFile);
    $compressedSize = filesize($bz2Path);

    expect($compressedSize)->toBeLessThan($originalSize);

    // Cleanup
    @unlink($tempFile);
    @unlink($bz2Path);
});

it('fails when source file does not exist', function () {
    $compressor = new Bzip2;
    $nonExistentFile = '/tmp/definitely_does_not_exist_'.uniqid().'.csv';

    $compressor->compress($nonExistentFile);
})->throws(CompressionException::class);

it('returns correct extension', function () {
    $compressor = new Bzip2;

    expect($compressor->extension())->toBe('bz2');
    expect($compressor->name())->toBe('bzip2');
});

it('reports availability correctly', function () {
    expect(Bzip2::isAvailable())->toBe(extension_loaded('bz2') && function_exists('bzopen'));
    expect(Bzip2::requirements())->toBe(['ext-bz2']);
});

it('accepts custom buffer size', function () {
    $compressor = new Bzip2(['buffer_size' => 1024]);

    $tempFile = tempnam(sys_get_temp_dir(), 'test_');
    file_put_contents($tempFile, str_repeat('test', 1000));

    $bz2Path = $compressor->compress($tempFile);

    expect(file_exists($bz2Path))->toBeTrue();

    // Cleanup
    @unlink($tempFile);
    @unlink($bz2Path);
});

it('handles invalid buffer size gracefully', function () {
    $compressor = new Bzip2(['buffer_size' => 0]);

    $tempFile = tempnam(sys_get_temp_dir(), 'test_');
    file_put_contents($tempFile, 'test content');

    $bz2Path = $compressor->compress($tempFile);

    expect(file_exists($bz2Path))->toBeTrue();

    // Cleanup
    @unlink($tempFile);
    @unlink($bz2Path);
});

it('handles negative buffer size gracefully', function () {
    $compressor = new Bzip2(['buffer_size' => -100]);

    $tempFile = tempnam(sys_get_temp_dir(), 'test_');
    file_put_contents($tempFile, 'test content');

    $bz2Path = $compressor->compress($tempFile);

    expect(file_exists($bz2Path))->toBeTrue();

    // Cleanup
    @unlink($tempFile);
    @unlink($bz2Path);
});

it('ignores inner filename parameter', function () {
    $compressor = new Bzip2;

    $tempFile = tempnam(sys_get_temp_dir(), 'test_');
    file_put_contents($tempFile, 'test content');

    // Inner filename is passed but should be ignored for bzip2
    $bz2Path = $compressor->compress($tempFile, 'custom_name.csv');

    // Output should still be based on original filename
    expect($bz2Path)->toBe($tempFile.'.bz2');
    expect(file_exists($bz2Path))->toBeTrue();

    // Cleanup
    @unlink($tempFile);
    @unlink($bz2Path);
});
