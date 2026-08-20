# The Outpost base image.
#
# Every instance boots from this image and differs only by the generated
# configuration mounted at /etc/outpost, so this image installs everything
# Outpost knows how to run and enables none of it. Build it with
# `php artisan outpost:build` — never by hand — so the DNS and
# database build arguments are supplied correctly.

FROM ubuntu:24.04

ARG DB_DATABASE=outpost
ARG DB_USERNAME=outpost
ARG DB_PASSWORD=password
ARG PHP_VERSIONS="8.4 8.5"
ARG MAILPIT_VERSION=v1.30.7
ARG MAILPIT_SHA256=12c736a73d122c55d3531ac2fc92cad96dbde283af661b6701fe32892bd438c5

ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=UTC
ENV COMPOSER_ALLOW_SUPERUSER=1

LABEL io.github.zacksmash.outpost.runtime-path="/etc/outpost"

# Ubuntu 24.04 ships a stock "ubuntu" user occupying UID/GID 1000, which
# collides with the IDs the entrypoint maps onto the "outpost" user for
# bind-mount ownership. Remove it so any host UID can be mapped.
RUN userdel --remove ubuntu 2>/dev/null || true

# Base tooling, web server, databases, and cache.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates curl git gnupg lsb-release software-properties-common unzip zip \
        supervisor nginx redis-server mysql-server postgresql-16 \
    && rm -rf /var/lib/apt/lists/*

# Every configured PHP version with the extensions Laravel applications
# expect. The version list arrives from config('outpost.php') via the
# outpost:build command; changing the config only takes effect at
# the next rebuild, and instances always pin what they detect.
RUN add-apt-repository -y ppa:ondrej/php \
    && apt-get update \
    && for v in $PHP_VERSIONS; do \
        apt-get install -y --no-install-recommends \
            php$v-cli php$v-fpm php$v-bcmath php$v-curl php$v-gd php$v-intl \
            php$v-mbstring php$v-mysql php$v-pgsql php$v-redis php$v-sqlite3 \
            php$v-xml php$v-zip; \
    done \
    && rm -rf /var/lib/apt/lists/*

# Composer, Node, and Mailpit — no piping remote scripts to a root shell:
# Node comes from NodeSource's signed apt repository, and Mailpit is a
# pinned release verified against its checksum before installation.
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key \
        | gpg --dearmor -o /usr/share/keyrings/nodesource.gpg \
    && echo "deb [signed-by=/usr/share/keyrings/nodesource.gpg] https://deb.nodesource.com/node_22.x nodistro main" \
        > /etc/apt/sources.list.d/nodesource.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends nodejs \
    && npm cache clean --force \
    && rm -rf /var/lib/apt/lists/*

# Playwright's Ubuntu 24.04 Chromium runtime dependencies. Projects still
# install their own Playwright version and matching browser binary as the
# non-root application user; the image supplies only the privileged OS layer.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libfontconfig1 libfreetype6 \
        libasound2t64 libatk-bridge2.0-0t64 libatk1.0-0t64 libatspi2.0-0t64 \
        libcairo2 libcups2t64 libdbus-1-3 libdrm2 libgbm1 libglib2.0-0t64 \
        libnspr4 libnss3 libpango-1.0-0 \
        libx11-6 libxcb1 libxcomposite1 libxdamage1 libxext6 libxfixes3 \
        libxkbcommon0 libxrandr2 \
    && rm -rf /var/lib/apt/lists/*

RUN curl -fsSL "https://github.com/axllent/mailpit/releases/download/${MAILPIT_VERSION}/mailpit-linux-arm64.tar.gz" \
        -o /tmp/mailpit.tar.gz \
    && echo "${MAILPIT_SHA256}  /tmp/mailpit.tar.gz" | sha256sum -c - \
    && tar -xzf /tmp/mailpit.tar.gz -C /usr/local/bin mailpit \
    && rm /tmp/mailpit.tar.gz

# Development-friendly PHP settings for every version and SAPI.
COPY php.ini /tmp/outpost-php.ini
RUN for v in $PHP_VERSIONS; do \
        cp /tmp/outpost-php.ini /etc/php/$v/cli/conf.d/99-outpost.ini; \
        cp /tmp/outpost-php.ini /etc/php/$v/fpm/conf.d/99-outpost.ini; \
    done \
    && rm /tmp/outpost-php.ini

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
COPY wait-for-app.sh /usr/local/bin/outpost-wait
RUN chmod +x /usr/local/bin/outpost-entrypoint /usr/local/bin/outpost-wait

WORKDIR /app
EXPOSE 80 443 1025 3306 5432 6379 8025

ENTRYPOINT ["/usr/local/bin/outpost-entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisor/supervisord.conf", "-n"]
