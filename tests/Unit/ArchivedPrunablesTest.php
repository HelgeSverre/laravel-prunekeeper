<?php

declare(strict_types=1);

use HelgeSverre\Prunekeeper\Prunekeeper;
use HelgeSverre\Prunekeeper\Tests\Fixtures\TestPrunableModel;

it('generates default filename with timestamp and table name', function () {
    $manager = new Prunekeeper;
    $model = new TestPrunableModel;

    config(['prunekeeper.compress' => false]);

    $filename = $manager->generateFilename($model, 'csv');

    expect($filename)
        ->toContain('prunable-exports/')
        ->toContain('test_prunable_models')
        ->toEndWith('.csv');
});

it('uses custom filename generator when set', function () {
    $manager = new Prunekeeper;
    $model = new TestPrunableModel;

    $manager->generateFilenameUsing(function ($model, $format) {
        return "custom/{$model->getTable()}.{$format}";
    });

    $filename = $manager->generateFilename($model, 'csv');

    expect($filename)->toBe('custom/test_prunable_models.csv');
});

it('adds zip extension when compression is enabled', function () {
    $manager = new Prunekeeper;
    $model = new TestPrunableModel;

    config(['prunekeeper.compress' => true]);

    $filename = $manager->generateFilename($model, 'csv');

    expect($filename)->toEndWith('.csv.zip');
});

it('resolves columns from model method', function () {
    $manager = new Prunekeeper;

    $model = new class extends TestPrunableModel
    {
        public function getArchivableColumns(): ?array
        {
            return ['id', 'name', 'email'];
        }
    };

    $columns = $manager->resolveColumns($model);

    expect($columns)->toBe(['id', 'name', 'email']);
});

it('uses custom columns resolver when set', function () {
    $manager = new Prunekeeper;
    $model = new TestPrunableModel;

    $manager->resolveColumnsUsing(function ($model) {
        return ['custom_column'];
    });

    $columns = $manager->resolveColumns($model);

    expect($columns)->toBe(['custom_column']);
});

it('returns null columns when no resolver is set', function () {
    $manager = new Prunekeeper;
    $model = new TestPrunableModel;

    $columns = $manager->resolveColumns($model);

    expect($columns)->toBeNull();
});

it('respects enabled config', function () {
    $manager = new Prunekeeper;

    config(['prunekeeper.enabled' => true]);
    expect($manager->isEnabled())->toBeTrue();

    config(['prunekeeper.enabled' => false]);
    expect($manager->isEnabled())->toBeFalse();
});

it('respects fail_silently config', function () {
    $manager = new Prunekeeper;

    config(['prunekeeper.fail_silently' => false]);
    expect($manager->shouldFailSilently())->toBeFalse();

    config(['prunekeeper.fail_silently' => true]);
    expect($manager->shouldFailSilently())->toBeTrue();
});

it('returns configured format', function () {
    $manager = new Prunekeeper;

    config(['prunekeeper.format' => 'csv']);
    expect($manager->getFormat())->toBe('csv');

    config(['prunekeeper.format' => 'sql']);
    expect($manager->getFormat())->toBe('sql');
});

it('returns configured chunk size', function () {
    $manager = new Prunekeeper;

    config(['prunekeeper.chunk_size' => 500]);
    expect($manager->getChunkSize())->toBe(500);

    config(['prunekeeper.chunk_size' => 2000]);
    expect($manager->getChunkSize())->toBe(2000);
});
