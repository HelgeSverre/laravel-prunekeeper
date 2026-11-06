#!/usr/bin/env bash

set -euo pipefail

repo_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
image_name=${PRUNEKEEPER_SMOKE_IMAGE:-prunekeeper-test:8.3}
versions=${PRUNEKEEPER_SMOKE_LARAVEL_VERSIONS:-"11 12 13"}

docker build --build-arg PHP_VERSION=8.3 --tag "$image_name" "$repo_dir"

docker run --rm \
    --volume "$repo_dir:/package:ro" \
    --env PRUNEKEEPER_SMOKE_LARAVEL_VERSIONS="$versions" \
    "$image_name" \
    bash -lc '
        set -euo pipefail

        mkdir -p /tmp/prunekeeper-package
        tar -C /package --exclude=.git --exclude=vendor --exclude=wip --exclude=.idea -cf - . | tar -C /tmp/prunekeeper-package -xf -
        rm -f /tmp/prunekeeper-package/composer.lock
        mkdir -p /tmp/prunekeeper-smoke

        for version in $PRUNEKEEPER_SMOKE_LARAVEL_VERSIONS; do
            project="/tmp/prunekeeper-smoke/laravel-${version}"
            echo "==> Laravel ${version}"

            composer create-project "laravel/laravel=${version}.*" "$project" --no-install --no-scripts --prefer-dist --no-interaction
            cd "$project"
            if [ "$version" = "11" ]; then
                composer config audit.block-insecure false
            fi
            touch database/database.sqlite

            composer config repositories.prunekeeper "{\"type\":\"path\",\"url\":\"/tmp/prunekeeper-package\",\"options\":{\"versions\":{\"helgesverre/laravel-prunekeeper\":\"dev-main\"}}}"
            composer require helgesverre/laravel-prunekeeper:"*@dev" --update-no-dev --prefer-dist --no-interaction
            composer install --no-dev --prefer-dist --no-interaction

            php artisan vendor:publish --tag=prunekeeper-config --force --no-interaction
            test -f config/prunekeeper.php
            php artisan prunekeeper:validate --no-interaction
            php /package/scripts/smoke.php

            package_tests="/tmp/prunekeeper-package-tests-${version}"
            cp -a /tmp/prunekeeper-package "$package_tests"
            cd "$package_tests"
            # Composer 2.9 blocks all currently resolved Laravel 11 releases;
            # this disposable compatibility job must still exercise Laravel 11.
            if [ "$version" = "11" ]; then
                composer config audit.block-insecure false
            fi
            composer require --dev "laravel/framework:^${version}.0" --no-update --no-interaction
            composer update --prefer-dist --no-interaction
            vendor/bin/pest --testsuite=Unit,Feature --compact
            composer analyse
        done
    '
