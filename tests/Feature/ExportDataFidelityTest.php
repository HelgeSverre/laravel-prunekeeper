<?php

declare(strict_types=1);

use HelgeSverre\Prunekeeper\Exporters\CsvExporter;
use HelgeSverre\Prunekeeper\Exporters\SqlExporter;
use HelgeSverre\Prunekeeper\Listeners\ArchiveBeforePruning;
use HelgeSverre\Prunekeeper\Tests\Fixtures\TestPrunableModel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\ModelPruningStarting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ExportDataFidelityModel extends Model
{
    protected $table = 'export_data_fidelity_models';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['secret'];

    protected $appends = ['computed'];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    protected function computed(): Attribute
    {
        return Attribute::get(fn (): string => 'computed value');
    }
}

class GeneratedColumnExportModel extends Model
{
    protected $table = 'generated_column_export_models';

    public $timestamps = false;

    protected $guarded = [];
}

beforeEach(function () {
    Schema::create('export_data_fidelity_models', function (Blueprint $table) {
        $table->id();
        $table->string('secret');
        $table->text('metadata');
        $table->float('amount')->nullable();
    });

    Schema::create('generated_column_export_models', function (Blueprint $table) {
        $table->id();
        $table->integer('amount');
        $table->integer('doubled_amount')->storedAs('amount * 2');
    });

    DB::table('export_data_fidelity_models')->insert([
        'secret' => 'archive-me',
        'metadata' => '{"amount":1.0,"path":"a/b"}',
    ]);
});

afterEach(function () {
    Schema::dropIfExists('generated_column_export_models');
    Schema::dropIfExists('export_data_fidelity_models');
});

it('exports hidden selected database columns and preserves raw CSV values', function () {
    $tempFile = (new CsvExporter)->export(ExportDataFidelityModel::query(), ['secret', 'metadata']);

    $rows = array_map('str_getcsv', file($tempFile, FILE_IGNORE_NEW_LINES));

    expect($rows)
        ->toBe([
            ['secret', 'metadata'],
            ['archive-me', '{"amount":1.0,"path":"a/b"}'],
        ]);

    @unlink($tempFile);
});

it('exports hidden database columns without virtual appended attributes', function () {
    $tempFile = (new CsvExporter)->export(ExportDataFidelityModel::query());

    $rows = array_map('str_getcsv', file($tempFile, FILE_IGNORE_NEW_LINES));

    expect($rows[0])
        ->toContain('secret')
        ->not->toContain('computed');
    expect($rows[1])->toContain('archive-me');

    @unlink($tempFile);
});

it('exports hidden selected database columns and raw values to SQL', function () {
    $tempFile = (new SqlExporter)->export(ExportDataFidelityModel::query(), ['secret', 'metadata']);
    $content = file_get_contents($tempFile);

    expect($content)
        ->toContain('"secret", "metadata"')
        ->toContain('archive-me')
        ->toContain('{"amount":1.0,"path":"a/b"}')
        ->not->toContain('computed');

    @unlink($tempFile);
});

it('rejects an empty column list', function (string $exporterClass) {
    (new $exporterClass)->export(ExportDataFidelityModel::query(), []);
})->with([
    CsvExporter::class,
    SqlExporter::class,
])->throws(InvalidArgumentException::class, 'At least one column must be specified');

it('rejects an incomplete query projection', function (string $exporterClass) {
    (new $exporterClass)->export(ExportDataFidelityModel::query()->select('id'));
})->with([
    CsvExporter::class,
    SqlExporter::class,
])->throws(RuntimeException::class, 'Archive query did not load required column(s): secret, metadata');

it('rejects SQL values containing NUL bytes', function () {
    DB::table('export_data_fidelity_models')->insert([
        'secret' => 'binary value',
        'metadata' => "before\0after",
    ]);

    (new SqlExporter)->export(ExportDataFidelityModel::query());
})->throws(RuntimeException::class, 'SQL export does not support values containing NUL bytes');

it('rejects resource values in CSV data', function () {
    $stream = fopen('php://temp', 'r');

    try {
        $exporter = new class extends CsvExporter
        {
            /**
             * @param  array<string, mixed>  $data
             * @return array<string, scalar|null>
             */
            public function flatten(array $data): array
            {
                return $this->flattenForCsv($data);
            }
        };

        $exporter->flatten(['payload' => $stream]);
    } finally {
        fclose($stream);
    }
})->throws(RuntimeException::class, 'CSV export does not support resource values in column payload');

