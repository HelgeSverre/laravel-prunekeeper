#!/usr/bin/env bash

set -euo pipefail

repo_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$repo_dir"

docker compose run --rm --env CI=true php bash -c '
    set -euo pipefail
    mkdir -p /tmp/prunekeeper-tests
    tar -C /app --exclude=.git --exclude=vendor --exclude=wip --exclude=.idea --exclude=composer.lock -cf - . | tar -C /tmp/prunekeeper-tests -xf -
    cd /tmp/prunekeeper-tests
    composer update --prefer-dist --no-interaction
    vendor/bin/pest "$@"
' bash "$@"
