<?php

declare(strict_types=1);

namespace HelgeSverre\Prunekeeper\Compression;

use Closure;
use HelgeSverre\Prunekeeper\Contracts\CompressionDriver;
use HelgeSverre\Prunekeeper\Exceptions\CompressionException;
use Illuminate\Support\Manager;
use InvalidArgumentException;

/**
 * @method CompressionDriver driver(?string $driver = null)
 */
class CompressionManager extends Manager
{
    /**
     * Driver aliases for convenience.
     *
     * @var array<string, string>
     */
    protected array $aliases = [
        'tgz' => 'targz',
        'tar-gz' => 'targz',
        'tar.gz' => 'targz',
        'bz2' => 'bzip2',
    ];

    /**
     * Get the default driver name.
     */
    public function getDefaultDriver(): string
    {
        return $this->config->get('prunekeeper.compression.driver', 'zip');
    }

    /**
     * Create a driver instance.
     *
     * @throws InvalidArgumentException
     */
    protected function createDriver($driver): CompressionDriver
    {
        $driver = $this->resolveAlias($driver);

        if (isset($this->customCreators[$driver])) {
            return $this->callCustomCreator($driver);
        }

        $method = 'create'.ucfirst($driver).'Driver';

        if (method_exists($this, $method)) {
            return $this->$method();
        }

        throw new InvalidArgumentException("Compression driver [{$driver}] is not supported.");
    }

    /**
     * Resolve driver alias to canonical name.
     */
    protected function resolveAlias(string $driver): string
    {
        return $this->aliases[$driver] ?? $driver;
    }

    /**
     * Create the Zip compression driver.
     */
    protected function createZipDriver(): Zip
    {
        $this->ensureAvailable(Zip::class, 'zip');

        return new Zip;
    }

    /**
     * Create the Gzip compression driver.
     */
    protected function createGzipDriver(): Gzip
    {
        $this->ensureAvailable(Gzip::class, 'gzip');

        $config = $this->getDriverConfig('gzip');

        return new Gzip($config);
    }

    /**
     * Create the Tar+Gzip compression driver.
     */
    protected function createTargzDriver(): TarGzip
    {
        $this->ensureAvailable(TarGzip::class, 'targz');

        return new TarGzip;
    }

    /**
     * Create the Bzip2 compression driver.
     */
    protected function createBzip2Driver(): Bzip2
    {
        $this->ensureAvailable(Bzip2::class, 'bzip2');

        $config = $this->getDriverConfig('bzip2');

        return new Bzip2($config);
    }

    /**
     * Ensure the driver is available on this system.
     *
     * @param  class-string<CompressionDriver>  $class
     *
     * @throws CompressionException
     */
    protected function ensureAvailable(string $class, string $driver): void
    {
        if (! $class::isAvailable()) {
            throw CompressionException::driverNotAvailable($driver, $class::requirements());
        }
    }

    /**
     * Get driver-specific configuration.
     *
     * @return array<string, mixed>
     */
    protected function getDriverConfig(string $driver): array
    {
        return $this->config->get("prunekeeper.compression.drivers.{$driver}", []);
    }

    /**
     * Get all available compression drivers.
     *
     * @return array<string, array{name: string, available: bool, requirements: array<string>}>
     */
    public function getAvailableDrivers(): array
    {
        $drivers = [
            'zip' => Zip::class,
            'gzip' => Gzip::class,
            'targz' => TarGzip::class,
            'bzip2' => Bzip2::class,
        ];

        $result = [];

        foreach ($drivers as $name => $class) {
            $result[$name] = [
                'name' => $name,
                'available' => $class::isAvailable(),
                'requirements' => $class::requirements(),
            ];
        }

        return $result;
    }

    /**
     * Register a custom driver creator.
     *
     * @return $this
     */
    public function extend($driver, Closure $callback): static
    {
        $driver = $this->resolveAlias($driver);

        $this->customCreators[$driver] = $callback;

        return $this;
    }
}
