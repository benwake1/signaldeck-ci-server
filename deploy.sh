#!/usr/bin/env bash
# =============================================================
#  Cypress Dashboard — DeployHQ post-deployment script
#  Runs on the server after each successful push to main.
# =============================================================
set -e

APP_DIR="/var/www/cypress-dashboard"
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
