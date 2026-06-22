#!/bin/sh
set -e

cd /var/www/html

if [ ! -f vendor/autoload.php ]; then
  echo "[api] Installing Composer dependencies..."
  composer install --no-interaction --prefer-dist --optimize-autoloader
fi

echo "[api] Waiting for Postgres..."
until php -r "new PDO('pgsql:host=${DB_HOST:-postgres};port=${DB_PORT:-5432};dbname=${DB_DATABASE:-spidernet}', '${DB_USERNAME:-postgres}', '${DB_PASSWORD:-postgres}');" 2>/dev/null; do
  sleep 2
done

if [ -f /docker-init/init-pgvector.sql ]; then
  PGPASSWORD="${DB_PASSWORD:-postgres}" psql -h "${DB_HOST:-postgres}" -U "${DB_USERNAME:-postgres}" -d "${DB_DATABASE:-spidernet}" -f /docker-init/init-pgvector.sql || true
fi

if [ -f /docker-init/init-v2-schema.sql ]; then
  PGPASSWORD="${DB_PASSWORD:-postgres}" psql -h "${DB_HOST:-postgres}" -U "${DB_USERNAME:-postgres}" -d "${DB_DATABASE:-spidernet}" -f /docker-init/init-v2-schema.sql || true
fi

echo "[api] Running migrations..."
php artisan migrate --force

if [ "${RUN_DB_SEED:-true}" = "true" ]; then
  USER_COUNT=$(PGPASSWORD="${DB_PASSWORD:-postgres}" psql -h "${DB_HOST:-postgres}" -U "${DB_USERNAME:-postgres}" -d "${DB_DATABASE:-spidernet}" -tAc "SELECT COUNT(*) FROM users" 2>/dev/null || echo 0)
  if [ "${USER_COUNT:-0}" = "0" ]; then
    php artisan db:seed --force || true
  else
    echo "[api] Skipping seed ($USER_COUNT users already present)"
  fi
fi

echo "[api] Starting Laravel on :8000"
exec php artisan serve --host=0.0.0.0 --port=8000
