# The Outpost base image.
#
# Every instance boots from this image and differs only by the generated
# configuration mounted at /outpost, so this image installs everything
# Outpost knows how to run and enables none of it. Build it with
# `php artisan outpost:build` — never by hand — so the DNS and
# database build arguments are supplied correctly.

FROM ubuntu:24.04

ARG DB_DATABASE=outpost
ARG DB_USERNAME=outpost
ARG DB_PASSWORD=password

ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=UTC

# Base tooling, web server, databases, and cache.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates curl git gnupg lsb-release software-properties-common unzip zip \
        supervisor nginx redis-server mysql-server postgresql-16 \
    && rm -rf /var/lib/apt/lists/*

# PHP 8.4 and 8.5 with the extensions Laravel applications expect.
RUN add-apt-repository -y ppa:ondrej/php \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        php8.4-cli php8.4-fpm php8.4-bcmath php8.4-curl php8.4-gd php8.4-intl \
        php8.4-mbstring php8.4-mysql php8.4-pgsql php8.4-redis php8.4-sqlite3 \
        php8.4-xml php8.4-zip \
        php8.5-cli php8.5-fpm php8.5-bcmath php8.5-curl php8.5-gd php8.5-intl \
        php8.5-mbstring php8.5-mysql php8.5-pgsql php8.5-redis php8.5-sqlite3 \
        php8.5-xml php8.5-zip \
    && rm -rf /var/lib/apt/lists/*

# Composer, Node, and Mailpit.
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

RUN curl -fsSL https://raw.githubusercontent.com/axllent/mailpit/develop/install.sh | bash

# Sandbox-friendly PHP settings for every version and SAPI.
COPY php.ini /etc/php/8.4/cli/conf.d/99-outpost.ini
COPY php.ini /etc/php/8.4/fpm/conf.d/99-outpost.ini
COPY php.ini /etc/php/8.5/cli/conf.d/99-outpost.ini
COPY php.ini /etc/php/8.5/fpm/conf.d/99-outpost.ini

# MySQL overrides must live in mysql.conf.d with a name that sorts last,
# because my.cnf includes conf.d before mysql.conf.d and the last value
# for a setting wins.
COPY mysql.cnf /etc/mysql/mysql.conf.d/zz-outpost.cnf

# Initialize the databases at build time so instances boot provisioned.
COPY setup-mysql.sh /usr/local/share/outpost/setup-mysql.sh
COPY setup-pgsql.sh /usr/local/share/outpost/setup-pgsql.sh

RUN bash /usr/local/share/outpost/setup-mysql.sh "$DB_DATABASE" "$DB_USERNAME" "$DB_PASSWORD" \
    && bash /usr/local/share/outpost/setup-pgsql.sh "$DB_DATABASE" "$DB_USERNAME" "$DB_PASSWORD"

COPY entrypoint.sh /usr/local/bin/outpost-entrypoint
RUN chmod +x /usr/local/bin/outpost-entrypoint

WORKDIR /app
EXPOSE 80

ENTRYPOINT ["/usr/local/bin/outpost-entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisor/supervisord.conf", "-n"]
