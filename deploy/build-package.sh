#!/usr/bin/env bash
# Build the single installation package from the committed source.
#   bash deploy/build-package.sh   ->  dist/cloake-manager-v<ver>-build<build>.zip
set -euo pipefail
cd "$(dirname "$0")/.."
VER=$(grep -oP "CLOAKE_VERSION = '\K[^']+" public/version.php)
BUILD=$(grep -oP "CLOAKE_BUILD = '\K[^']+" public/version.php)
NAME="cloake-manager-v$VER-build$BUILD${PKG_REV:+-$PKG_REV}"
OUT="dist/$NAME"
rm -rf "$OUT" "dist/$NAME.zip"; mkdir -p "$OUT"
git diff --quiet HEAD -- public deploy || { echo "Uncommitted changes in public/ or deploy/ — commit first."; exit 1; }
git archive HEAD public deploy | tar -x -C "$OUT"
rm -f "$OUT/deploy/build-package.sh"
for f in INSTALL.md CHANGELOG.md TEST_REPORT.md; do [ -f "$f" ] && cp "$f" "$OUT/"; done
chmod +x "$OUT"/deploy/*.sh
( cd "$OUT" && find . -type f ! -name MANIFEST_SHA256.txt | sed 's|^\./||' | LC_ALL=C sort | xargs sha256sum > MANIFEST_SHA256.txt )
echo "commit $(git rev-parse --short HEAD)" > "$OUT/SOURCE_COMMIT.txt"
( cd "$OUT" && sha256sum SOURCE_COMMIT.txt >> MANIFEST_SHA256.txt )
( cd dist && zip -qr -X "$NAME.zip" "$NAME" )
echo "Built dist/$NAME.zip ($(du -h "dist/$NAME.zip" | cut -f1), $(find "$OUT" -type f | wc -l) files, commit $(git rev-parse --short HEAD))"
