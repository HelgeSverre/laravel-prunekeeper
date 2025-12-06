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
            echo -e "${GREEN}[PASS] Config file exists at config/prunekeeper.php${NC}"
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

    // Test 2: Prunekeeper class is accessible
    if (!class_exists(\HelgeSverre\Prunekeeper\Prunekeeper::class)) {
        echo "FAIL: Prunekeeper class not found\n";
        exit(1);
    }
    echo "PASS: Prunekeeper class exists\n";

    // Test 3: Static methods work
    $enabled = \HelgeSverre\Prunekeeper\Prunekeeper::isEnabled();
    if (!is_bool($enabled)) {
        echo "FAIL: isEnabled() did not return boolean\n";
        exit(1);
    }
    echo "PASS: Static methods work\n";

    // Test 4: Config values loaded
    $config = config('prunekeeper');
    if (!is_array($config)) {
        echo "FAIL: Config not loaded\n";
        exit(1);
    }
    if (!isset($config['disk']) || !isset($config['format']) || !isset($config['compression'])) {
        echo "FAIL: Config missing expected keys\n";
        exit(1);
    }
    echo "PASS: Config loaded with expected keys\n";

    // Test 5: Compression manager resolves
    $compression = $app->make(\HelgeSverre\Prunekeeper\Compression\CompressionManager::class);
    if (!($compression instanceof \HelgeSverre\Prunekeeper\Compression\CompressionManager)) {
        echo "FAIL: CompressionManager not resolved\n";
        exit(1);
    }
    echo "PASS: CompressionManager resolves\n";

    // Test 6: Exporter contract resolves
    $exporter = $app->make(\HelgeSverre\Prunekeeper\Contracts\Exporter::class);
    if (!($exporter instanceof \HelgeSverre\Prunekeeper\Contracts\Exporter)) {
        echo "FAIL: Exporter contract not resolved\n";
        exit(1);
    }
    echo "PASS: Exporter contract resolves\n";

    // Test 7: Commands are registered
    $commands = Artisan::all();
    if (!isset($commands['prunekeeper:archive'])) {
        echo "FAIL: prunekeeper:archive command not registered\n";
        exit(1);
    }
    if (!isset($commands['prunekeeper:validate'])) {
        echo "FAIL: prunekeeper:validate command not registered\n";
        exit(1);
    }
    echo "PASS: Commands registered\n";

    echo "ALL_TESTS_PASSED\n";
    exit(0);
} catch (Exception $e) {
    echo "FAIL: Exception: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
EOF

        echo "Testing service provider and bindings..."
        local test_output=$(php test_prunekeeper.php 2>&1)

        if echo "$test_output" | grep -q "ALL_TESTS_PASSED"; then
            echo -e "${GREEN}[PASS] Service provider registered${NC}"
            echo -e "${GREEN}[PASS] Prunekeeper class exists${NC}"
            echo -e "${GREEN}[PASS] Static methods work${NC}"
            echo -e "${GREEN}[PASS] Config loaded${NC}"
            echo -e "${GREEN}[PASS] CompressionManager resolves${NC}"
            echo -e "${GREEN}[PASS] Exporter contract resolves${NC}"
            echo -e "${GREEN}[PASS] Commands registered${NC}"
        else
            echo -e "${RED}[FAIL] Integration tests failed${NC}"
            echo -e "${BLUE}Output:${NC}"
            echo "$test_output" | sed 's/^/  /'
            cd ../..
            FAILED_VERSIONS+=("$version")
            test_failed=1
        fi

        rm -f test_prunekeeper.php
    fi

    cd ../..

    if [ $test_failed -eq 0 ]; then
        echo -e "${GREEN}[PASS] Laravel ${version} test completed successfully${NC}"
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
