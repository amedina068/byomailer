#!/bin/sh
# Prepares the /data volume before Laravel starts.
#
# The serversideup/php entrypoint runs each script in /etc/entrypoint.d in a
# subshell, in version order. An `export` in this script does not reach PHP.
# For this reason, the app key goes to a file, and config/app.php reads the
# file (APP_KEY_FILE).
#
# The /data volume holds:
#   /data/.secrets/app_key   the app key (mode 600), made on the first start
#   /data/database.sqlite    the SQLite database
#   /data/storage            the Laravel storage folder (LARAVEL_STORAGE_PATH):
#                            the uploads, the logs and the GeoIP database
#   /data/caddy              the TLS certificates, when APP_DOMAIN is set
set -e

data_dir="${BYOMAILER_DATA_DIR:-/data}"

# Record the start time. The ready service prints the boot duration.
date +%s > /tmp/byomailer-started-at

if ! mkdir -p "$data_dir" 2>/dev/null || ! [ -w "$data_dir" ]; then
    echo "❌ BYOMailer cannot write to $data_dir."
    echo "   The container runs as the user $(id -u):$(id -g)."
    echo "   Use a named volume: -v byomailer:$data_dir"
    echo "   For a host folder, give it to that user: chown -R $(id -u):$(id -g) <folder>"
    exit 1
fi

umask 077
mkdir -p "$data_dir/.secrets"
umask 022

# The app key. A value in APP_KEY wins. Otherwise the file holds the key, and
# the file stays the same on each later start.
key_file="${APP_KEY_FILE:-$data_dir/.secrets/app_key}"
if [ -z "${APP_KEY:-}" ] && [ ! -s "$key_file" ]; then
    (
        umask 077
        php -r 'echo "base64:".base64_encode(random_bytes(32)), PHP_EOL;' > "$key_file.tmp"
        mv "$key_file.tmp" "$key_file"
    )
    echo "🔑 BYOMailer made a new app key in $key_file. Back up the $data_dir volume."
    echo "   The key encrypts the AWS credentials of each account."
fi

# The Laravel storage folder.
storage_dir="${LARAVEL_STORAGE_PATH:-}"
if [ -n "$storage_dir" ] && ! mkdir -p \
    "$storage_dir/app/public" \
    "$storage_dir/framework/cache/data" \
    "$storage_dir/framework/sessions" \
    "$storage_dir/framework/views" \
    "$storage_dir/logs" 2>/dev/null; then
    echo "❌ BYOMailer cannot write to $storage_dir."
    echo "   Give the folder to the user $(id -u):$(id -g): chown -R $(id -u):$(id -g) <folder>"
    exit 1
fi

# The "mysql" profile of docker-compose.yml starts a service with the name
# mysql. The app uses it only with the MySQL block of .env.example.
if [ "${DB_CONNECTION:-}" = "sqlite" ] && getent hosts mysql > /dev/null 2>&1; then
    echo "⚠️ The mysql service runs, but DB_CONNECTION is sqlite, so BYOMailer uses SQLite."
    echo "   To use MySQL, set the MySQL block of .env.example in .env. Then start the stack again."
fi

# Laravel opens a SQLite file only when the file exists.
if [ "${DB_CONNECTION:-}" = "sqlite" ]; then
    db_file="${DB_DATABASE:-$data_dir/database.sqlite}"
    case "$db_file" in
        :memory:) ;;
        *) [ -e "$db_file" ] || touch "$db_file" ;;
    esac
fi
