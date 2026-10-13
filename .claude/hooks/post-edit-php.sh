#!/usr/bin/env bash
# PostToolUse hook: after Claude edits a PHP file, format it with Pint and run PHPStan (Larastan) on it.
# Exit 2 + stderr = feedback shown to Claude, so type errors come back immediately.
set -uo pipefail

input="$(cat)"
file="$(jq -r '.tool_input.file_path // empty' <<<"$input")"

[[ -z "$file" || "$file" != *.php || ! -f "$file" ]] && exit 0
# Never touch dependencies; PHPStan below runs only for src/ and tests/ (config/ is checked by composer check).
[[ "$file" == */vendor/* ]] && exit 0

root="${CLAUDE_PROJECT_DIR:-$(pwd)}"
cd "$root" || exit 0
[[ -x vendor/bin/pint && -x vendor/bin/phpstan ]] || exit 0

vendor/bin/pint --quiet "$file" >/dev/null 2>&1 || true

case "$file" in
  "$root"/src/*|"$root"/tests/*|src/*|tests/*) ;;
  *) exit 0 ;;
esac

if ! output="$(vendor/bin/phpstan analyse --no-progress --error-format=raw --memory-limit=1G "$file" 2>&1)"; then
  {
    echo "PHPStan found problems in $file:"
    echo "$output"
  } >&2
  exit 2
fi

exit 0
