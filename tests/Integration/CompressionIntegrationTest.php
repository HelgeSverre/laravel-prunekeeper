<?php

declare(strict_types=1);

use HelgeSverre\Prunekeeper\ArchivePrunedRecords;
use HelgeSverre\Prunekeeper\Compression\Bzip2;
use HelgeSverre\Prunekeeper\Compression\CompressionManager;
use HelgeSverre\Prunekeeper\Compression\Gzip;
use HelgeSverre\Prunekeeper\Compression\TarGzip;
use HelgeSverre\Prunekeeper\Compression\Zip;
use HelgeSverre\Prunekeeper\Contracts\CompressionDriver;
use HelgeSverre\Prunekeeper\Exceptions\CompressionException;
use HelgeSverre\Prunekeeper\Listeners\ArchiveBeforePruning;
use HelgeSverre\Prunekeeper\Tests\Fixtures\TestPrunableModel;
use HelgeSverre\Prunekeeper\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Events\ModelPruningStarting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

uses(TestCase::class);

beforeEach(function () {
    Storage::fake('local');
    config(['prunekeeper.disk' => 'local']);
    config(['prunekeeper.compression.enabled' => true]);
});

describe('Gzip driver integration', function () {
    beforeEach(function () {
        if (! Gzip::isAvailable()) {
            $this->markTestSkipped('ext-zlib not available');
        }
        config(['prunekeeper.compression.driver' => 'gzip']);
    });

    it('archives with gzip compression', function () {
        TestPrunableModel::create([
            'name' => 'Old Record',
            'email' => 'old@example.com',
            'created_at' => now()->subMonths(2),
        ]);

        $listener = app(ArchiveBeforePruning::class);
        $event = new ModelPruningStarting([TestPrunableModel::class]);

        $listener->handle($event);

        $files = Storage::disk('local')->files('prunable-exports');
        expect($files)->toHaveCount(1);
        expect($files[0])->toEndWith('.csv.gz');

        // Verify content can be decompressed
        $compressedPath = Storage::disk('local')->path($files[0]);
        $content = implode('', gzfile($compressedPath));

        expect($content)->toContain('Old Record');
        expect($content)->toContain('old@example.com');
    });

    it('appends gz extension to custom filename', function () {
        $modelClass = new class extends Model
        {
            use ArchivePrunedRecords;
            use Prunable;

            protected $table = 'test_prunable_models';

            protected $guarded = [];

            public function getArchiveFilename(string $format): ?string
            {
                return "custom/my-archive.{$format}";
            }

            public function prunable()
            {
                return self::where('created_at', '<=', now()->subMonth());
            }
        };

        TestPrunableModel::create([
            'name' => 'Old Record',
            'created_at' => now()->subMonths(2),
        ]);

        $listener = app(ArchiveBeforePruning::class);
        $event = new ModelPruningStarting([get_class($modelClass)]);

        $listener->handle($event);

        expect(Storage::disk('local')->exists('custom/my-archive.csv.gz'))->toBeTrue();
    });
});

describe('Bzip2 driver integration', function () {
    beforeEach(function () {
        if (! Bzip2::isAvailable()) {
            $this->markTestSkipped('ext-bz2 not available');
        }
        config(['prunekeeper.compression.driver' => 'bzip2']);
    });

    it('archives with bzip2 compression', function () {
        TestPrunableModel::create([
            'name' => 'Old Record',
            'email' => 'old@example.com',
            'created_at' => now()->subMonths(2),
        ]);

        $listener = app(ArchiveBeforePruning::class);
        $event = new ModelPruningStarting([TestPrunableModel::class]);

        $listener->handle($event);

        $files = Storage::disk('local')->files('prunable-exports');
        expect($files)->toHaveCount(1);
        expect($files[0])->toEndWith('.csv.bz2');

        // Verify content can be decompressed
        $compressedPath = Storage::disk('local')->path($files[0]);
        $bzHandle = bzopen($compressedPath, 'r');
        $content = '';
        while (! feof($bzHandle)) {
            $content .= bzread($bzHandle, 4096);
        }
        bzclose($bzHandle);

        expect($content)->toContain('Old Record');
        expect($content)->toContain('old@example.com');
    });

    it('appends bz2 extension to custom filename', function () {
        $modelClass = new class extends Model
        {
            use ArchivePrunedRecords;
            use Prunable;

            protected $table = 'test_prunable_models';

            protected $guarded = [];

            public function getArchiveFilename(string $format): ?string
            {
                return "custom/my-archive.{$format}";
            }

            public function prunable()
            {
                return self::where('created_at', '<=', now()->subMonth());
            }
        };

        TestPrunableModel::create([
            'name' => 'Old Record',
            'created_at' => now()->subMonths(2),
        ]);

        $listener = app(ArchiveBeforePruning::class);
        $event = new ModelPruningStarting([get_class($modelClass)]);

        $listener->handle($event);

        expect(Storage::disk('local')->exists('custom/my-archive.csv.bz2'))->toBeTrue();
    });
});

