#!/bin/sh
set -e

# Wait for the database to be reachable before running migrations.
echo "[entrypoint] Waiting for database at ${DATABASE_HOST:-postgres}:${DATABASE_PORT:-5432}..."
DB_HOST="${DB_HOST:-postgres}"
DB_PORT="${DB_PORT:-5432}"
for i in $(seq 1 60); do
    if php -r "
        \$h = getenv('DB_HOST') ?: 'postgres';
        \$p = (int)(getenv('DB_PORT') ?: 5432);
        \$c = @fsockopen(\$h, \$p, \$errno, \$errstr, 2);
        if (\$c) { fclose(\$c); exit(0); }
        exit(1);
    "; then
        echo "[entrypoint] Database is reachable."
        break
    fi
    if [ "$i" -eq 60 ]; then
        echo "[entrypoint] ERROR: database not reachable after 60 attempts." >&2
        exit 1
    fi
    sleep 2
done

# Run database migrations (idempotent; --allow-no-migration tolerates "nothing to do").
echo "[entrypoint] Running database migrations..."
php /var/www/html/bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
echo "[entrypoint] Migrations complete."

# Clear and warm the application cache in prod.
if [ "${APP_ENV:-prod}" = "prod" ]; then
    php /var/www/html/bin/console cache:clear --no-warmup || true
    php /var/www/html/bin/console cache:warmup || true
fi

# Execute the image's default command (Apache).
echo "[entrypoint] Starting web server..."
exec "$@"
