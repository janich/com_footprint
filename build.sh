#!/usr/bin/env bash
#
# Build an installable Joomla component from src/.
#
# The sources are laid out for development (src/admin, src/site, src/media),
# while Joomla expects the manifest at the package root with its file groups
# beside it. This script assembles that layout in a temporary folder and
# zips it, so the working tree never has to carry a second copy.
#
# Usage: ./build.sh [version] [output-directory]      (default: ./dist)
#
# The version is stamped into the package as it is built; release.sh passes
# the one being tagged. Without it you get a development build carrying the
# placeholder from the manifest, which is lower than any release — so a dev
# site is always offered the newest real version.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="$ROOT/src"
MANIFEST="$SRC/admin/footprint.xml"
VERSION="${1:-}"
DIST="${2:-$ROOT/dist}"

[ -f "$MANIFEST" ] || { echo "Manifest not found: $MANIFEST" >&2; exit 1; }

if [ -z "$VERSION" ]; then
    VERSION="$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "$MANIFEST" | head -1)"
    [ -n "$VERSION" ] || { echo "No <version> in $MANIFEST" >&2; exit 1; }
fi

PACKAGE="com_footprint-$VERSION.zip"
BUILD="$(mktemp -d)"
trap 'rm -rf "$BUILD"' EXIT

# Assemble the package layout the manifest describes.
cp "$MANIFEST" "$BUILD/footprint.xml"
cp -R "$SRC/admin" "$BUILD/admin"

# src/admin carries its own copy because the manifest lists it — an installed
# site must have the file its headers point at, and a discover-install from
# source must not reference a file that is only ever added at build time.
# This one is the zip-root copy, for anyone inspecting the package.
cp "$ROOT/LICENSE.txt" "$BUILD/LICENSE.txt"
cp -R "$SRC/site" "$BUILD/site"
cp -R "$SRC/media" "$BUILD/media"

# Stamp the version into both copies of the manifest, never into the working
# tree. Joomla reads the package root one to install and record the version;
# the component folder one is what the running component parses for its own
# footer and statistics, so they have to agree.
for manifest in "$BUILD/footprint.xml" "$BUILD/admin/footprint.xml"; do
    awk -v v="$VERSION" '
        !done && /<version>/ { sub(/<version>[^<]*<\/version>/, "<version>" v "</version>"); done = 1 }
        { print }
    ' "$manifest" > "$manifest.tmp" && mv "$manifest.tmp" "$manifest"
done

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
