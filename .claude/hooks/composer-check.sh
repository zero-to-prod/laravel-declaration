#!/usr/bin/env bash
#
# Stop hook. Runs `composer check` when src/ or tests/ have changed, and hands
# the failure back to Claude so it fixes the work before you see it.
#
# Exit 0 says nothing needs doing. Exit 2 wakes Claude with whatever the check
# printed.

set -uo pipefail

input=$(cat)

# One wake-up per turn. Without this, a failure Claude cannot fix — a missing
# coverage driver, say — would wake it again on every stop, forever.
if [ "$(printf '%s' "$input" | jq -r '.stop_hook_active // false')" = 'true' ]; then
    exit 0
fi

directory=${CLAUDE_PROJECT_DIR:-}
cd "${directory:-$(git rev-parse --show-toplevel 2>/dev/null)}" 2>/dev/null || exit 0

# Nothing under src/ or tests/ is dirty, so nothing can have broken.
if [ -z "$(git status --porcelain -- src tests 2>/dev/null)" ]; then
    exit 0
fi

# A content signature of that same tree, so a turn that edits nothing new —
# answering a question, say — does not pay for the check a second time.
signature=$(
    {
        git diff HEAD -- src tests
        git ls-files --others --exclude-standard -- src tests | while read -r file; do
            shasum "$file"
        done
    } 2>/dev/null | shasum | cut -d ' ' -f 1
)

stamp="${TMPDIR:-/tmp}/composer-check-$(pwd | shasum | cut -c 1-12)"

if [ "$signature" = "$(cat "$stamp" 2>/dev/null)" ]; then
    exit 0
fi

if output=$(composer check 2>&1); then
    printf '%s' "$signature" >"$stamp"
    exit 0
fi

# Everything the check printed goes to stderr, which is what Claude reads. The
# tail keeps a runaway suite from flooding the context; the note says so, so a
# truncated failure is never mistaken for the whole story.
lines=$(printf '%s\n' "$output" | wc -l | tr -d ' ')

if [ "$lines" -gt 200 ]; then
    printf '`composer check` failed. Showing the last 200 of %s lines; run it yourself for the rest.\n\n' "$lines" >&2
else
    printf '`composer check` failed.\n\n' >&2
fi

printf '%s\n' "$output" | tail -n 200 >&2

exit 2
