#!/bin/sh
set -eu

if [ "${APP_ENV:-}" != production ] || [ "${APP_DEBUG:-}" != false ] || [ -z "${APP_KEY:-}" ]; then
    echo "Production requires APP_ENV=production, APP_DEBUG=false and a persistent APP_KEY." >&2
    exit 1
fi
case "${APP_URL:-}" in
    https://*) ;;
    *) echo "Production APP_URL must use HTTPS." >&2; exit 1 ;;
esac
if [ "${SESSION_SECURE_COOKIE:-}" != true ]; then
    echo "Production session cookies must be Secure." >&2
    exit 1
fi

mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs
php artisan package:discover --no-interaction
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
# No dependency installs, key generation or migrations during startup.
exec docker-php-entrypoint "$@"
