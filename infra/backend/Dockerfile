FROM debian:trixie-slim AS libxml2-builder
WORKDIR /build
COPY infra/toolchain/libxml2/ ./
RUN sh build-package.sh

FROM debian:trixie-slim AS krb5-builder
WORKDIR /build
COPY infra/toolchain/krb5/prepare-source.sh ./
RUN sh prepare-source.sh
COPY infra/toolchain/krb5/build-package.sh infra/toolchain/krb5/*.patch ./
RUN sh build-package.sh

FROM composer:2.11.1 AS composer
RUN cp /usr/bin/composer /tmp/composer.phar \
    && php -r '(new Phar("/tmp/composer.phar"))->extractTo("/composer-source");'
FROM php:8.5.12-fpm-trixie AS composer-toolchain

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer-bootstrap
WORKDIR /opt/composer
COPY --from=composer /composer-source/src ./src
COPY --from=composer /composer-source/bin ./bin
COPY --from=composer /composer-source/res ./res
COPY --from=composer /composer-source/LICENSE ./LICENSE
COPY infra/toolchain/composer/composer.json infra/toolchain/composer/composer.lock ./
RUN composer-bootstrap install --no-interaction --prefer-dist --no-dev --no-scripts \
    && php bin/composer audit --locked

FROM php:8.5.12-fpm-trixie AS php-runtime

RUN --mount=type=bind,from=libxml2-builder,source=/out,target=/packages \
    --mount=type=bind,from=krb5-builder,source=/out,target=/kerberos \
    apt-get update \
    && apt-get upgrade -y --no-install-recommends \
    && apt-get install -y --no-install-recommends /packages/libxml2.deb /kerberos/*.deb unzip libpq-dev libzip-dev libfcgi-bin \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql zip \
    && apt-mark manual libpq5 libzip5 \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS libpq-dev libzip-dev \
    && rm -rf /var/lib/apt/lists/*

FROM php-runtime AS production-dependencies
COPY --from=composer-toolchain /opt/composer /opt/composer
COPY --chmod=755 infra/toolchain/composer/composer.sh /usr/local/bin/composer
WORKDIR /var/www/html
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader
COPY backend/app ./app
COPY backend/bootstrap ./bootstrap
COPY backend/config ./config
COPY backend/database ./database
COPY backend/public ./public
COPY backend/routes ./routes
COPY backend/artisan ./artisan
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts

FROM php-runtime AS production
WORKDIR /var/www/html
COPY --from=production-dependencies --chown=www-data:www-data /var/www/html ./
COPY infra/production/php.ini /usr/local/etc/php/conf.d/zz-production.ini
COPY infra/production/fpm.conf /usr/local/etc/php-fpm.d/zz-production.conf
COPY --chmod=755 infra/production/backend-entrypoint.sh /usr/local/bin/production-entrypoint
RUN cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini \
    && sed -i '/^user =/d; /^group =/d' /usr/local/etc/php-fpm.d/www.conf \
    && mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data
ENTRYPOINT ["production-entrypoint"]
CMD ["php-fpm", "-F"]

# Keep development as the default target for the existing compose.yaml.
FROM php-runtime AS development
COPY --from=composer-toolchain /opt/composer /opt/composer
COPY --chmod=755 infra/toolchain/composer/composer.sh /usr/local/bin/composer
COPY infra/backend/php.ini /usr/local/etc/php/conf.d/zz-development.ini
COPY infra/backend/fpm.conf /usr/local/etc/php-fpm.d/zz-development.conf

ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /var/www/html
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader

COPY backend/storage/ ./storage/
COPY backend/bootstrap/cache/.gitignore ./bootstrap/cache/.gitignore
COPY --chmod=755 infra/backend/entrypoint.sh /usr/local/bin/development-entrypoint

ENTRYPOINT ["development-entrypoint"]
CMD ["php-fpm"]
