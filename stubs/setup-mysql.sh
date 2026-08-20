#!/usr/bin/env bash
set -euo pipefail

# Initialize the MySQL data directory at image build time and provision
# the instance database and user, so booting an instance never waits on
# database initialization.

DB_DATABASE="${1}"
DB_USERNAME="${2}"
DB_PASSWORD="${3}"

mkdir -p /var/run/mysqld
chown -R mysql:mysql /var/run/mysqld

# The mysql-server package may have initialized the data directory already;
# a second --initialize aborts on the non-empty directory.
if [ ! -d /var/lib/mysql/mysql ]; then
    mysqld --initialize-insecure --user=mysql
fi

mysqld --user=mysql --skip-networking --socket=/var/run/mysqld/mysqld.sock &

for _ in $(seq 1 30); do
    if mysqladmin --socket=/var/run/mysqld/mysqld.sock ping >/dev/null 2>&1; then
        break
    fi

    sleep 1
done

mysql --socket=/var/run/mysqld/mysqld.sock <<SQL
CREATE DATABASE \`${DB_DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '${DB_USERNAME}'@'%' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON *.* TO '${DB_USERNAME}'@'%' WITH GRANT OPTION;
FLUSH PRIVILEGES;
SQL

mysqladmin --socket=/var/run/mysqld/mysqld.sock shutdown
