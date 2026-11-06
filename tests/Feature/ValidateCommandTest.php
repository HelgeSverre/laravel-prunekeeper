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

it('fails validation when no columns are configured', function () {
    $modelClass = new class extends Model
    {
        use ArchivePrunedRecords;
        use Prunable;

        protected $table = 'test_prunable_models';

        public function getArchivableColumns(): ?array
        {
            return [];
        }

        public function prunable()
        {
            return self::query();
        }
    };

    $this->artisan('prunekeeper:validate', ['--model' => [get_class($modelClass)]])
        ->expectsOutputToContain('At least one column must be specified')
        ->assertExitCode(1);
});

it('fails for a non-existent model class', function () {
    $this->artisan('prunekeeper:validate', ['--model' => ['App\\Models\\NonExistentModel']])
        ->expectsOutputToContain('Model class not found')
        ->assertExitCode(1);
});

it('fails for a model without ArchivePrunedRecords trait', function () {
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
        ->assertExitCode(1);
});

it('fails validation when the model table does not exist', function () {
    $modelClass = new class extends Model
    {
        use ArchivePrunedRecords;
        use Prunable;

        protected $table = 'missing_prunekeeper_table';

        public function prunable()
        {
            return self::query();
        }
    };

    $this->artisan('prunekeeper:validate', ['--model' => [get_class($modelClass)]])
        ->expectsOutputToContain('Table does not exist')
        ->assertExitCode(1);
});

it('fails validation when the model has no prunable method', function () {
    $modelClass = new class extends Model
    {
        use ArchivePrunedRecords;

        protected $table = 'test_prunable_models';
    };

    $this->artisan('prunekeeper:validate', ['--model' => [get_class($modelClass)]])
        ->expectsOutputToContain('Missing prunable() method')
        ->assertExitCode(1);
});

it('fails validation when a pruning trait does not override prunable', function () {
    $modelClass = new class extends Model
    {
        use ArchivePrunedRecords;
        use Prunable;

        protected $table = 'test_prunable_models';
    };

    $this->artisan('prunekeeper:validate', ['--model' => [get_class($modelClass)]])
        ->expectsOutputToContain('Could not build prunable query')
        ->assertExitCode(1);
});

it('fails validation when a model does not use a Laravel pruning trait', function () {
    $modelClass = new class extends Model
    {
        use ArchivePrunedRecords;

        protected $table = 'test_prunable_models';

        public function prunable()
        {
            return self::query();
        }
    };

    $this->artisan('prunekeeper:validate', ['--model' => [get_class($modelClass)]])
        ->expectsOutputToContain('must use Laravel\'s Prunable or MassPrunable trait')
        ->assertExitCode(1);
});

it('fails validation when prunable returns a builder for a different model', function () {
    $modelClass = new class extends Model
    {
        use ArchivePrunedRecords;
        use Prunable;

        protected $table = 'test_prunable_models';

        public function prunable()
        {
            return TestPrunableModel::query();
        }
    };

    $this->artisan('prunekeeper:validate', ['--model' => [get_class($modelClass)]])
        ->expectsOutputToContain('must return an Eloquent Builder for the same model')
        ->assertExitCode(1);
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