it('stops pruning when SQL archiving finds a NUL byte', function () {
    config([
        'prunekeeper.format' => 'sql',
        'prunekeeper.compression.enabled' => false,
        'prunekeeper.fail_silently' => false,
    ]);

    DB::table('test_prunable_models')->insert([
        'name' => 'NUL byte record',
        'metadata' => "before\0after",
        'created_at' => now()->subMonths(2),
        'updated_at' => now(),
    ]);

    try {
        app(ArchiveBeforePruning::class)->handle(new ModelPruningStarting([TestPrunableModel::class]));
        $this->fail('Expected the NUL byte archive failure to stop pruning.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toContain('SQL export does not support values containing NUL bytes');
    }

    expect(TestPrunableModel::count())->toBe(1);
});

it('preserves REAL precision in CSV and SQL exports', function () {
    $amount = 1.2345678901234567;

    DB::statement(
        "INSERT INTO export_data_fidelity_models (secret, metadata, amount) VALUES ('precise amount', '{}', 1.2345678901234567)"
    );

    $columns = ['secret', 'metadata', 'amount'];
    $query = ExportDataFidelityModel::query()->where('secret', 'precise amount');

    $csvFile = (new CsvExporter)->export($query, $columns);
    $rows = array_map('str_getcsv', file($csvFile, FILE_IGNORE_NEW_LINES));

    expect($rows)->toBe([
        $columns,
        ['precise amount', '{}', '1.2345678901234567'],
    ]);

    $sqlFile = (new SqlExporter)->export(ExportDataFidelityModel::query()->where('secret', 'precise amount'), $columns);
    $statement = collect(explode("\n", file_get_contents($sqlFile)))
        ->first(fn (string $line): bool => str_starts_with($line, 'INSERT INTO'));

    expect($statement)->toContain('1.2345678901234567');

    DB::table('export_data_fidelity_models')->where('secret', 'precise amount')->delete();
    DB::statement($statement);

    expect(DB::table('export_data_fidelity_models')->where('secret', 'precise amount')->value('amount'))->toBe($amount);

    @unlink($csvFile);
    @unlink($sqlFile);
});

it('rejects non-finite CSV floats', function () {
    $exporter = new class extends CsvExporter
    {
        /**
         * @param  array<string, mixed>  $data
         * @return array<string, scalar|null>
         */
        public function flatten(array $data): array
        {
            return $this->flattenForCsv($data);
        }
    };

    $exporter->flatten(['amount' => INF]);
})->throws(RuntimeException::class, 'CSV export does not support non-finite float values in column amount');

it('rejects non-finite SQL floats', function () {
    $exporter = new class extends SqlExporter
    {
        public function escape(mixed $value): string
        {
            return $this->escapeValue($value, column: 'amount');
        }
    };

    $exporter->escape(INF);
})->throws(RuntimeException::class, 'SQL export does not support non-finite float values in column amount');

it('formats finite floats independently of serialize precision', function () {
    $csvExporter = new class extends CsvExporter
    {
        /**
         * @param  array<string, mixed>  $data
         * @return array<string, scalar|null>
         */
        public function flatten(array $data): array
        {
            return $this->flattenForCsv($data);
        }
    };

    $sqlExporter = new class extends SqlExporter
    {
        public function escape(mixed $value): string
        {
            return $this->escapeValue($value, column: 'amount');
        }
    };

    $previousPrecision = ini_get('serialize_precision');
    ini_set('serialize_precision', '4');

    try {
        expect($csvExporter->flatten(['amount' => 1.2345678901234567]))->toBe(['amount' => '1.2345678901234567']);
        expect($sqlExporter->escape(1.2345678901234567))->toBe('1.2345678901234567');
    } finally {
        ini_set('serialize_precision', $previousPrecision);
    }
});

it('omits generated columns from default SQL archives', function () {
    DB::table('generated_column_export_models')->insert(['amount' => 21]);

    $tempFile = (new SqlExporter)->export(GeneratedColumnExportModel::query());
    $statement = collect(explode("\n", file_get_contents($tempFile)))
        ->first(fn (string $line): bool => str_starts_with($line, 'INSERT INTO'));

    expect($statement)->not->toContain('doubled_amount');

    DB::table('generated_column_export_models')->delete();
    DB::statement($statement);

    expect(DB::table('generated_column_export_models')->first())
        ->amount->toBe(21)
        ->doubled_amount->toBe(42);

    @unlink($tempFile);
});

it('rejects generated columns selected for a SQL archive', function () {
    (new SqlExporter)->export(GeneratedColumnExportModel::query(), ['id', 'doubled_amount']);
})->throws(InvalidArgumentException::class, 'Generated columns cannot be included in SQL archives: doubled_amount');
