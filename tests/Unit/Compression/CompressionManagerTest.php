<?php

declare(strict_types=1);

use HelgeSverre\Prunekeeper\Compression\Bzip2;
use HelgeSverre\Prunekeeper\Compression\CompressionManager;
use HelgeSverre\Prunekeeper\Compression\Gzip;
use HelgeSverre\Prunekeeper\Compression\TarGzip;
use HelgeSverre\Prunekeeper\Compression\Zip;
use HelgeSverre\Prunekeeper\Contracts\CompressionDriver;

it('returns default driver from config', function () {
    config(['prunekeeper.compression.driver' => 'zip']);

    $manager = app(CompressionManager::class);

    expect($manager->getDefaultDriver())->toBe('zip');
});

it('creates zip driver by default', function () {
    config(['prunekeeper.compression.driver' => 'zip']);

    $manager = app(CompressionManager::class);
    $driver = $manager->driver();

    expect($driver)->toBeInstanceOf(Zip::class);
    expect($driver)->toBeInstanceOf(CompressionDriver::class);
});

it('creates gzip driver when configured', function () {
    if (! Gzip::isAvailable()) {
        $this->markTestSkipped('ext-zlib not available');
    }

    config(['prunekeeper.compression.driver' => 'gzip']);

    $manager = app(CompressionManager::class);
    $driver = $manager->driver();

    expect($driver)->toBeInstanceOf(Gzip::class);
});

it('resolves driver aliases', function () {
    if (! TarGzip::isAvailable()) {
        $this->markTestSkipped('ext-phar or ext-zlib not available');
    }

    $manager = app(CompressionManager::class);

    expect($manager->driver('tgz'))->toBeInstanceOf(TarGzip::class);
    expect($manager->driver('tar-gz'))->toBeInstanceOf(TarGzip::class);
    expect($manager->driver('targz'))->toBeInstanceOf(TarGzip::class);
});

it('resolves bz2 alias to bzip2', function () {
    if (! Bzip2::isAvailable()) {
        $this->markTestSkipped('ext-bz2 not available');
    }

    $manager = app(CompressionManager::class);

    expect($manager->driver('bz2'))->toBeInstanceOf(Bzip2::class);
    expect($manager->driver('bzip2'))->toBeInstanceOf(Bzip2::class);
});

it('allows extending with custom drivers', function () {
    $manager = app(CompressionManager::class);

    $manager->extend('custom', function ($app) {
        return new Zip;
    });

    $driver = $manager->driver('custom');

    expect($driver)->toBeInstanceOf(Zip::class);
});

it('returns available drivers', function () {
    $manager = app(CompressionManager::class);

    $available = $manager->getAvailableDrivers();

    expect($available)->toHaveKeys(['zip', 'gzip', 'targz', 'bzip2']);

    foreach ($available as $driver) {
        expect($driver)->toHaveKeys(['name', 'available', 'requirements']);
    }
});

it('throws exception for unsupported driver', function () {
    $manager = app(CompressionManager::class);

    $manager->driver('nonexistent');
})->throws(InvalidArgumentException::class);

it('applies driver config from config file', function () {
    config([
        'prunekeeper.compression.driver' => 'gzip',
        'prunekeeper.compression.drivers.gzip' => ['buffer_size' => 1024],
    ]);

    if (! Gzip::isAvailable()) {
        $this->markTestSkipped('ext-zlib not available');
    }

    $manager = app(CompressionManager::class);
    $driver = $manager->driver();

    expect($driver)->toBeInstanceOf(Gzip::class);
});
