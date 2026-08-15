#!/usr/bin/env bash
set -euo pipefail

# Refuse to boot without the generated instance configuration. Serving
# nginx's stock page from a "healthy" container is the worst debugging
# experience this image can produce, so fail loudly instead.
for file in nginx.conf supervisord.conf; do
    if [ ! -f "/etc/outpost/${file}" ]; then
        echo "Outpost: /etc/outpost/${file} is missing." >&2
        echo "Outpost: this container must be booted by the outpost command, not by hand." >&2
        exit 1
    fi
done

cp /etc/outpost/nginx.conf /etc/nginx/sites-available/default
cp /etc/outpost/supervisord.conf /etc/supervisor/conf.d/outpost.conf

outpost_uid="${OUTPOST_UID:-1000}"
outpost_gid="${OUTPOST_GID:-1000}"

if [[ ! "$outpost_uid" =~ ^[1-9][0-9]*$ || ! "$outpost_gid" =~ ^[1-9][0-9]*$ ]]; then
    echo "Outpost: OUTPOST_UID and OUTPOST_GID must be positive integers." >&2
    exit 1
fi

if ! getent group "$outpost_gid" >/dev/null; then
    groupadd --gid "$outpost_gid" outpost
fi

if ! id outpost >/dev/null 2>&1; then
    useradd --uid "$outpost_uid" --gid "$outpost_gid" --create-home --shell /bin/bash outpost
fi

outpost_group="$(id --group --name outpost)"

mkdir -p /home/outpost/.composer /home/outpost/.npm
chown -R outpost:"$outpost_group" /home/outpost

for pool in /etc/php/*/fpm/pool.d/www.conf; do
    sed -i "s/^user = .*/user = outpost/; s/^group = .*/group = ${outpost_group}/" "$pool"
done

mkdir -p /run/php /var/run/mysqld /var/lib/outpost
chown mysql:mysql /var/run/mysqld
chown outpost:"$outpost_group" /var/lib/outpost

exec "$@"
