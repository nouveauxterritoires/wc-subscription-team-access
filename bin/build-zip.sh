#!/usr/bin/env bash
# Builds build/<slug>.zip from the committed files, minus the paths listed in .distignore.
# Usage: bin/build-zip.sh [git-ref]   (default: HEAD)
set -euo pipefail

cd "$(dirname "$0")/.."
SLUG="exprime-team-access"
REF="${1:-HEAD}"
OUT="build"

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

# git archive only ships tracked files: local leftovers never reach the zip.
git archive --format=tar --prefix="$SLUG/" "$REF" | tar -x -C "$STAGE"

while IFS= read -r line; do
	pattern="${line%%#*}"
	pattern="$(printf '%s' "$pattern" | xargs)"
	[ -z "$pattern" ] && continue
	if [[ "$pattern" == /* ]]; then
		rm -rf "$STAGE/$SLUG${pattern}"
	else
		find "$STAGE/$SLUG" -name "$pattern" -exec rm -rf {} +
	fi
done < .distignore

mkdir -p "$OUT"
rm -f "$OUT/$SLUG.zip"
(cd "$STAGE" && zip -qr -X "$OLDPWD/$OUT/$SLUG.zip" "$SLUG")

echo "$OUT/$SLUG.zip"
unzip -l "$OUT/$SLUG.zip" | tail -n 1
