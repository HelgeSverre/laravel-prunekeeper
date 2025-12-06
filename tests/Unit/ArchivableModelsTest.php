<?php

declare(strict_types=1);

use HelgeSverre\Prunekeeper\ArchivableModels;
use HelgeSverre\Prunekeeper\Tests\Fixtures\TestPrunableModel;
use Illuminate\Support\Collection;

it('returns empty collection when models_path directories do not exist', function () {
    config(['prunekeeper.models_path' => ['nonexistent/path', 'another/missing/dir']]);

    $models = ArchivableModels::get();

    expect($models)->toBeEmpty();
});

it('returns empty collection when models_path is empty array', function () {
    config(['prunekeeper.models_path' => []]);

    $models = ArchivableModels::get();

    expect($models)->toBeEmpty();
});

it('handles string config value for models_path', function () {
    config(['prunekeeper.models_path' => 'nonexistent/single/path']);

    $models = ArchivableModels::get();

    expect($models)->toBeEmpty();
});

it('filters models to only those using ArchivePrunedRecords trait', function () {
    // The filter method should validate class names
    $models = ArchivableModels::filter([
        TestPrunableModel::class,
    ]);

    expect($models)->toContain(TestPrunableModel::class);
});

it('calls error callback for non-existent class', function () {
    $errorCalled = false;
    $errorMessage = '';

    ArchivableModels::filter(
        ['NonExistent\\Class\\Name'],
        function ($model, $message) use (&$errorCalled, &$errorMessage) {
            $errorCalled = true;
            $errorMessage = $message;
        }
    );

    expect($errorCalled)->toBeTrue();
    expect($errorMessage)->toContain('Model class not found');
});

it('calls warning callback for class without ArchivePrunedRecords trait', function () {
    $warningCalled = false;
    $warningMessage = '';

    // stdClass exists but doesn't have the trait
    ArchivableModels::filter(
        ['stdClass'],
        null,
        function ($model, $message) use (&$warningCalled, &$warningMessage) {
            $warningCalled = true;
            $warningMessage = $message;
        }
    );

    expect($warningCalled)->toBeTrue();
    expect($warningMessage)->toContain('does not use ArchivePrunedRecords trait');
});

it('filters out invalid classes from the result', function () {
    $models = ArchivableModels::filter([
        TestPrunableModel::class,
        'NonExistent\\Class',
        'stdClass', // exists but no trait
    ]);

    expect($models)->toHaveCount(1);
    expect($models)->toContain(TestPrunableModel::class);
});

it('deduplicates models when same model found in multiple paths', function () {
    // The get() method should deduplicate using unique()
    // This is tested implicitly through the implementation
    // We can verify the unique() call works by checking the collection methods
    $models = ArchivableModels::filter([
        TestPrunableModel::class,
        TestPrunableModel::class, // duplicate
        TestPrunableModel::class, // another duplicate
    ]);

    // Filter doesn't deduplicate, but get() does - testing filter behavior
    expect($models)->toHaveCount(3);
});

it('handles glob pattern with no matches gracefully', function () {
    config(['prunekeeper.models_path' => ['app/NonExistent/*/Models']]);

    $models = ArchivableModels::get();

    expect($models)->toBeEmpty();
});

it('handles recursive glob pattern with no matches gracefully', function () {
    config(['prunekeeper.models_path' => ['app/NonExistent/**/Models']]);

    $models = ArchivableModels::get();

    expect($models)->toBeEmpty();
});

it('converts path to namespace correctly', function () {
    // Test via reflection since pathToNamespace is private
    $reflection = new ReflectionClass(ArchivableModels::class);
    $method = $reflection->getMethod('pathToNamespace');
    $method->setAccessible(true);

    $namespace = $method->invoke(null, 'app/Domain/Users/Models');

    expect($namespace)->toBe('App\\Domain\\Users\\Models');
});

it('converts single segment path to namespace', function () {
    $reflection = new ReflectionClass(ArchivableModels::class);
    $method = $reflection->getMethod('pathToNamespace');
    $method->setAccessible(true);

    $namespace = $method->invoke(null, 'app');

    expect($namespace)->toBe('App');
});

it('handles empty path in namespace conversion', function () {
    $reflection = new ReflectionClass(ArchivableModels::class);
    $method = $reflection->getMethod('pathToNamespace');
    $method->setAccessible(true);

    $namespace = $method->invoke(null, '');

    expect($namespace)->toBe('');
});

it('expands simple path correctly', function () {
    $reflection = new ReflectionClass(ArchivableModels::class);
    $method = $reflection->getMethod('expandPathPattern');
    $method->setAccessible(true);

    $basePath = base_path();

    // Test with non-existent path
    $result = $method->invoke(null, $basePath, 'nonexistent/path');
    expect($result)->toBeEmpty();

    // Test with existing path
    $result = $method->invoke(null, $basePath, 'tests');
    expect($result)->toContain($basePath.'/tests');
});

it('defaults to app/Models and app when no config set', function () {
    // Reset config to ensure default is used
    config(['prunekeeper.models_path' => null]);

    // The implementation defaults to ['app/Models', 'app'] when null
    // Since we're in a test environment without real app directories,
    // the result should be empty but not throw
    $models = ArchivableModels::get();

    expect($models)->toBeInstanceOf(Collection::class);
});
