<?php

declare(strict_types=1);

use Domain\Records\CustomPrunableModel;
use HelgeSverre\Prunekeeper\ArchivableModels;
use HelgeSverre\Prunekeeper\ArchivePrunedRecords;
use HelgeSverre\Prunekeeper\Tests\Fixtures\TestPrunableModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Collection;

abstract class AbstractArchivableModel extends Model
{
    use ArchivePrunedRecords;
    use Prunable;

    protected $table = 'test_prunable_models';

    public function prunable()
    {
        return static::query();
    }
}

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

it('calls warning callback for a class that is not an Eloquent model', function () {
    $warningCalled = false;
    $warningMessage = '';

    // stdClass exists but cannot be archived.
    ArchivableModels::filter(
        ['stdClass'],
        null,
        function ($model, $message) use (&$warningCalled, &$warningMessage) {
            $warningCalled = true;
            $warningMessage = $message;
        }
    );

    expect($warningCalled)->toBeTrue();
    expect($warningMessage)->toContain('must extend Eloquent Model');
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
    $models = ArchivableModels::filter([
        TestPrunableModel::class,
        TestPrunableModel::class,
        TestPrunableModel::class,
    ]);

    expect($models)->toHaveCount(1);
});

it('filters out abstract models', function () {
    $warning = '';

    $models = ArchivableModels::filter(
        [AbstractArchivableModel::class],
        null,
        function (string $model, string $message) use (&$warning): void {
            $warning = $message;
        }
    );

    expect($models)->toBeEmpty();
    expect($warning)->toContain('must be instantiable');
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

it('discovers models from their declared namespace', function () {
    $directory = app_path('CustomModels');
    $file = $directory.'/CustomPrunableModel.php';

    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    file_put_contents($file, <<<'PHP'
<?php

namespace Domain\Records;

use HelgeSverre\Prunekeeper\ArchivePrunedRecords;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

class CustomPrunableModel extends Model
{
    use ArchivePrunedRecords;
    use Prunable;

    protected $table = 'test_prunable_models';

    public function prunable()
    {
        return static::query();
    }
}
PHP);

    try {
        require_once $file;
        config(['prunekeeper.models_path' => ['app/CustomModels']]);

        expect(ArchivableModels::get())->toContain(CustomPrunableModel::class);
    } finally {
        unlink($file);
        rmdir($directory);
    }
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
