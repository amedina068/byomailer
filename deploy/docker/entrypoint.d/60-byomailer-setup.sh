#!/bin/sh
# Prepares BYOMailer after the migrations.
#
# The serversideup/php entrypoint runs each script in /etc/entrypoint.d in
# version order. This script runs after 50-laravel-automations.sh, so the
# migrations are complete. Set BYOMAILER_PREPARE_ON_START=false to skip it.
#
# The s6 service byomailer-ready prints the setup URL when the web server
# answers. That line is the last line of the boot log.

if [ "${BYOMAILER_PREPARE_ON_START:-false}" != "true" ]; then
    exit 0
fi

# Create the storage link when it is missing.
php /var/www/html/artisan byomailer:install --prepare --no-interaction
