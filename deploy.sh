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

cd "${APP_DIR}"

echo "▶ Installing PHP dependencies..."
COMPOSER_NO_INTERACTION=1 composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --ignore-platform-reqs \
    -q

echo "▶ Installing Node dependencies and building assets..."
npm ci --silent
npm run build --silent

echo "▶ Running database migrations..."
${PHP} artisan migrate --force

echo "▶ Setting app version from git tag..."
APP_VERSION="${APP_VERSION:-$(git describe --tags --abbrev=0 2>/dev/null || echo "dev")}"
sed -i "s/^APP_VERSION=.*/APP_VERSION=${APP_VERSION}/" .env

echo "▶ Caching config, routes and views..."
${PHP} artisan config:cache
${PHP} artisan route:cache
${PHP} artisan view:cache

echo "▶ Publishing Filament assets..."
${PHP} artisan filament:assets
${PHP} artisan package:discover --ansi

echo "▶ Restarting queue worker..."
${PHP} artisan queue:restart

echo "✔ Deployment complete."