describe('TarGzip driver integration', function () {
    beforeEach(function () {
        if (! TarGzip::isAvailable()) {
            $this->markTestSkipped('ext-phar or ext-zlib not available, or phar.readonly is enabled');
        }
        config(['prunekeeper.compression.driver' => 'targz']);
    });

    it('archives with tar.gz compression', function () {
        TestPrunableModel::create([
            'name' => 'Old Record',
            'email' => 'old@example.com',
            'created_at' => now()->subMonths(2),
        ]);

        $listener = app(ArchiveBeforePruning::class);
        $event = new ModelPruningStarting([TestPrunableModel::class]);

        $listener->handle($event);

        $files = Storage::disk('local')->files('prunable-exports');
        expect($files)->toHaveCount(1);
        expect($files[0])->toEndWith('.csv.tar.gz');

        // Verify content can be extracted
        $compressedPath = Storage::disk('local')->path($files[0]);
        $phar = new PharData($compressedPath);

        $content = null;
        foreach ($phar as $file) {
            $content = $file->getContent();
            break;
        }

        expect($content)->toContain('Old Record');
        expect($content)->toContain('old@example.com');
    });

    it('appends tar.gz extension to custom filename', function () {
        $modelClass = new class extends Model
        {
            use ArchivePrunedRecords;
            use Prunable;

            protected $table = 'test_prunable_models';

            protected $guarded = [];

            public function getArchiveFilename(string $format): ?string
            {
                return "custom/my-archive.{$format}";
            }

            public function prunable()
            {
                return self::where('created_at', '<=', now()->subMonth());
            }
        };

        TestPrunableModel::create([
            'name' => 'Old Record',
            'created_at' => now()->subMonths(2),
        ]);

        $listener = app(ArchiveBeforePruning::class);
        $event = new ModelPruningStarting([get_class($modelClass)]);

        $listener->handle($event);

        expect(Storage::disk('local')->exists('custom/my-archive.csv.tar.gz'))->toBeTrue();
    });
});

describe('Zip driver integration', function () {
    beforeEach(function () {
        if (! Zip::isAvailable()) {
            $this->markTestSkipped('ext-zip not available');
        }
        config(['prunekeeper.compression.driver' => 'zip']);
    });

    it('archives with zip compression', function () {
        TestPrunableModel::create([
            'name' => 'Old Record',
            'email' => 'old@example.com',
            'created_at' => now()->subMonths(2),
        ]);

        $listener = app(ArchiveBeforePruning::class);
        $event = new ModelPruningStarting([TestPrunableModel::class]);

        $listener->handle($event);

        $files = Storage::disk('local')->files('prunable-exports');
        expect($files)->toHaveCount(1);
        expect($files[0])->toEndWith('.csv.zip');

        // Verify content can be extracted
        $compressedPath = Storage::disk('local')->path($files[0]);
        $zip = new ZipArchive;
        $zip->open($compressedPath);

        $content = $zip->getFromIndex(0);
        $zip->close();

        expect($content)->toContain('Old Record');
        expect($content)->toContain('old@example.com');
    });

    it('does not double-append zip extension when already present', function () {
        $modelClass = new class extends Model
        {
            use ArchivePrunedRecords;
            use Prunable;

            protected $table = 'test_prunable_models';

            protected $guarded = [];

            public function getArchiveFilename(string $format): ?string
            {
                return "custom/my-archive.{$format}.zip";
            }

            public function prunable()
            {
                return self::where('created_at', '<=', now()->subMonth());
            }
        };

        TestPrunableModel::create([
            'name' => 'Old Record',
            'created_at' => now()->subMonths(2),
        ]);

        $listener = app(ArchiveBeforePruning::class);
        $event = new ModelPruningStarting([get_class($modelClass)]);

        $listener->handle($event);

        // Should NOT have double .zip extension
        expect(Storage::disk('local')->exists('custom/my-archive.csv.zip'))->toBeTrue();
        expect(Storage::disk('local')->exists('custom/my-archive.csv.zip.zip'))->toBeFalse();
    });
});

describe('Compression disabled', function () {
    it('does not compress when compression is disabled', function () {
        config(['prunekeeper.compression.enabled' => false]);

        TestPrunableModel::create([
            'name' => 'Old Record',
            'email' => 'old@example.com',
            'created_at' => now()->subMonths(2),
        ]);

        $listener = app(ArchiveBeforePruning::class);
        $event = new ModelPruningStarting([TestPrunableModel::class]);

        $listener->handle($event);

        $files = Storage::disk('local')->files('prunable-exports');
        expect($files)->toHaveCount(1);
        expect($files[0])->toEndWith('.csv');
        expect($files[0])->not->toContain('.zip');
        expect($files[0])->not->toContain('.gz');
        expect($files[0])->not->toContain('.bz2');
    });
});

describe('Error handling', function () {
    it('leaves no partial files when compression fails', function () {
        config(['prunekeeper.fail_silently' => true]);

        // Create a custom failing driver
        $failingDriver = new class implements CompressionDriver
        {
            public function compress(string $filePath, ?string $innerFilename = null): string
            {
                throw new CompressionException(
                    'Intentional failure for testing',
                    'failing'
                );
            }

            public function extension(): string
            {
                return 'fail';
            }

            public function name(): string
            {
                return 'failing';
            }

            public static function isAvailable(): bool
            {
                return true;
            }

            public static function requirements(): array
            {
                return [];
            }
        };

        // Register the failing driver
        $manager = app(CompressionManager::class);
        $manager->extend('failing', fn () => $failingDriver);
        config(['prunekeeper.compression.driver' => 'failing']);

        Log::shouldReceive('info')->once();
        Log::shouldReceive('error')->once();

        TestPrunableModel::create([
            'name' => 'Old Record',
            'created_at' => now()->subMonths(2),
        ]);

        $listener = app(ArchiveBeforePruning::class);
        $event = new ModelPruningStarting([TestPrunableModel::class]);

        $listener->handle($event);

        // No files should be stored
        $files = Storage::disk('local')->allFiles();
        expect($files)->toBeEmpty();
    });
});
