#!/usr/bin/env sh

set -e

TOOL_DIR=build/bc-check
BIN="$TOOL_DIR/vendor/bin/roave-backward-compatibility-check"

if [ -z "$(git tag --list '[0-9]*.[0-9]*.[0-9]*' 'v[0-9]*.[0-9]*.[0-9]*')" ]; then
    echo "bc-check: no SemVer release tags found, nothing to compare against. Skipping."
    exit 0
fi

if [ ! -x "$BIN" ]; then
    echo "bc-check: installing roave/backward-compatibility-check into $TOOL_DIR"
    mkdir -p "$TOOL_DIR"
    composer require --working-dir="$TOOL_DIR" \
        roave/backward-compatibility-check --no-interaction --no-progress
fi

exec "$BIN" "$@"