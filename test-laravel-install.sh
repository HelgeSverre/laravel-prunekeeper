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

    # Create model and migration
    echo "  Creating PruneTest model and migration..."
    if ! php artisan make:model PruneTest --migration --quiet 2>/dev/null; then
        echo -e "${RED}[FAIL] Failed to create model/migration${NC}"
        return 1
    fi

    # Overwrite the model with ArchivePrunedRecords + Prunable
    cat > app/Models/PruneTest.php << 'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use HelgeSverre\Prunekeeper\ArchivePrunedRecords;

class PruneTest extends Model
{
    use Prunable;
    use ArchivePrunedRecords;

    protected $fillable = ['name', 'created_at', 'updated_at'];

    public function prunable(): Builder
    {
        return static::where('created_at', '<=', now()->subDay());
    }
}
PHP

    # Find and overwrite the migration
    local migration_file
    migration_file=$(ls database/migrations/*create_prune_tests_table.php 2>/dev/null | head -n 1)

    if [ -z "$migration_file" ]; then
        echo -e "${RED}[FAIL] Migration file not found${NC}"
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
        Schema::create('prune_tests', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prune_tests');
    }
};
PHP

    # Run migrations
    echo "  Running migrations..."
    if ! php artisan migrate --no-interaction --quiet 2>/dev/null; then
        echo -e "${RED}[FAIL] Migrations failed${NC}"
        return 1
    fi

    # Create test data using a PHP script
    echo "  Seeding test data..."
    cat > seed_test_data.php << 'PHP'
<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\PruneTest;
use Illuminate\Support\Carbon;

$now = Carbon::now();

// Old records (should be prunable)
PruneTest::create([
    'name' => 'Old record 1',
    'created_at' => $now->copy()->subDays(10),
    'updated_at' => $now->copy()->subDays(10),
]);

PruneTest::create([
    'name' => 'Old record 2',
    'created_at' => $now->copy()->subDays(2),
    'updated_at' => $now->copy()->subDays(2),
]);

// Recent record (should NOT be prunable)
PruneTest::create([
    'name' => 'Recent record',
    'created_at' => $now,
    'updated_at' => $now,
]);

echo "Seeded 3 records (2 prunable, 1 recent)\n";
PHP

    if ! php seed_test_data.php 2>/dev/null; then
        echo -e "${RED}[FAIL] Seeding failed${NC}"
        rm -f seed_test_data.php
        return 1
    fi
    rm -f seed_test_data.php

    # Run prunekeeper:archive
    echo "  Running prunekeeper:archive..."
    local archive_output
    archive_output=$(php artisan prunekeeper:archive --model="App\\Models\\PruneTest" --no-interaction 2>&1)
    local archive_status=$?

    if [ $archive_status -ne 0 ]; then
        echo -e "${RED}[FAIL] prunekeeper:archive command failed${NC}"
        echo "$archive_output" | sed 's/^/    /'
        return 1
    fi

    # Verify archive file exists
    echo "  Verifying archive file exists..."
    cat > verify_archive.php << 'PHP'
<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Storage;

$config = config('prunekeeper');
$disk = $config['disk'] ?? 'local';
$path = $config['path'] ?? 'prunekeeper-test';

$files = Storage::disk($disk)->allFiles($path);

if (empty($files)) {
    echo "FAIL: No archive files found\n";
    exit(1);
}

// Check file has content
$firstFile = $files[0];
$contents = Storage::disk($disk)->get($firstFile);

if (empty($contents)) {
    echo "FAIL: Archive file is empty\n";
    exit(1);
}

// Verify CSV has expected headers and data
$lines = explode("\n", trim($contents));
if (count($lines) < 2) {
    echo "FAIL: Archive has fewer than 2 lines (header + data)\n";
    exit(1);
}

// Check header contains expected columns
$header = $lines[0];
if (strpos($header, 'id') === false || strpos($header, 'name') === false) {
    echo "FAIL: Archive header missing expected columns\n";
    echo "Header: $header\n";
    exit(1);
}

// Should have 2 data rows (the 2 old records)
$dataRows = count($lines) - 1;
if ($dataRows !== 2) {
    echo "FAIL: Expected 2 data rows, got $dataRows\n";
    exit(1);
}

echo "PASS: Archive created with 2 records\n";
echo "File: $firstFile\n";
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
