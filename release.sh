#!/usr/bin/env bash
#
# Publish a release.
#
# Run it on main, after the development pull request is merged, naming the
# version to publish:
#
#     ./release.sh 1.3.0
#
# That version is decided here and nowhere else. There is no version to bump
# beforehand: the manifest carries a placeholder, and build.sh stamps the real
# number into the package as it is built. The git tag is the version.
#
# It builds the package, tags the commit, creates the GitHub release with the
# package attached, and updates the file Joomla reads to learn that a new
# version exists. Every check runs before anything is pushed, so a failed
# check costs nothing.
#
# Requires the GitHub CLI, logged in once:
#
#     gh auth login

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

REPO="janich/com_footprint"
BRANCH="main"

fail() { echo "✗ $1" >&2; exit 1; }

# Newest release tag, ignoring the one being published so a resumed run does
# not measure itself against itself.
latest_release() {
    git tag --list 'v*' --sort=-v:refname | grep -v "^${TAG:-}\$" | head -1
}

# --- checks, cheapest first ------------------------------------------------

if [ $# -eq 0 ]; then
    git fetch --quiet --tags origin 2>/dev/null || true
    LATEST="$(latest_release)"

    echo "Usage: ./release.sh <version>" >&2
    echo >&2
    echo "  Latest release: ${LATEST:-none yet}" >&2
    echo "  The version lives in the tag, so name the next one, e.g. 1.3.0" >&2
    exit 1
fi

VERSION="$1"
TAG="v$VERSION"

case "$VERSION" in
    v*) fail "Write the version without the leading v: ./release.sh ${VERSION#v}" ;;
esac

echo "$VERSION" | grep -Eq '^[0-9]+\.[0-9]+(\.[0-9]+)?$' \
    || fail "$VERSION does not look like a version number, e.g. 1.3.0"

command -v gh >/dev/null 2>&1 \
    || fail "The GitHub CLI is not installed. Get the macOS installer from
  https://github.com/cli/cli/releases (gh_<version>_macOS_universal.pkg)."

gh auth status >/dev/null 2>&1 \
    || fail "The GitHub CLI is not logged in. Run: gh auth login"

CURRENT_BRANCH="$(git rev-parse --abbrev-ref HEAD)"
[ "$CURRENT_BRANCH" = "$BRANCH" ] \
    || fail "On branch $CURRENT_BRANCH. Releases come from $BRANCH — merge your pull request first, then: git checkout $BRANCH && git pull"

git diff --quiet && git diff --staged --quiet \
    || fail "You have uncommitted changes. A release must describe a commit that exists."

# The tag has to point at what is actually published, so main must match the
# remote before anything is built from it.
git fetch --quiet --tags origin "$BRANCH"
[ "$(git rev-parse HEAD)" = "$(git rev-parse "origin/$BRANCH")" ] \
    || fail "Your $BRANCH differs from origin/$BRANCH. Run: git pull --ff-only"

# An existing tag on this very commit means an earlier run got part way —
# publishing is several steps and any of them can fail. Carry on from there
# rather than making recovery a manual job.
RESUMING=0

if git rev-parse -q --verify "refs/tags/$TAG" >/dev/null; then
    [ "$(git rev-parse "$TAG^{commit}")" = "$(git rev-parse HEAD)" ] \
        || fail "$TAG already exists and points at a different commit. Pick a higher version."

    RESUMING=1
fi

# Joomla only offers an update when the published version is higher than the
# installed one, so a release below the last one would quietly reach nobody.
LATEST="$(latest_release)"
if [ -n "$LATEST" ]; then
    [ "$(printf '%s\n%s\n' "${LATEST#v}" "$VERSION" | sort -V | tail -1)" = "$VERSION" ] \
        || fail "$VERSION is not higher than the last release, $LATEST. Joomla would never offer it."
fi

# --- confirm ---------------------------------------------------------------

echo
echo "  Publishing  $VERSION   (last release: ${LATEST:-none yet})"
if [ "$RESUMING" = "1" ]; then
    echo "  Resuming    $TAG already exists on this commit"
fi
echo "  Tag         $TAG"
echo "  Commit      $(git log -1 --format='%h %s')"
echo
echo "  This publishes a public release. Sites will be offered it."
echo
# `|| true` so a Ctrl-D answers "no" rather than tripping set -e and exiting
# without saying why.
REPLY=""
read -r -p "  Continue? [y/N] " REPLY || true
[ "$REPLY" = "y" ] || [ "$REPLY" = "Y" ] || fail "Cancelled. Nothing was pushed."

# --- publish ---------------------------------------------------------------

echo
./build.sh "$VERSION"
ZIP="dist/com_footprint-$VERSION.zip"
[ -f "$ZIP" ] || fail "Expected $ZIP, which build.sh did not produce."

[ "$RESUMING" = "1" ] || git tag "$TAG"
git push --quiet origin "$TAG"
echo "✓ Tagged $TAG"

# The tag is pushed by now, so GitHub can write the release notes itself from
# the commits since the previous tag.
gh release create "$TAG" \
    --repo "$REPO" \
    --title "Footprint $VERSION" \
    --generate-notes \
    "$ZIP" \
    || fail "The release was not created. Nothing has been announced, so no
  site can see a half-made release. Fix the cause and run this again — it
  will resume from the tag that already exists."

echo "✓ Released, with $ZIP attached"

# updates.xml is what Joomla actually reads, served from main by
# raw.githubusercontent.com. Generated here rather than edited by hand so the
# version, the download URL and the checksum always describe the zip that was
# just uploaded — a mismatch between them is the usual reason an update either
# never appears or refuses to install.
SHA256="$(shasum -a 256 "$ZIP" | cut -d' ' -f1)"

cat > updates.xml <<XML
<?xml version="1.0" encoding="utf-8"?>
<updates>
    <update>
        <name>Footprint</name>
        <description>See what your Joomla extensions really weigh on your site</description>
        <element>com_footprint</element>
        <type>component</type>
        <client>administrator</client>
        <version>$VERSION</version>
        <infourl title="Footprint">https://github.com/$REPO/releases/tag/$TAG</infourl>
        <downloads>
            <downloadurl type="full" format="zip">https://github.com/$REPO/releases/download/$TAG/com_footprint-$VERSION.zip</downloadurl>
        </downloads>
        <tags>
            <tag>stable</tag>
        </tags>
        <maintainer>Janich Rasmussen</maintainer>
        <maintainerurl>https://github.com/$REPO</maintainerurl>
        <targetplatform name="joomla" version="6\.[0-9]+"/>
        <php_minimum>8.3</php_minimum>
        <sha256>$SHA256</sha256>
    </update>
</updates>
XML

git add updates.xml
git commit -q -m "Update server: Footprint $VERSION"

# Last step on purpose: until this lands, the release exists but no site is
# pointed at it, so nobody can be offered a download that is not there yet.
if git push --quiet origin "$BRANCH"; then
    echo "✓ Announced — updates.xml on $BRANCH now names $VERSION"
    echo
    echo "  Done. Joomla sites will see $VERSION within a few minutes."
else
    echo
    echo "✗ Could not push updates.xml to $BRANCH — branch protection, most likely."
    echo "  The release is already published; it is only the announcement that is stuck."
    echo "  Push it through a pull request instead:"
    echo
    echo "      git checkout -b updates-xml/$TAG && git push -u origin updates-xml/$TAG"
    echo
    echo "  then merge that branch into $BRANCH."
    exit 1
fi
