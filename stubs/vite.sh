#!/usr/bin/env bash
set -euo pipefail

url="${1:?Outpost requires the public Vite URL.}"
hot_file="${2:?Outpost requires the Vite hot-file path.}"
shift 2

case "$hot_file" in
    /*|*..*|*\\*)
        echo "Outpost: refusing unsafe Vite hot-file path [$hot_file]." >&2
        exit 1
        ;;
esac

hot_path="/app/$hot_file"
child=0

cleanup() {
    rm -f "$hot_path"

    if [ "$child" -ne 0 ] && kill -0 "$child" 2>/dev/null; then
        kill -TERM "$child" 2>/dev/null || true
        wait "$child" 2>/dev/null || true
    fi
}

trap cleanup EXIT
trap 'exit 143' TERM INT

"$@" &
child=$!

attempt=0

while [ ! -f "$hot_path" ]; do
    if ! kill -0 "$child" 2>/dev/null; then
        wait "$child"
    fi

    attempt=$((attempt + 1))

    if [ "$attempt" -ge 300 ]; then
        echo "Outpost: Vite did not create [$hot_file] within 60 seconds." >&2
        exit 1
    fi

    sleep 0.2
done

# Laravel's Vite plugin writes the address it bound inside the container.
# Replace it with the routable container hostname the browser can reach.
printf '%s\n' "$url" > "$hot_path"

wait "$child"
