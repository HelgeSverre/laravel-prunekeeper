<?php

declare(strict_types=1);

use HelgeSverre\Prunekeeper\ArchivePrunedRecords;
use HelgeSverre\Prunekeeper\Tests\Fixtures\TestMassPrunableModel;
use HelgeSverre\Prunekeeper\Tests\Fixtures\TestPrunableModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

beforeEach(function () {
    // Clean up any existing model files in the test app/Models directory
    $modelsPath = app_path('Models');
    if (is_dir($modelsPath)) {
        array_map('unlink', glob("{$modelsPath}/*.php"));
    }
});

it('validates models with default columns successfully', function () {
    $this->artisan('prunekeeper:validate', ['--model' => [TestPrunableModel::class]])
        ->expectsOutputToContain('All models validated successfully')
        ->assertExitCode(0);
});

it('validates models with valid custom columns', function () {
    // Create a model class with valid custom columns
    $modelClass = new class extends Model
    {
        use ArchivePrunedRecords;
        use Prunable;

        protected $table = 'test_prunable_models';

        protected $guarded = [];

        public function getArchivableColumns(): ?array
        {
            return ['id', 'name', 'email'];
        }

        public function prunable()
        {
            return self::where('created_at', '<=', now()->subMonth());
        }
    };

    $this->artisan('prunekeeper:validate', ['--model' => [get_class($modelClass)]])
        ->expectsOutputToContain('All models validated successfully')
        ->assertExitCode(0);
});

it('fails validation for models with invalid columns', function () {
    // Create a model class with invalid custom columns
    $modelClass = new class extends Model
    {
        use ArchivePrunedRecords;
        use Prunable;

        protected $table = 'test_prunable_models';

        protected $guarded = [];

        public function getArchivableColumns(): ?array
        {
            return ['id', 'name', 'non_existent_column'];
        }

        public function prunable()
        {
            return self::where('created_at', '<=', now()->subMonth());
        }
    };

    $this->artisan('prunekeeper:validate', ['--model' => [get_class($modelClass)]])
        ->expectsOutputToContain('Invalid columns: non_existent_column')
        ->expectsOutputToContain('Validation failed')
        ->assertExitCode(1);
});

it('shows warning for non-existent model class', function () {
    $this->artisan('prunekeeper:validate', ['--model' => ['App\\Models\\NonExistentModel']])
        ->expectsOutputToContain('Model class not found')
        ->assertExitCode(0);
});

it('shows warning for model without ArchivePrunedRecords trait', function () {
    // Create a model without the trait
    $modelClass = new class extends Model
    {
        use Prunable;

        protected $table = 'test_prunable_models';

        public function prunable()
        {
            return self::where('created_at', '<=', now()->subMonth());
        }
    };

    $this->artisan('prunekeeper:validate', ['--model' => [get_class($modelClass)]])
        ->expectsOutputToContain('does not use ArchivePrunedRecords trait')
        ->assertExitCode(0);
});

it('shows no archivable models warning when none found', function () {
    // Without --model option and no models in app/Models
    $this->artisan('prunekeeper:validate')
        ->expectsOutputToContain('No archivable models found')
        ->assertExitCode(0);
});

it('displays table with validation results', function () {
    $this->artisan('prunekeeper:validate', ['--model' => [TestPrunableModel::class]])
        ->expectsTable(
            ['Model', 'Table', 'Status', 'Message'],
            [
                [TestPrunableModel::class, 'test_prunable_models', '<fg=green>OK</>', 'Using all columns'],
            ]
        )
        ->assertExitCode(0);
});

it('validates multiple models at once', function () {
    $this->artisan('prunekeeper:validate', [
        '--model' => [
            TestPrunableModel::class,
            TestMassPrunableModel::class,
        ],
    ])
        ->expectsOutputToContain('All models validated successfully')
        ->assertExitCode(0);
});
