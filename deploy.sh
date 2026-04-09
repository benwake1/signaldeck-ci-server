#!/usr/bin/env bash
# =============================================================
#  Cypress Dashboard — Post-deployment script
#  Runs on the server after each successful push to main.
#  Works from any install directory — resolves its own location.
# =============================================================
set -e

# Resolve the directory this script lives in (works for any instance)
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHP="php8.4"

# Detect the owner of the app directory and run commands as that user.
# This handles multi-instance deploys where each instance has its own user.
APP_USER="$(stat -c '%U' "${APP_DIR}" 2>/dev/null || stat -f '%Su' "${APP_DIR}")"

# Helper: run a command as the app user (or directly if already that user)
run_as() {
    if [[ "$(whoami)" == "${APP_USER}" ]]; then
        "$@"
    else
        sudo -u "${APP_USER}" "$@"
    fi
}

cd "${APP_DIR}"

# Ensure git trusts this directory when running as a different user
if [[ "$(whoami)" != "${APP_USER}" ]]; then
    git config --global --add safe.directory "${APP_DIR}" 2>/dev/null || true
fi

echo "▶ Pulling latest code..."
run_as git pull --ff-only

echo "▶ Installing PHP dependencies..."
run_as env COMPOSER_NO_INTERACTION=1 composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --ignore-platform-reqs \
    -q

echo "▶ Installing Node dependencies and building assets..."
run_as npm ci --silent
run_as npm run build --silent

echo "▶ Running database migrations..."
run_as ${PHP} artisan migrate --force

echo "▶ Auto-detecting Node/NPM paths..."
DETECTED_NPM="$(run_as which npm 2>/dev/null || true)"
DETECTED_NODE="$(run_as which node 2>/dev/null || true)"
if [[ -n "${DETECTED_NPM}" ]]; then
    run_as sed -i "s|^NPM_PATH=.*|NPM_PATH=${DETECTED_NPM}|" .env
fi
if [[ -n "${DETECTED_NODE}" ]]; then
    run_as sed -i "s|^NODE_PATH=.*|NODE_PATH=${DETECTED_NODE}|" .env
fi

echo "▶ Setting app version from git tag..."
APP_VERSION="${APP_VERSION:-$(run_as git describe --tags --abbrev=0 2>/dev/null || echo "dev")}"
run_as sed -i "s/^APP_VERSION=.*/APP_VERSION=${APP_VERSION}/" .env

echo "▶ Clearing stale caches..."
run_as ${PHP} artisan optimize:clear

echo "▶ Caching config, routes and views..."
run_as ${PHP} artisan config:cache
run_as ${PHP} artisan route:cache
run_as ${PHP} artisan view:cache

echo "▶ Publishing Filament assets..."
run_as ${PHP} artisan filament:assets
run_as ${PHP} artisan package:discover --ansi

echo "▶ Restarting queue worker..."
run_as ${PHP} artisan queue:restart

echo "✔ Deployment complete (user: ${APP_USER}, dir: ${APP_DIR})."
