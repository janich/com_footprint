#!/usr/bin/env bash
#
# Build an installable Joomla component from src/.
#
# The sources are laid out for development (src/admin, src/site, src/media),
# while Joomla expects the manifest at the package root with its file groups
# beside it. This script assembles that layout in a temporary folder and
# zips it, so the working tree never has to carry a second copy.
#
# Usage: ./build.sh [output-directory]      (default: ./dist)

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="$ROOT/src"
MANIFEST="$SRC/admin/footprint.xml"
DIST="${1:-$ROOT/dist}"

[ -f "$MANIFEST" ] || { echo "Manifest not found: $MANIFEST" >&2; exit 1; }

# Version straight from the manifest: one source of truth, and the same
# value the component's own footer shows.
VERSION="$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "$MANIFEST" | head -1)"
[ -n "$VERSION" ] || { echo "No <version> in $MANIFEST" >&2; exit 1; }

PACKAGE="com_footprint-$VERSION.zip"
BUILD="$(mktemp -d)"
trap 'rm -rf "$BUILD"' EXIT

# Assemble the package layout the manifest describes.
cp "$MANIFEST" "$BUILD/footprint.xml"
cp -R "$SRC/admin" "$BUILD/admin"

# One licence file in the repo, copied where the package needs it: the zip
# root for anyone inspecting it, and the component folder because every
# source header says "see LICENSE.txt".
cp "$ROOT/LICENSE.txt" "$BUILD/LICENSE.txt"
cp "$ROOT/LICENSE.txt" "$BUILD/admin/LICENSE.txt"
cp -R "$SRC/site" "$BUILD/site"
cp -R "$SRC/media" "$BUILD/media"

# Never ship editor noise or a site's private overrides.
find "$BUILD" \( -name '.DS_Store' -o -name '*.orig' -o -name '*.rej' -o -name 'paths.local.php' \) -delete
find "$BUILD" -name '.git*' -prune -exec rm -rf {} +

mkdir -p "$DIST"
rm -f "$DIST/$PACKAGE"
(cd "$BUILD" && zip -rq "$DIST/$PACKAGE" . -x '.*')

FILES="$(unzip -Z1 "$DIST/$PACKAGE" | grep -vc '/$' || true)"
SIZE="$(du -h "$DIST/$PACKAGE" | cut -f1 | tr -d ' ')"

echo "Built $PACKAGE  ($FILES files, $SIZE)"
echo "  $DIST/$PACKAGE"
