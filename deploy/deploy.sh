#!/usr/bin/env bash
# ROVEXY — deploy the latest code from GitHub on the live server (Ubuntu, nginx + PHP-FPM + MySQL + Redis).
# First time: see deploy/README.md. Every later release: just run   sudo -u www-data bash /var/www/rovexy/deploy/deploy.sh
set -euo pipefail

APP=${APP:-/var/www/ametecs_apps/rovexy}   # the git checkout (repo root: backend/, admin/, deploy/ …)
BRANCH=${BRANCH:-main}
PHP=${PHP:-php8.3}

cd "$APP"
echo "▶ Pulling $BRANCH…"
git fetch --quiet origin "$BRANCH"
git reset --hard "origin/$BRANCH"

cd "$APP/backend"
php artisan down --retry=30 || true            # maintenance page while files change
trap 'php artisan up || true' EXIT             # always come back up, even if a step fails

echo "▶ PHP packages…"
composer install --no-dev --optimize-autoloader --no-interaction --quiet

echo "▶ Admin console…"
cd "$APP/admin"
npm ci --no-audit --no-fund --silent
npm run build --silent                          # builds into backend/public/admin

cd "$APP/backend"
mkdir -p storage/app/keys storage/app/updates storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
echo "▶ Database…"
php artisan migrate --force
echo "▶ Caches…"
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link 2>/dev/null || true
php artisan rovexy:health-check

echo "▶ Restarting workers…"
php artisan queue:restart
sudo systemctl reload "$PHP-fpm" 2>/dev/null || true
sudo systemctl restart rovexy-reverb 2>/dev/null || true

php artisan up
trap - EXIT
echo "✔ Live: $(grep -o '"version": *"[^"]*"' version.json)"
