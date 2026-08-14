#!/usr/bin/env bash
set -euo pipefail

# Refuse to boot without the generated instance configuration. Serving
# nginx's stock page from a "healthy" container is the worst debugging
# experience this image can produce, so fail loudly instead.
for file in nginx.conf supervisord.conf; do
    if [ ! -f "/outpost/${file}" ]; then
        echo "Outpost: /outpost/${file} is missing." >&2
        echo "Outpost: this container must be booted by the outpost command, not by hand." >&2
        exit 1
    fi
done

cp /outpost/nginx.conf /etc/nginx/sites-available/default
cp /outpost/supervisord.conf /etc/supervisor/conf.d/outpost.conf

mkdir -p /run/php /var/run/mysqld
chown mysql:mysql /var/run/mysqld

# Bind mounts from macOS refuse ownership changes; that must not be fatal.
chown -R www-data:www-data /app 2>/dev/null || true

exec "$@"
