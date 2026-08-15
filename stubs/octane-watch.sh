#!/usr/bin/env bash
set -euo pipefail

php_binary="${1:?Outpost requires the selected PHP binary.}"
watch_paths="${2:?Outpost requires the Octane watch paths.}"
shift 2

watcher_script=/app/vendor/laravel/octane/bin/file-watcher.cjs
server_pid=0
watcher_pid=0

if [ ! -f "$watcher_script" ]; then
    echo "Outpost: Laravel Octane's file watcher is missing." >&2
    exit 1
fi

cleanup() {
    trap - EXIT

    for pid in "$watcher_pid" "$server_pid"; do
        if [ "$pid" -ne 0 ] && kill -0 "$pid" 2>/dev/null; then
            kill -TERM "$pid" 2>/dev/null || true
        fi
    done

    for pid in "$watcher_pid" "$server_pid"; do
        if [ "$pid" -ne 0 ]; then
            wait "$pid" 2>/dev/null || true
        fi
    done
}

watch_changes() {
    set -o pipefail

    node "$watcher_script" "$watch_paths" poll |
        while IFS= read -r event; do
            echo "Outpost: $event Reloading Octane workers..."
            "$php_binary" artisan octane:reload
        done
}

trap cleanup EXIT
trap 'exit 143' TERM INT

"$@" &
server_pid=$!

watch_changes &
watcher_pid=$!

status=0
wait -n "$server_pid" "$watcher_pid" || status=$?

exit "$status"
