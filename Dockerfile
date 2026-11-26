# syntax=docker/dockerfile:1

# One image runs all of BYOMailer. s6-overlay keeps these processes running:
#   - NGINX and PHP-FPM (the web server), from serversideup/php
#   - the queue worker (queue:work, or Horizon when QUEUE_CONNECTION=redis)
#   - the scheduler (schedule:work)
#   - the SES events poller, only when the SQS settings are set
#   - Caddy with automatic HTTPS, only when APP_DOMAIN is set
#
# The volume /data holds the SQLite database, the app key and the uploads.
# With no variables, the image runs SQLite on port 8080:
#
#   docker run -d -p 8080:8080 -v byomailer:/data ghcr.io/amedina068/byomailer
#
# docker-compose.yml also runs this image with MySQL, Redis and Horizon (the
# "mysql" profile).
#
# The composer.lock needs PHP 8.4 or later (Symfony 8 needs 8.4.1).
ARG PHP_IMAGE=serversideup/php:8.4-fpm-nginx-trixie-v5.1.2
ARG NODE_IMAGE=node:24-trixie-slim
ARG CADDY_IMAGE=caddy:2.10-alpine

############################################
# Base: PHP with the extensions that the app needs
############################################
FROM ${PHP_IMAGE} AS base

USER root
RUN install-php-extensions bcmath exif gd intl
USER www-data

############################################
# Composer dependencies, without dev packages
############################################
FROM base AS vendor

COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --no-autoloader \
        --prefer-dist

############################################
# Frontend build (Vite). The Wayfinder plugin runs `php artisan`,
# so this stage has PHP and Node.
############################################
FROM ${NODE_IMAGE} AS node

FROM base AS assets

USER root
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx
USER www-data

COPY --chown=www-data:www-data package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /var/www/html/vendor ./vendor
RUN composer dump-autoload --no-dev --optimize \
    && npm run build

############################################
# The Caddy binary for automatic HTTPS
############################################
FROM ${CADDY_IMAGE} AS caddy

############################################
# Application image
############################################
FROM base AS app

USER root

# The database clients that load a schema dump: sqlite3, and mysql from
# mariadb-client-core.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        mariadb-client-core \
        sqlite3 \
    && rm -rf /var/lib/apt/lists/* /var/cache/apt/archives/*

COPY deploy/docker/mysql-client.cnf /etc/mysql/conf.d/byomailer-client.cnf
COPY --from=caddy /usr/bin/caddy /usr/local/bin/caddy
COPY deploy/docker/Caddyfile /etc/caddy/Caddyfile

# The image entrypoint runs these scripts at start, before s6 starts the
# services: 05 prepares /data, 50 (serversideup) runs the migrations, and 60
# makes the storage link.
COPY --chmod=755 deploy/docker/entrypoint.d/ /etc/entrypoint.d/

# The s6 services. Each one is a longrun that depends on "base". The ready
# service prints the last lines of the boot log, so it starts after the web
# server, the queue worker and the scheduler.
COPY --chmod=755 deploy/docker/s6-rc.d/ /etc/s6-overlay/s6-rc.d/
RUN for service in byomailer-queue byomailer-scheduler byomailer-ses-events byomailer-caddy byomailer-ready; do \
        echo longrun > "/etc/s6-overlay/s6-rc.d/${service}/type" \
        && mkdir -p "/etc/s6-overlay/s6-rc.d/${service}/dependencies.d" \
        && touch "/etc/s6-overlay/s6-rc.d/${service}/dependencies.d/base" \
        && touch "/etc/s6-overlay/user-bundles.d/user/contents.d/${service}"; \
    done \
    && for service in nginx php-fpm byomailer-queue byomailer-scheduler; do \
        touch "/etc/s6-overlay/s6-rc.d/byomailer-ready/dependencies.d/${service}"; \
    done \
    && mkdir -p /data/storage/app \
    && chown -R www-data:www-data /data

USER www-data

# The defaults for one container with SQLite. Each value can change with -e.
# APP_URL has no default here: config/app.php derives it from APP_DOMAIN, or
# uses http://localhost:8080.
ENV APP_NAME=BYOMailer \
    APP_ENV=production \
    APP_DEBUG=false \
    APP_KEY_FILE=/data/.secrets/app_key \
    LARAVEL_STORAGE_PATH=/data/storage \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/data/database.sqlite \
    QUEUE_CONNECTION=database \
    CACHE_STORE=file \
    SESSION_DRIVER=file \
    MAIL_MAILER=log \
    INERTIA_SSR_ENABLED=false \
    AUTORUN_ENABLED=true \
    AUTORUN_LARAVEL_MIGRATION_ISOLATION=true \
    AUTORUN_LARAVEL_MIGRATION_TIMEOUT=300 \
    BYOMAILER_PREPARE_ON_START=true \
    PHP_OPCACHE_ENABLE=1 \
    HEALTHCHECK_PATH=/up \
    SHOW_WELCOME_MESSAGE=false

COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /var/www/html/vendor ./vendor
COPY --from=assets --chown=www-data:www-data /var/www/html/public/build ./public/build

RUN composer dump-autoload --no-dev --optimize \
    && mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

VOLUME ["/data"]
EXPOSE 8080 80 443

# A new MySQL database loads database/schema/mysql-schema.sql, so the first
# start takes less than a minute. The start period allows for a slow disk.
HEALTHCHECK --interval=10s --timeout=3s --start-period=300s --start-interval=2s --retries=3 \
    CMD ["sh", "-c", "curl --silent --fail --output /dev/null http://127.0.0.1:${NGINX_HTTP_PORT:-8080}${HEALTHCHECK_PATH} || exit 1"]
