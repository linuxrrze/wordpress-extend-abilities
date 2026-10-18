#!/usr/bin/env bash
# Boots a throwaway WordPress in WordPress Playground (PHP as WebAssembly, SQLite),
# mounts the plugin, and runs the end-to-end tests against it.
# Needs Node.js and Python 3. No PHP, MySQL or Docker required.
#
# Usage: tests/e2e/run.sh [--fixture path/to/image.png] [-k name-filter]
# Env:   PLAYGROUND  command to start the Playground CLI (default: pinned npx version)
#        PORT        port for the test site (default 9400)
#        PLUGIN_DIR  plugin directory to test (default: the source next to tests/)
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
# PLUGIN_DIR: test another copy of the plugin, e.g. the unpacked release zip (CI does this).
PLUGIN="$(cd "${PLUGIN_DIR:-$HERE/../..}" && pwd)"
PORT="${PORT:-9400}"
PLAYGROUND="${PLAYGROUND:-npx -y @wp-playground/cli@3.1.56}"
OUT="$(mktemp -d)"

echo "Starting Playground on port $PORT (log: $OUT/server.log)"
# shellcheck disable=SC2086 # PLAYGROUND is a command line on purpose
$PLAYGROUND server \
	--port="$PORT" --php=8.3 --wp=7.1 \
	--mount="$PLUGIN:/wordpress/wp-content/plugins/extended-abilities" \
	--mount="$HERE:/e2e" \
	--mount="$HERE/helpers-mu-plugin.php:/wordpress/wp-content/mu-plugins/exab-e2e-helpers.php" \
	--mount="$OUT:/e2e-out" \
	--blueprint="$HERE/blueprint.json" >"$OUT/server.log" 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true; wait "$SERVER_PID" 2>/dev/null || true' EXIT

for _ in $(seq 1 300); do
	if [[ -s "$OUT/creds.json" ]] && curl -fsS -o /dev/null "http://127.0.0.1:$PORT/wp-json/"; then
		break
	fi
	if ! kill -0 "$SERVER_PID" 2>/dev/null; then
		echo "Playground exited early:"; cat "$OUT/server.log"; exit 1
	fi
	sleep 1
done
[[ -s "$OUT/creds.json" ]] || { echo "Timed out waiting for the test site"; cat "$OUT/server.log"; exit 1; }

status=0
python3 -I "$HERE/test_upload.py" --base "http://127.0.0.1:$PORT" --creds "$OUT/creds.json" "$@" || status=$?

# PHP notices, warnings and errors fail the run too (other log lines, e.g. from cron, don't).
if [[ -s "$OUT/debug.log" ]] && grep -q "PHP " "$OUT/debug.log"; then
	echo; echo "PHP debug log has messages:"; grep "PHP " "$OUT/debug.log"
	status=1
fi
exit "$status"
