# Laravel Prunekeeper - Development Commands
# Run `just` or `just --list` to see available commands

# Default recipe: show available commands
[private]
default:
    @just --list --unsorted

# ─────────────────────────────────────────────────────────────────────────────
# Docker
# ─────────────────────────────────────────────────────────────────────────────

# Start all test database containers
[group('docker')]
up:
    docker compose up -d

# Stop all test database containers
[group('docker')]
down:
    docker compose down

# Show container status
[group('docker')]
ps:
    docker compose ps

# Show container logs
[group('docker')]
logs *args:
    docker compose logs {{ args }}

# Build PHP test container
[group('docker')]
build:
    docker compose build php

# Open shell in PHP container
[group('docker')]
shell: up wait
    docker compose run --rm php bash

# Wait for all database containers to be healthy
[group('docker')]
wait:
    #!/usr/bin/env bash
    set -euo pipefail
    echo "Waiting for database containers to be healthy..."

    containers=(
        "prunekeeper-mysql"
        "prunekeeper-mysql84"
        "prunekeeper-postgres"
        "prunekeeper-postgres15"
        "prunekeeper-postgres14"
        "prunekeeper-mariadb"
        "prunekeeper-mariadb10"
    )

    for container in "${containers[@]}"; do
        if docker ps --format '{{{{.Names}}' | grep -q "^${container}$"; then
            echo -n "Waiting for ${container}..."
            until docker inspect --format='{{{{.State.Health.Status}}' "${container}" 2>/dev/null | grep -q "healthy"; do
                echo -n "."
                sleep 1
            done
            echo " ready!"
        fi
    done
    echo "All running containers are healthy."

# ─────────────────────────────────────────────────────────────────────────────
# Testing
# ─────────────────────────────────────────────────────────────────────────────

# Run all tests (starts docker if needed)
[group('test')]
test: up wait
    vendor/bin/pest

# Run tests in parallel (starts docker if needed)
[group('test')]
test-parallel: up wait
    vendor/bin/pest --parallel

# Run unit tests only (no docker needed)
[group('test')]
test-unit:
    vendor/bin/pest --testsuite=Unit

# Run feature tests only (no docker needed)
[group('test')]
test-feature:
    vendor/bin/pest --testsuite=Feature

# Run integration tests only (starts docker if needed)
[group('test')]
test-integration: up wait
    vendor/bin/pest --testsuite=Integration

# Run e2e Laravel installation test
[group('test')]
e2e:
    ./test-laravel-install.sh

# Run tests with coverage report (uses herd for Xdebug)
[group('test')]
coverage *args: up wait
    herd coverage vendor/bin/pest --coverage {{ args }}

# Run a specific test file or filter
[group('test')]
test-filter filter: up wait
    vendor/bin/pest --filter="{{ filter }}"

# Run all tests in Docker container
[group('test')]
dtest: install up wait
    docker compose run --rm php vendor/bin/pest

# Run unit tests in Docker container
[group('test')]
dtest-unit: install  up wait
    docker compose run --rm php vendor/bin/pest --testsuite=Unit

# Run integration tests in Docker container
[group('test')]
dtest-integration: install  up wait
    docker compose run --rm php vendor/bin/pest --testsuite=Integration

# Run tests with coverage in Docker container (uses pcov)
[group('test')]
dcoverage *args: up wait
    docker compose run --rm php vendor/bin/pest --coverage {{ args }}

# ─────────────────────────────────────────────────────────────────────────────
# Code Quality
# ─────────────────────────────────────────────────────────────────────────────

# Run static analysis with PHPStan
[group('quality')]
analyse:
    vendor/bin/phpstan analyse src --level=6

# Run code formatter (Laravel Pint)
[group('quality')]
format:
    vendor/bin/pint

# Check code formatting without making changes
[group('quality')]
format-check:
    vendor/bin/pint --test

# Run format + analyse (no tests)
[group('quality')]
lint: format analyse

# Run all quality checks (format, analyse, test)
[group('quality')]
check: format analyse test

# ─────────────────────────────────────────────────────────────────────────────
# Workflows
# ─────────────────────────────────────────────────────────────────────────────

# Simulate CI pipeline (format-check, analyse, all tests)
[group('workflow')]
ci: format-check analyse test

# Pre-PR checks (format-check, analyse, unit + feature tests)
[group('workflow')]
pr: format-check analyse test-unit test-feature

# ─────────────────────────────────────────────────────────────────────────────
# Development
# ─────────────────────────────────────────────────────────────────────────────

# Install composer dependencies
[group('dev')]
install:
    composer install

# Update composer dependencies
[group('dev')]
update:
    composer update

# Clear all caches and generated files
[group('dev')]
clean:
    rm -rf .phpunit.cache
    rm -rf coverage
    rm -rf vendor

# First-time setup (clean + install + docker)
[group('dev')]
setup: clean install up wait
