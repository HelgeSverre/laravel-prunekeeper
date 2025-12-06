<?php

declare(strict_types=1);

use HelgeSverre\Prunekeeper\Compression\Gzip;
use HelgeSverre\Prunekeeper\Exceptions\CompressionException;

beforeEach(function () {
    if (! Gzip::isAvailable()) {
        $this->markTestSkipped('ext-zlib not available');
    }
});

it('compresses a file to gzip format', function () {
    $compressor = new Gzip;

    $tempFile = tempnam(sys_get_temp_dir(), 'test_');
    file_put_contents($tempFile, 'Hello, World! This is test content for compression.');

    $gzPath = $compressor->compress($tempFile);

    expect($gzPath)->toEndWith('.gz');
    expect(file_exists($gzPath))->toBeTrue();

    // Verify it can be decompressed
    $content = gzfile($gzPath);
    expect($content)->not->toBeEmpty();

    // Cleanup
    @unlink($tempFile);
    @unlink($gzPath);
});

it('creates a gzip file smaller than original for compressible content', function () {
    $compressor = new Gzip;

    $tempFile = tempnam(sys_get_temp_dir(), 'test_');
    file_put_contents($tempFile, str_repeat('Hello, World! ', 1000));

    $originalSize = filesize($tempFile);
    $gzPath = $compressor->compress($tempFile);
    $compressedSize = filesize($gzPath);

    expect($compressedSize)->toBeLessThan($originalSize);

    // Cleanup
    @unlink($tempFile);
    @unlink($gzPath);
});

it('fails when source file does not exist', function () {
    $compressor = new Gzip;
    $nonExistentFile = '/tmp/definitely_does_not_exist_'.uniqid().'.csv';

    $compressor->compress($nonExistentFile);
})->throws(CompressionException::class);

it('returns correct extension', function () {
    $compressor = new Gzip;

    expect($compressor->extension())->toBe('gz');
    expect($compressor->name())->toBe('gzip');
});

it('reports availability correctly', function () {
    expect(Gzip::isAvailable())->toBe(extension_loaded('zlib') && function_exists('gzopen'));
    expect(Gzip::requirements())->toBe(['ext-zlib']);
});

it('accepts custom buffer size', function () {
    $compressor = new Gzip(['buffer_size' => 1024]);

    $tempFile = tempnam(sys_get_temp_dir(), 'test_');
    file_put_contents($tempFile, str_repeat('test', 1000));

    $gzPath = $compressor->compress($tempFile);

    expect(file_exists($gzPath))->toBeTrue();

    // Cleanup
    @unlink($tempFile);
    @unlink($gzPath);
});
