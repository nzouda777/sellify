#!/bin/bash
set -e

echo "🚀 Starting Laravel app"

# Attendre que la DB soit prête (important sur Render)
until php artisan migrate:status >/dev/null 2>&1; do
  echo "⏳ Waiting for database..."
  sleep 3
done

# Sécurité : clé uniquement si absente
if [ -z "$APP_KEY" ]; then
  php artisan key:generate --force
fi

php artisan migrate --force
php artisan storage:link || true

php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan db:seed

php-fpm -D
nginx -g "daemon off;"
