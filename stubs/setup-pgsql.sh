#!/usr/bin/env bash
set -euo pipefail

# Provision the sandbox PostgreSQL role and database at image build time.
# The application lives in the same container, so the cluster keeps its
# default loopback-only listener — other instances on the container
# network cannot reach it.

DB_DATABASE="${1}"
DB_USERNAME="${2}"
DB_PASSWORD="${3}"

service postgresql start

cat > /tmp/outpost-pgsql.sql <<SQL
CREATE ROLE "${DB_USERNAME}" LOGIN SUPERUSER PASSWORD '${DB_PASSWORD}';
CREATE DATABASE "${DB_DATABASE}" OWNER "${DB_USERNAME}";
SQL

su postgres -c "psql --file /tmp/outpost-pgsql.sql"

rm /tmp/outpost-pgsql.sql

service postgresql stop
