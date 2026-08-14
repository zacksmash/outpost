#!/usr/bin/env bash
set -euo pipefail

# Application processes are started by Supervisor with the container, but the
# worktree is not ready until Outpost has installed dependencies, prepared the
# environment, and run migrations. The marker persists across normal restarts.
while [ ! -f /var/lib/outpost/ready ]; do
    sleep 1
done

exec "$@"
