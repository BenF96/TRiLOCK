#!/bin/sh
set -e

cd /var/www/html

if [ "${WAIT_FOR_DB:-true}" = "true" ] && [ -n "$DB_HOST" ]; then
    echo "Waiting for database at ${DB_HOST}:${DB_PORT:-5432}..."
    tries=0
    until php -r "exit(@fsockopen(getenv('DB_HOST'), (int) (getenv('DB_PORT') ?: 5432)) ? 0 : 1);"; do
        tries=$((tries + 1))
        if [ "$tries" -ge 30 ]; then
            echo "Database did not become reachable in time, continuing anyway."
            break
        fi
        sleep 1
    done
fi

# Every replica needs this (storage:link creates a symlink on the
# container's own filesystem), but it's a no-op if it already exists.
php artisan storage:link --force > /dev/null 2>&1 || true

# Bake config/routes/views into a single cached file per Opcache guidance
# above. Safe to re-run on every boot; each container caches to its own
# local bootstrap/cache.
php artisan optimize

# Migrations are intentionally NOT run here: with multiple replicas starting
# concurrently, every container hitting this entrypoint would race to run
# `migrate`. Run it once as an explicit deploy step instead:
#   docker compose run --rm app php artisan migrate --force

exec "$@"
