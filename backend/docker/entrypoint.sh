#!/bin/sh
set -e

cd /var/www/html

if [ -z "$APP_KEY" ]; then
    echo "APP_KEY is not set. Generate one with:" >&2
    echo "  docker compose run --rm --no-deps api php artisan key:generate --show" >&2
    echo "and put it in .env (repo root)." >&2
    exit 1
fi

# Only the API container migrates, so workers never race it. Set by compose.
if [ "$RUN_MIGRATIONS" = "true" ]; then
    php artisan migrate --force
    php artisan storage:link 2>/dev/null || true
fi

# Cached config bakes in env values; workers and the API share one image, so
# rebuild the caches from the container's own env on every start.
php artisan config:cache
php artisan route:cache || true

exec "$@"
