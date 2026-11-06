<?php

declare(strict_types=1);

use App\Models\CompressedLog;
use App\Models\FailingLog;
use App\Models\MassLog;
use App\Models\PretendLog;
use App\Models\PrunableLog;
use App\Models\SoftLog;
use App\Models\SqlLog;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

require getcwd().'/vendor/autoload.php';

$app = require_once getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }

    fwrite(STDOUT, "PASS: {$message}\n");
}

function archiveFiles(string $path): array
{
    return Storage::disk('local')->allFiles($path);
}

function writeModel(string $class, bool $mass = false, bool $softDeletes = false): void
{
    $traits = $mass ? 'MassPrunable' : 'Prunable';
    $softTrait = $softDeletes ? "use Illuminate\\Database\\Eloquent\\SoftDeletes;\n" : '';
    $softUse = $softDeletes ? "    use SoftDeletes;\n" : '';

    file_put_contents(app_path("Models/{$class}.php"), <<<PHP
<?php

namespace App\\Models;

use HelgeSverre\\Prunekeeper\\ArchivePrunedRecords;
use Illuminate\\Database\\Eloquent\\Builder;
use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Database\\Eloquent\\{$traits};
{$softTrait}
class {$class} extends Model
{
    use {$traits};
    use ArchivePrunedRecords;
{$softUse}
    protected \$guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<=', now()->subDay());
    }
}
PHP);
}

foreach ([
    'PrunableLog' => [false, false],
    'MassLog' => [true, false],
    'SoftLog' => [false, true],
    'PretendLog' => [false, false],
    'FailingLog' => [false, false],
    'SqlLog' => [false, false],
    'CompressedLog' => [false, false],
] as $class => [$mass, $softDeletes]) {
    writeModel($class, $mass, $softDeletes);

    $table = (new ("App\\Models\\{$class}"))->getTable();
    Schema::dropIfExists($table);
    Schema::create($table, function ($table) use ($softDeletes): void {
        $table->id();
        $table->string('message');
        $table->json('payload')->nullable();
        $table->timestamps();
        if ($softDeletes) {
            $table->softDeletes();
        }
    });
}

config([
    'filesystems.default' => 'local',
    'prunekeeper.disk' => 'local',
    'prunekeeper.enabled' => true,
    'prunekeeper.fail_silently' => false,
    'prunekeeper.compression.enabled' => false,
]);

check(is_file(config_path('prunekeeper.php')), 'published configuration is available');
check(isset(Artisan::all()['prunekeeper:archive'], Artisan::all()['prunekeeper:validate']), 'package commands are registered by discovery');

PrunableLog::create(['message' => 'prunable lifecycle record', 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);
MassLog::create(['message' => 'mass lifecycle record', 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);
SoftLog::create(['message' => 'soft lifecycle record', 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);
$trashed = SoftLog::create(['message' => 'already deleted lifecycle record', 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);
$trashed->delete();

config(['prunekeeper.path' => 'smoke/lifecycle']);
$status = Artisan::call('model:prune', ['--model' => [PrunableLog::class, MassLog::class, SoftLog::class], '--no-interaction' => true]);
check($status === 0, 'model:prune completes for Prunable, MassPrunable, and SoftDeletes models');
check(PrunableLog::count() === 0 && MassLog::count() === 0 && SoftLog::withTrashed()->count() === 0, 'successful archive precedes permanent deletion for every pruning strategy');
$lifecycleArchives = archiveFiles('smoke/lifecycle');
check(count($lifecycleArchives) === 3, 'model:prune listener writes one archive for each model');
$lifecycleContent = implode('', array_map(fn (string $file): string => Storage::disk('local')->get($file), $lifecycleArchives));
check(str_contains($lifecycleContent, 'prunable lifecycle record') && str_contains($lifecycleContent, 'mass lifecycle record') && str_contains($lifecycleContent, 'already deleted lifecycle record'), 'listener archives regular, mass-prunable, and soft-deleted records');

PretendLog::create(['message' => 'pretend record', 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);
config(['prunekeeper.path' => 'smoke/pretend']);
$status = Artisan::call('model:prune', ['--model' => [PretendLog::class], '--pretend' => true, '--no-interaction' => true]);
check($status === 0 && PretendLog::count() === 1 && archiveFiles('smoke/pretend') === [], 'pretend mode neither archives nor deletes records');

FailingLog::create(['message' => 'must survive failed archive', 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);
config(['prunekeeper.disk' => 'missing-smoke-disk', 'prunekeeper.path' => 'smoke/failure', 'prunekeeper.fail_silently' => false]);
try {
    Artisan::call('model:prune', ['--model' => [FailingLog::class], '--no-interaction' => true]);
} catch (Throwable) {
    // The command may surface the listener exception directly, depending on the Laravel version.
}
check(FailingLog::count() === 1, 'an archive storage failure prevents deletion');

config(['prunekeeper.disk' => 'local', 'prunekeeper.path' => 'smoke/sql', 'prunekeeper.compression.enabled' => false]);
SqlLog::create([
    'message' => "SQL round-trip O'Reilly",
    'payload' => ['kind' => 'round-trip', 'quoted' => "O'Reilly"],
    'created_at' => now()->subDays(2),
    'updated_at' => now()->subDays(2),
]);
$status = Artisan::call('prunekeeper:archive', ['--model' => [SqlLog::class], '--format' => 'sql', '--no-interaction' => true]);
check($status === 0, 'SQL archive command completes');
$sqlArchives = archiveFiles('smoke/sql');
check(count($sqlArchives) === 1 && str_ends_with($sqlArchives[0], '.sql'), 'SQL archive is stored with its SQL extension');
$sql = Storage::disk('local')->get($sqlArchives[0]);
SqlLog::query()->delete();
foreach (preg_split('/\R/', $sql) ?: [] as $statement) {
    if (str_starts_with($statement, 'INSERT INTO ')) {
        DB::unprepared($statement);
    }
}
check(SqlLog::count() === 1 && SqlLog::firstOrFail()->message === "SQL round-trip O'Reilly", 'SQLite restores a SQL archive without data loss');

config(['prunekeeper.path' => 'smoke/compression', 'prunekeeper.compression.enabled' => true, 'prunekeeper.compression.driver' => 'zip']);
CompressedLog::create(['message' => 'compressed record', 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);
$status = Artisan::call('prunekeeper:archive', ['--model' => [CompressedLog::class], '--no-interaction' => true]);
check($status === 0, 'compressed archive command completes');
$compressedArchives = archiveFiles('smoke/compression');
check(count($compressedArchives) === 1 && str_ends_with($compressedArchives[0], '.zip'), 'ZIP compression creates a ZIP archive');
$zipPath = Storage::disk('local')->path($compressedArchives[0]);
$zip = new ZipArchive;
check($zip->open($zipPath) === true, 'ZIP archive opens successfully');
$compressedContents = $zip->getFromIndex(0);
$zip->close();
check(is_string($compressedContents) && str_contains($compressedContents, 'compressed record'), 'ZIP archive contains the exported record');

fwrite(STDOUT, "SMOKE_TESTS_PASSED\n");
