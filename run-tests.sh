#!/usr/bin/env bash
# Run the plugin's PHPUnit harness.
#
# Uses a local PHP if one is on PATH; otherwise falls back to the official
# php:8.2-cli Docker image (mirrors CI). Composer deps are installed on
# first run. Pass any extra args straight through to phpunit, e.g.:
#
#   ./run-tests.sh --testdox
#   ./run-tests.sh --filter test_permission_rejects_unauthenticated_request
set -euo pipefail

cd "$(dirname "$0")"

if command -v php >/dev/null 2>&1 && command -v composer >/dev/null 2>&1; then
	[ -d vendor ] || composer install --no-interaction --no-progress
	exec php vendor/bin/phpunit "$@"
fi

echo "No local php/composer — running via Docker (php:8.2-cli, composer:2)." >&2
DOCKER="docker"
command -v docker >/dev/null 2>&1 || { echo "docker not found either; install PHP 8.2+ or Docker." >&2; exit 1; }

[ -d vendor ] || "$DOCKER" run --rm -v "$PWD":/app -w /app composer:2 install --no-interaction --no-progress
exec "$DOCKER" run --rm -v "$PWD":/app -w /app php:8.2-cli php vendor/bin/phpunit "$@"
