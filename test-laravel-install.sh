#!/usr/bin/env bash

echo "Testing Prunekeeper Laravel integration across versions"
echo "==========================================================================="
echo ""

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

declare -a PASSED_VERSIONS
declare -a FAILED_VERSIONS
declare -a TESTED_DIRS

mkdir -p wip

run_e2e_archive_test() {
    echo "Running end-to-end archive test..."

    # Configure SQLite database
    echo "  Configuring SQLite database..."
    touch database/database.sqlite

    # Update .env for SQLite
    if grep -q "^DB_CONNECTION=" .env; then
        sed -i.bak 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/' .env
        rm -f .env.bak
    else
        echo "DB_CONNECTION=sqlite" >> .env
    fi

    # Configure Prunekeeper to use local disk
    {
        echo ""
        echo "PRUNEKEEPER_DISK=local"
        echo "PRUNEKEEPER_PATH=prunekeeper-test"
        echo "PRUNEKEEPER_COMPRESS=false"
    } >> .env

    # Create models and migrations
    echo "  Creating test models and migrations..."

    # Model 1: Prunable with CSV export
    if ! php artisan make:model PrunableLog --migration --quiet 2>/dev/null; then
        echo -e "${RED}[FAIL] Failed to create PrunableLog model${NC}"
        return 1
    fi

    # Model 2: Prunable with SQL export
    if ! php artisan make:model PrunableEvent --migration --quiet 2>/dev/null; then
        echo -e "${RED}[FAIL] Failed to create PrunableEvent model${NC}"
        return 1
    fi

    # Model 3: MassPrunable
    if ! php artisan make:model MassPrunableMetric --migration --quiet 2>/dev/null; then
        echo -e "${RED}[FAIL] Failed to create MassPrunableMetric model${NC}"
        return 1
    fi

    # Overwrite PrunableLog model (Prunable trait, default CSV format)
    cat > app/Models/PrunableLog.php << 'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use HelgeSverre\Prunekeeper\ArchivePrunedRecords;

class PrunableLog extends Model
{
    use Prunable;
    use ArchivePrunedRecords;

    protected $fillable = ['message', 'level', 'created_at', 'updated_at'];

    public function prunable(): Builder
    {
        return static::where('created_at', '<=', now()->subDay());
    }
}
PHP

    # Overwrite PrunableEvent model (Prunable trait, will test SQL format)
    cat > app/Models/PrunableEvent.php << 'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use HelgeSverre\Prunekeeper\ArchivePrunedRecords;

class PrunableEvent extends Model
{
    use Prunable;
    use ArchivePrunedRecords;

    protected $fillable = ['name', 'payload', 'created_at', 'updated_at'];

    protected $casts = [
        'payload' => 'array',
    ];

    public function prunable(): Builder
    {
        return static::where('created_at', '<=', now()->subDay());
    }
}
PHP

    # Overwrite MassPrunableMetric model (MassPrunable trait)
    cat > app/Models/MassPrunableMetric.php << 'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\MassPrunable;
use HelgeSverre\Prunekeeper\ArchivePrunedRecords;

class MassPrunableMetric extends Model
{
    use MassPrunable;
    use ArchivePrunedRecords;

    protected $fillable = ['metric_name', 'value', 'created_at', 'updated_at'];

    public function prunable(): Builder
    {
        return static::where('created_at', '<=', now()->subDay());
    }
}
PHP

    # Overwrite migrations
    local migration_file

    migration_file=$(ls database/migrations/*create_prunable_logs_table.php 2>/dev/null | head -n 1)
    if [ -z "$migration_file" ]; then
        echo -e "${RED}[FAIL] PrunableLog migration not found${NC}"
        return 1
    fi
    cat > "$migration_file" << 'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prunable_logs', function (Blueprint $table) {
            $table->id();
            $table->string('message');
            $table->string('level')->default('info');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prunable_logs');
    }
};
PHP

    migration_file=$(ls database/migrations/*create_prunable_events_table.php 2>/dev/null | head -n 1)
    if [ -z "$migration_file" ]; then
        echo -e "${RED}[FAIL] PrunableEvent migration not found${NC}"
        return 1
    fi
    cat > "$migration_file" << 'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prunable_events', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prunable_events');
    }
};
PHP

    migration_file=$(ls database/migrations/*create_mass_prunable_metrics_table.php 2>/dev/null | head -n 1)
    if [ -z "$migration_file" ]; then
        echo -e "${RED}[FAIL] MassPrunableMetric migration not found${NC}"
        return 1
    fi
    cat > "$migration_file" << 'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mass_prunable_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('metric_name');
            $table->decimal('value', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mass_prunable_metrics');
    }
};
PHP

    # Run migrations
    echo "  Running migrations..."
    if ! php artisan migrate --no-interaction --quiet 2>/dev/null; then
        echo -e "${RED}[FAIL] Migrations failed${NC}"
        return 1
    fi

    # Seed test data
    echo "  Seeding test data..."
    cat > seed_test_data.php << 'PHP'
<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\PrunableLog;
use App\Models\PrunableEvent;
use App\Models\MassPrunableMetric;
use Illuminate\Support\Carbon;

$now = Carbon::now();
$old = $now->copy()->subDays(5);

// PrunableLog: 2 old, 1 recent
PrunableLog::create(['message' => 'Old log 1', 'level' => 'info', 'created_at' => $old, 'updated_at' => $old]);
PrunableLog::create(['message' => 'Old log 2', 'level' => 'error', 'created_at' => $old, 'updated_at' => $old]);
PrunableLog::create(['message' => 'Recent log', 'level' => 'info', 'created_at' => $now, 'updated_at' => $now]);

// PrunableEvent: 3 old, 1 recent (with JSON payload)
PrunableEvent::create(['name' => 'user.created', 'payload' => ['user_id' => 1], 'created_at' => $old, 'updated_at' => $old]);
PrunableEvent::create(['name' => 'order.placed', 'payload' => ['order_id' => 100, 'total' => 99.99], 'created_at' => $old, 'updated_at' => $old]);
PrunableEvent::create(['name' => 'user.deleted', 'payload' => null, 'created_at' => $old, 'updated_at' => $old]);
PrunableEvent::create(['name' => 'recent.event', 'payload' => [], 'created_at' => $now, 'updated_at' => $now]);

// MassPrunableMetric: 2 old, 1 recent
MassPrunableMetric::create(['metric_name' => 'cpu_usage', 'value' => 45.5, 'created_at' => $old, 'updated_at' => $old]);
MassPrunableMetric::create(['metric_name' => 'memory_usage', 'value' => 72.3, 'created_at' => $old, 'updated_at' => $old]);
MassPrunableMetric::create(['metric_name' => 'recent_metric', 'value' => 10.0, 'created_at' => $now, 'updated_at' => $now]);

echo "Seeded: PrunableLog (2 old), PrunableEvent (3 old), MassPrunableMetric (2 old)\n";
PHP

    if ! php seed_test_data.php 2>/dev/null; then
        echo -e "${RED}[FAIL] Seeding failed${NC}"
        rm -f seed_test_data.php
        return 1
    fi
    rm -f seed_test_data.php

    # Test 1: Archive PrunableLog with CSV format (default)
    echo "  Testing CSV export (PrunableLog)..."
    local archive_output
    archive_output=$(php artisan prunekeeper:archive --model="App\\Models\\PrunableLog" --format=csv --no-interaction 2>&1)
    if [ $? -ne 0 ]; then
        echo -e "${RED}[FAIL] CSV archive failed${NC}"
        echo "$archive_output" | sed 's/^/    /'
        return 1
    fi

    # Test 2: Archive PrunableEvent with SQL format
    echo "  Testing SQL export (PrunableEvent)..."
    archive_output=$(php artisan prunekeeper:archive --model="App\\Models\\PrunableEvent" --format=sql --no-interaction 2>&1)
    if [ $? -ne 0 ]; then
        echo -e "${RED}[FAIL] SQL archive failed${NC}"
        echo "$archive_output" | sed 's/^/    /'
        return 1
    fi

    # Test 3: Archive MassPrunableMetric with CSV format
    echo "  Testing MassPrunable (MassPrunableMetric)..."
    archive_output=$(php artisan prunekeeper:archive --model="App\\Models\\MassPrunableMetric" --format=csv --no-interaction 2>&1)
    if [ $? -ne 0 ]; then
        echo -e "${RED}[FAIL] MassPrunable archive failed${NC}"
        echo "$archive_output" | sed 's/^/    /'
        return 1
    fi

    # Verify all archives exist and have correct content
    echo "  Verifying archive files..."
    cat > verify_archive.php << 'PHP'
<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Storage;

$disk = config('prunekeeper.disk', 'local');
$path = config('prunekeeper.path', 'prunekeeper-test');

$files = Storage::disk($disk)->allFiles($path);

if (count($files) < 3) {
    echo "FAIL: Expected at least 3 archive files, found " . count($files) . "\n";
    exit(1);
}

$csvFiles = array_filter($files, fn($f) => str_ends_with($f, '.csv'));
$sqlFiles = array_filter($files, fn($f) => str_ends_with($f, '.sql'));

if (count($csvFiles) < 2) {
    echo "FAIL: Expected at least 2 CSV files\n";
    exit(1);
}

if (count($sqlFiles) < 1) {
    echo "FAIL: Expected at least 1 SQL file\n";
    exit(1);
}

// Verify CSV content (PrunableLog)
$logCsv = current(array_filter($csvFiles, fn($f) => str_contains($f, 'prunable_logs')));
if ($logCsv) {
    $contents = Storage::disk($disk)->get($logCsv);
    $lines = explode("\n", trim($contents));
    if (count($lines) !== 3) { // header + 2 records
        echo "FAIL: PrunableLog CSV expected 3 lines, got " . count($lines) . "\n";
        exit(1);
    }
    echo "PASS: PrunableLog CSV has 2 records\n";
}

// Verify SQL content (PrunableEvent)
$eventSql = current(array_filter($sqlFiles, fn($f) => str_contains($f, 'prunable_events')));
if ($eventSql) {
    $contents = Storage::disk($disk)->get($eventSql);
    $insertCount = substr_count($contents, 'INSERT INTO');
    if ($insertCount !== 3) {
        echo "FAIL: PrunableEvent SQL expected 3 INSERT statements, got $insertCount\n";
        exit(1);
    }
    // Verify JSON is properly escaped in SQL
    if (strpos($contents, 'user_id') === false) {
        echo "FAIL: PrunableEvent SQL missing JSON payload content\n";
        exit(1);
    }
    echo "PASS: PrunableEvent SQL has 3 INSERT statements with JSON\n";
}

// Verify MassPrunable CSV
$metricCsv = current(array_filter($csvFiles, fn($f) => str_contains($f, 'mass_prunable_metrics')));
if ($metricCsv) {
    $contents = Storage::disk($disk)->get($metricCsv);
    $lines = explode("\n", trim($contents));
    if (count($lines) !== 3) { // header + 2 records
        echo "FAIL: MassPrunableMetric CSV expected 3 lines, got " . count($lines) . "\n";
        exit(1);
    }
    echo "PASS: MassPrunableMetric (MassPrunable) CSV has 2 records\n";
}

echo "PASS: All archive files verified\n";
echo "Files:\n";
foreach ($files as $file) {
    echo "  - $file\n";
}
exit(0);
PHP

    local verify_output
    verify_output=$(php verify_archive.php 2>&1)
    local verify_status=$?

    rm -f verify_archive.php

    if [ $verify_status -ne 0 ]; then
        echo -e "${RED}[FAIL] Archive verification failed${NC}"
        echo "$verify_output" | sed 's/^/    /'
        return 1
    fi

    echo -e "${GREEN}[PASS] End-to-end archive test passed${NC}"
    echo "$verify_output" | sed 's/^/    /'
    return 0
}

test_laravel_version() {
    local version=$1
    local project_dir="wip/laravel-${version}"
    local test_failed=0

    echo -e "${YELLOW}Testing Laravel ${version}...${NC}"
    echo "----------------------------------------"

    if [ -d "$project_dir" ]; then
        echo "Removing existing ${project_dir}"
        rm -rf "$project_dir"
    fi

    echo "Creating Laravel ${version} project..."
    if ! composer create-project laravel/laravel="${version}.*" "$project_dir" --quiet --no-interaction 2>/dev/null; then
        echo -e "${RED}[FAIL] Failed to create Laravel ${version} project${NC}"
        echo -e "${BLUE}  (This version may not be compatible or available)${NC}"
        FAILED_VERSIONS+=("$version")
        echo ""
        return 1
    fi

    TESTED_DIRS+=("$project_dir")
    cd "$project_dir" || { FAILED_VERSIONS+=("$version"); return 1; }

    echo "Installing prunekeeper package..."
    composer config repositories.local '{"type": "path", "url": "../../"}' --quiet
    if ! composer require helgesverre/laravel-prunekeeper:@dev --quiet --no-interaction 2>/dev/null; then
        echo -e "${RED}[FAIL] Failed to install package${NC}"
        cd ../..
        FAILED_VERSIONS+=("$version")
        test_failed=1
    fi

    if [ $test_failed -eq 0 ]; then
        echo "Publishing config file..."
        if php artisan vendor:publish --tag="prunekeeper-config" --no-interaction > /dev/null 2>&1; then
            echo -e "${GREEN}[PASS] Config published successfully${NC}"
        else
            echo -e "${RED}[FAIL] Failed to publish config${NC}"
            cd ../..
            FAILED_VERSIONS+=("$version")
            test_failed=1
        fi
    fi

    if [ $test_failed -eq 0 ]; then
        if [ -f "config/prunekeeper.php" ]; then
            echo -e "${GREEN}[PASS] Config file exists${NC}"
        else
            echo -e "${RED}[FAIL] Config file not found${NC}"
            cd ../..
            FAILED_VERSIONS+=("$version")
            test_failed=1
        fi
    fi

    if [ $test_failed -eq 0 ]; then
        cat > test_prunekeeper.php << 'EOF'
<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    // Test 1: Service provider registered
    $providers = $app->getLoadedProviders();
    if (!isset($providers['HelgeSverre\Prunekeeper\PrunekeeperServiceProvider'])) {
        echo "FAIL: Service provider not registered\n";
        exit(1);
    }
    echo "PASS: Service provider registered\n";

    // Test 2: Static methods work
    $enabled = \HelgeSverre\Prunekeeper\Prunekeeper::isEnabled();
    if (!is_bool($enabled)) {
        echo "FAIL: isEnabled() did not return boolean\n";
        exit(1);
    }
    echo "PASS: Static methods work\n";

    // Test 3: Config values loaded
    $config = config('prunekeeper');
    if (!is_array($config) || !isset($config['disk']) || !isset($config['compression'])) {
        echo "FAIL: Config not loaded correctly\n";
        exit(1);
    }
    echo "PASS: Config loaded\n";

    // Test 4: CompressionManager resolves
    $compression = $app->make(\HelgeSverre\Prunekeeper\Compression\CompressionManager::class);
    if (!($compression instanceof \HelgeSverre\Prunekeeper\Compression\CompressionManager)) {
        echo "FAIL: CompressionManager not resolved\n";
        exit(1);
    }
    echo "PASS: CompressionManager resolves\n";

    // Test 5: Commands are registered
    $commands = Artisan::all();
    if (!isset($commands['prunekeeper:archive']) || !isset($commands['prunekeeper:validate'])) {
        echo "FAIL: Commands not registered\n";
        exit(1);
    }
    echo "PASS: Commands registered\n";

    echo "ALL_TESTS_PASSED\n";
    exit(0);
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    exit(1);
}
EOF

        echo "Testing service provider and bindings..."
        local test_output=$(php test_prunekeeper.php 2>&1)

        if echo "$test_output" | grep -q "ALL_TESTS_PASSED"; then
            echo -e "${GREEN}[PASS] Service provider registered${NC}"
            echo -e "${GREEN}[PASS] Static methods work${NC}"
            echo -e "${GREEN}[PASS] Config loaded${NC}"
            echo -e "${GREEN}[PASS] CompressionManager resolves${NC}"
            echo -e "${GREEN}[PASS] Commands registered${NC}"
        else
            echo -e "${RED}[FAIL] Integration tests failed${NC}"
            echo "$test_output" | sed 's/^/  /'
            cd ../..
            FAILED_VERSIONS+=("$version")
            test_failed=1
        fi

        rm -f test_prunekeeper.php
    fi

    # Run end-to-end archive test
    if [ $test_failed -eq 0 ]; then
        if ! run_e2e_archive_test; then
            cd ../..
            FAILED_VERSIONS+=("$version")
            test_failed=1
        fi
    fi

    cd ../..

    if [ $test_failed -eq 0 ]; then
        echo -e "${GREEN}[PASS] Laravel ${version} - all tests passed${NC}"
        PASSED_VERSIONS+=("$version")
    fi

    echo ""
    return $test_failed
}

test_laravel_version "11"
test_laravel_version "12"

echo ""
echo "==========================================================================="
echo "Test Summary"
echo "==========================================================================="
echo ""

if [ ${#PASSED_VERSIONS[@]} -gt 0 ]; then
    echo -e "${GREEN}Passed (${#PASSED_VERSIONS[@]})${NC}"
    for version in "${PASSED_VERSIONS[@]}"; do
        echo -e "  ${GREEN}*${NC} Laravel ${version}"
    done
    echo ""
fi

if [ ${#FAILED_VERSIONS[@]} -gt 0 ]; then
    echo -e "${RED}Failed (${#FAILED_VERSIONS[@]})${NC}"
    for version in "${FAILED_VERSIONS[@]}"; do
        echo -e "  ${RED}*${NC} Laravel ${version}"
    done
    echo ""
fi

echo "Test projects are located in:"
for dir in "${TESTED_DIRS[@]}"; do
    echo "  - $dir"
done
echo ""
echo "To clean up: rm -rf wip"
echo ""

if [ ${#FAILED_VERSIONS[@]} -gt 0 ]; then
    exit 1
else
    echo -e "${GREEN}All tests passed!${NC}"
    exit 0
fi
