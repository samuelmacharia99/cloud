#!/bin/sh
set -e

mkdir -p /app

# When the container starts as root (first boot / maintenance), normalize ownership.
if [ "$(id -u)" = "0" ]; then
    if id www-data >/dev/null 2>&1; then
        chown -R www-data:www-data /app
    fi
fi

exec "$@"
