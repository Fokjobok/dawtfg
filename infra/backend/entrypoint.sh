#!/bin/sh
set -eu

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY is missing. Run scripts/Initialize-Development.ps1 on the host." >&2
    exit 1
fi

# The source is bind-mounted; dependencies and writable runtime data use volumes.
composer install --no-interaction --prefer-dist
mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# Database migrations are an explicit operation, never part of startup.
exec docker-php-entrypoint "$@"
