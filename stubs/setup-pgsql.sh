#!/usr/bin/env bash
set -euo pipefail

# Provision the sandbox PostgreSQL role and database at image build time.
# The cluster keeps its default loopback listener unless the generated
# Supervisor command enables direct service access for an instance.

DB_DATABASE="${1}"
DB_USERNAME="${2}"
DB_PASSWORD="${3}"

service postgresql start

cat > /tmp/outpost-pgsql.sql <<SQL
CREATE ROLE "${DB_USERNAME}" LOGIN SUPERUSER PASSWORD '${DB_PASSWORD}';
CREATE DATABASE "${DB_DATABASE}" OWNER "${DB_USERNAME}";
SQL

su postgres -c "psql --file /tmp/outpost-pgsql.sql"

# Password authentication is still required whenever the per-instance
# process opts into listening on its network interface.
cat >> /etc/postgresql/16/main/pg_hba.conf <<'HBA'
host all all 0.0.0.0/0 scram-sha-256
host all all ::/0 scram-sha-256
HBA

rm /tmp/outpost-pgsql.sql

service postgresql stop
