<?php

declare(strict_types=1);

use HelgeSverre\Prunekeeper\Compression\TarGzip;
use HelgeSverre\Prunekeeper\Exceptions\CompressionException;

beforeEach(function () {
    if (! TarGzip::isAvailable()) {
        $this->markTestSkipped('ext-phar or ext-zlib not available, or phar.readonly is enabled');
    }
});

it('compresses a file to tar.gz format', function () {
    $compressor = new TarGzip;

    $tempFile = tempnam(sys_get_temp_dir(), 'test_');
    file_put_contents($tempFile, 'Hello, World! This is test content for compression.');

    $tarGzPath = $compressor->compress($tempFile);

    expect($tarGzPath)->toEndWith('.tar.gz');
    expect(file_exists($tarGzPath))->toBeTrue();

    // Verify intermediate .tar was removed
    $tarPath = $tempFile.'.tar';
    expect(file_exists($tarPath))->toBeFalse();

    // Verify it's a valid tar.gz file by opening it
    $phar = new PharData($tarGzPath);
    expect($phar->count())->toBe(1);

    // Cleanup
    @unlink($tempFile);
    @unlink($tarGzPath);
});

it('creates a tar.gz file smaller than original for compressible content', function () {
    $compressor = new TarGzip;

    $tempFile = tempnam(sys_get_temp_dir(), 'test_');
    file_put_contents($tempFile, str_repeat('Hello, World! ', 1000));

    $originalSize = filesize($tempFile);
    $tarGzPath = $compressor->compress($tempFile);
    $compressedSize = filesize($tarGzPath);

    expect($compressedSize)->toBeLessThan($originalSize);

    // Cleanup
    @unlink($tempFile);
    @unlink($tarGzPath);
});

it('fails when source file does not exist', function () {
    $compressor = new TarGzip;
    $nonExistentFile = '/tmp/definitely_does_not_exist_'.uniqid().'.csv';

    $compressor->compress($nonExistentFile);
})->throws(CompressionException::class);

it('returns correct extension', function () {
    $compressor = new TarGzip;

    expect($compressor->extension())->toBe('tar.gz');
    expect($compressor->name())->toBe('targz');
});

it('reports availability correctly', function () {
    $extensionsAvailable = extension_loaded('phar') && extension_loaded('zlib') && class_exists(PharData::class);

    if ($extensionsAvailable) {
        $readonly = ini_get('phar.readonly');
        $pharWritable = $readonly === '' || $readonly === '0' || $readonly === false;
        expect(TarGzip::isAvailable())->toBe($pharWritable);
    } else {
        expect(TarGzip::isAvailable())->toBeFalse();
    }

    expect(TarGzip::requirements())->toBe(['ext-phar', 'ext-zlib', 'phar.readonly=0']);
});

it('respects inner filename', function () {
    $compressor = new TarGzip;

    $tempFile = tempnam(sys_get_temp_dir(), 'test_');
    file_put_contents($tempFile, 'test content');

    $tarGzPath = $compressor->compress($tempFile, 'export.csv');

    // Verify the inner file has the correct name
    $phar = new PharData($tarGzPath);
    $files = [];
    foreach ($phar as $file) {
        $files[] = $file->getFilename();
    }

    expect($files)->toContain('export.csv');

    // Cleanup
    @unlink($tempFile);
    @unlink($tarGzPath);
});

it('uses basename when inner filename is null', function () {
    $compressor = new TarGzip;

    $tempFile = tempnam(sys_get_temp_dir(), 'test_');
    file_put_contents($tempFile, 'test content');

    $tarGzPath = $compressor->compress($tempFile);

    // Verify the inner file uses the original basename
    $phar = new PharData($tarGzPath);
    $files = [];
    foreach ($phar as $file) {
        $files[] = $file->getFilename();
    }

    expect($files)->toContain(basename($tempFile));

    // Cleanup
    @unlink($tempFile);
    @unlink($tarGzPath);
});

it('cleans up intermediate tar file on success', function () {
    $compressor = new TarGzip;

    $tempFile = tempnam(sys_get_temp_dir(), 'test_');
    file_put_contents($tempFile, 'test content');

    $tarGzPath = $compressor->compress($tempFile);

    // Intermediate .tar should not exist
    $tarPath = $tempFile.'.tar';
    expect(file_exists($tarPath))->toBeFalse();
    expect(file_exists($tarGzPath))->toBeTrue();

    // Cleanup
    @unlink($tempFile);
    @unlink($tarGzPath);
});

it('can extract and read content from archive', function () {
    $compressor = new TarGzip;
    $originalContent = 'This is the original content to verify extraction works.';

    $tempFile = tempnam(sys_get_temp_dir(), 'test_');
    file_put_contents($tempFile, $originalContent);

    $tarGzPath = $compressor->compress($tempFile, 'data.txt');

    // PharData cannot read content directly from .tar.gz files - we need to decompress first
    // This is a known limitation of PharData: it can iterate files but getContent() returns empty
    $gzData = file_get_contents($tarGzPath);
    $decompressed = gzdecode($gzData);
    $extractedTarPath = $tempFile.'.extracted.tar';
    file_put_contents($extractedTarPath, $decompressed);

    $phar = new PharData($extractedTarPath);
    $extractedContent = $phar['data.txt']->getContent();

    expect($extractedContent)->toBe($originalContent);

    // Cleanup
    @unlink($tempFile);
    @unlink($tarGzPath);
    @unlink($extractedTarPath);
});
