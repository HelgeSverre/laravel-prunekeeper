<?php

declare(strict_types=1);

use HelgeSverre\Prunekeeper\Listeners\ArchiveBeforePruning;
use HelgeSverre\Prunekeeper\Prunekeeper;
use HelgeSverre\Prunekeeper\Tests\Fixtures\TestMassPrunableModel;
use HelgeSverre\Prunekeeper\Tests\Fixtures\TestPrunableModel;
use Illuminate\Database\Events\ModelPruningStarting;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

it('archives prunable records before pruning', function () {
    // Create some old records that should be pruned
    TestPrunableModel::create([
        'name' => 'Old Record 1',
        'email' => 'old1@example.com',
        'created_at' => now()->subMonths(2),
    ]);

    TestPrunableModel::create([
        'name' => 'Old Record 2',
        'email' => 'old2@example.com',
        'created_at' => now()->subMonths(3),
    ]);

    // Create a recent record that should NOT be pruned
    TestPrunableModel::create([
        'name' => 'New Record',
        'email' => 'new@example.com',
        'created_at' => now(),
    ]);

    $listener = app(ArchiveBeforePruning::class);
    $event = new ModelPruningStarting([TestPrunableModel::class]);

    $listener->handle($event);

    // Check that a file was created in storage
    $files = Storage::disk('local')->files('prunable-exports');
    expect($files)->toHaveCount(1);

    // Check that the file contains the old records
    $content = Storage::disk('local')->get($files[0]);
    expect($content)
        ->toContain('Old Record 1')
        ->toContain('Old Record 2')
        ->not->toContain('New Record');
});

it('skips archiving when disabled', function () {
    config(['prunekeeper.enabled' => false]);

    TestPrunableModel::create([
        'name' => 'Old Record',
        'created_at' => now()->subMonths(2),
    ]);

    $listener = app(ArchiveBeforePruning::class);
    $event = new ModelPruningStarting([TestPrunableModel::class]);

    $listener->handle($event);

    $files = Storage::disk('local')->files('prunable-exports');
    expect($files)->toBeEmpty();
});

it('skips models without the ArchivePrunedRecords trait', function () {
    // Create a model class without the trait for this test
    $modelWithoutTrait = new class extends \Illuminate\Database\Eloquent\Model
    {
        use \Illuminate\Database\Eloquent\Prunable;

        protected $table = 'test_prunable_models';

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
    $event = new ModelPruningStarting([get_class($modelWithoutTrait)]);

    $listener->handle($event);

    $files = Storage::disk('local')->files('prunable-exports');
    expect($files)->toBeEmpty();
});

it('skips archiving when no prunable records exist', function () {
    // Create only recent records
    TestPrunableModel::create([
        'name' => 'New Record',
        'created_at' => now(),
    ]);

    $listener = app(ArchiveBeforePruning::class);
    $event = new ModelPruningStarting([TestPrunableModel::class]);

    $listener->handle($event);

    $files = Storage::disk('local')->files('prunable-exports');
    expect($files)->toBeEmpty();
});

it('uses custom filename generator', function () {
    $manager = app(Prunekeeper::class);
    $manager->generateFilenameUsing(function ($model, $format) {
        return "custom-path/custom-name.{$format}";
    });

    TestPrunableModel::create([
        'name' => 'Old Record',
        'created_at' => now()->subMonths(2),
    ]);

    $listener = app(ArchiveBeforePruning::class);
    $event = new ModelPruningStarting([TestPrunableModel::class]);

    $listener->handle($event);

    expect(Storage::disk('local')->exists('custom-path/custom-name.csv'))->toBeTrue();
});

it('fires before and after callbacks', function () {
    $beforeCalled = false;
    $afterCalled = false;
    $afterResult = null;

    $manager = app(Prunekeeper::class);

    $manager->beforeArchiving(function ($model) use (&$beforeCalled) {
        $beforeCalled = true;
        expect($model)->toBeInstanceOf(TestPrunableModel::class);
    });

    $manager->afterArchiving(function ($model, $result) use (&$afterCalled, &$afterResult) {
        $afterCalled = true;
        $afterResult = $result;
    });

    TestPrunableModel::create([
        'name' => 'Old Record',
        'created_at' => now()->subMonths(2),
    ]);

    $listener = app(ArchiveBeforePruning::class);
    $event = new ModelPruningStarting([TestPrunableModel::class]);

    $listener->handle($event);

    expect($beforeCalled)->toBeTrue();
    expect($afterCalled)->toBeTrue();
    expect($afterResult->recordCount)->toBe(1);
});

it('handles JSON columns correctly in CSV export', function () {
    TestPrunableModel::create([
        'name' => 'Old Record',
        'metadata' => ['key' => 'value', 'nested' => ['a' => 1]],
        'created_at' => now()->subMonths(2),
    ]);

    $listener = app(ArchiveBeforePruning::class);
    $event = new ModelPruningStarting([TestPrunableModel::class]);

    $listener->handle($event);

    $files = Storage::disk('local')->files('prunable-exports');
    $content = Storage::disk('local')->get($files[0]);

    // JSON should be encoded as a string in CSV (quotes are escaped as "" per RFC 4180)
    expect($content)->toContain('key');
    expect($content)->toContain('value');
    expect($content)->toContain('nested');
});

it('skips models with ArchivePrunedRecords but without prunable method', function () {
    // Create a model class with the trait but without Prunable
    $modelWithTraitButNoPrunable = new class extends \Illuminate\Database\Eloquent\Model
    {
        use \HelgeSverre\Prunekeeper\ArchivePrunedRecords;

        protected $table = 'test_prunable_models';
    };

    TestPrunableModel::create([
        'name' => 'Old Record',
        'created_at' => now()->subMonths(2),
    ]);

    $listener = app(ArchiveBeforePruning::class);
    $event = new ModelPruningStarting([get_class($modelWithTraitButNoPrunable)]);

    // Should not throw, just skip gracefully
    $listener->handle($event);

    $files = Storage::disk('local')->files('prunable-exports');
    expect($files)->toBeEmpty();
});

it('archives MassPrunable records before pruning', function () {
    // Create some old records that should be pruned
    TestMassPrunableModel::create([
        'name' => 'Old Mass Record 1',
        'email' => 'old1@example.com',
        'created_at' => now()->subMonths(2),
    ]);

    TestMassPrunableModel::create([
        'name' => 'Old Mass Record 2',
        'email' => 'old2@example.com',
        'created_at' => now()->subMonths(3),
    ]);

    // Create a recent record that should NOT be pruned
    TestMassPrunableModel::create([
        'name' => 'New Mass Record',
        'email' => 'new@example.com',
        'created_at' => now(),
    ]);

    $listener = app(ArchiveBeforePruning::class);
    $event = new ModelPruningStarting([TestMassPrunableModel::class]);

    $listener->handle($event);

    // Check that a file was created in storage
    $files = Storage::disk('local')->files('prunable-exports');
    expect($files)->toHaveCount(1);

    // Check that the file contains the old records
    $content = Storage::disk('local')->get($files[0]);
    expect($content)
        ->toContain('Old Mass Record 1')
        ->toContain('Old Mass Record 2')
        ->not->toContain('New Mass Record');
});
