#!/usr/bin/env bash
set -euo pipefail

# Provision the sandbox PostgreSQL role and database at image build time,
# and open the cluster to connections from the instance's applications.

DB_DATABASE="${1}"
DB_USERNAME="${2}"
DB_PASSWORD="${3}"

PG_VERSION=16
PG_CONF="/etc/postgresql/${PG_VERSION}/main/postgresql.conf"
PG_HBA="/etc/postgresql/${PG_VERSION}/main/pg_hba.conf"

echo "listen_addresses = '*'" >> "${PG_CONF}"
echo "host all all 0.0.0.0/0 scram-sha-256" >> "${PG_HBA}"

service postgresql start

su postgres -c "psql --command \"CREATE ROLE ${DB_USERNAME} LOGIN SUPERUSER PASSWORD '${DB_PASSWORD}';\""
su postgres -c "createdb --owner=${DB_USERNAME} ${DB_DATABASE}"

service postgresql stop
