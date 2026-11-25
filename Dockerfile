# syntax=docker/dockerfile:1

# BYOMailer production image, built on serversideup/php (PHP-FPM and NGINX).
# The composer.lock needs PHP 8.4 (Symfony 8 needs 8.4.1 or later, and
# phpoffice/phpspreadsheet does not accept 8.5 yet).
ARG PHP_IMAGE=serversideup/php:8.4-fpm-nginx-v5.1.2
ARG NODE_IMAGE=node:24-bookworm-slim

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
# Application image
############################################
FROM base AS app

# The image entrypoint runs this script at start. It acts only in the web container.
COPY --chmod=755 deploy/docker/entrypoint.d/60-byomailer-setup.sh /etc/entrypoint.d/60-byomailer-setup.sh
COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /var/www/html/vendor ./vendor
COPY --from=assets --chown=www-data:www-data /var/www/html/public/build ./public/build

RUN composer dump-autoload --no-dev --optimize \
    && mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

# The web server checks that Laravel boots, not only that NGINX answers.
ENV HEALTHCHECK_PATH=/up
