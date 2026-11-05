#!/usr/bin/env bash
# cm-update — install a Cloake Workdesk release straight from GitHub (run on the server as cloakecrea).
#   cm-update            list the releases available on GitHub and what is installed
#   cm-update v0.15.0    fetch release/v0.15.0, build the package, dry-run, ask, then install
#   cm-update v0.15.0 --yes   the same without the question
# It only READS from GitHub (read-only deploy key). The install itself is deploy/install.sh,
# exactly as with uploaded zips: database backup, migration, verify, automatic rollback on failure.
set -euo pipefail
REPO="$HOME/cloake-manager/repo"
RELEASES="$HOME/cloake-manager/releases"
[ -d "$REPO/.git" ] || { echo "No repository at $REPO — run the one-off setup first."; exit 1; }
cd "$REPO"
git fetch --quiet --prune origin '+refs/heads/release/*:refs/remotes/origin/release/*' \
  || { echo "Could not reach GitHub (check the deploy key / network). Nothing changed."; exit 1; }
LIVE="$HOME/public_html/manager-dev.cloakecreative.co.nz/version.php"
CUR=$(grep -oP "CLOAKE_VERSION = '\K[^']+" "$LIVE" 2>/dev/null || echo unknown)
V="${1:-}"
if [ -z "$V" ]; then
  echo "Installed now: v$CUR"
  echo "Releases on GitHub:"
  git for-each-ref --sort=-creatordate --format='  %(refname:lstrip=4)   commit %(objectname:short)   %(creatordate:short)' refs/remotes/origin/release/
  echo "Install one with:  cm-update vX.Y.Z"
  exit 0
fi
[[ "$V" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "Give the version like this: cm-update v0.15.0"; exit 1; }
REF="refs/remotes/origin/release/$V"
git rev-parse -q --verify "$REF" >/dev/null || { echo "There is no release $V on GitHub. Run cm-update with no version to list them."; exit 1; }
COMMIT=$(git rev-parse "$REF")
VER=$(git show "$COMMIT:public/version.php" | grep -oP "CLOAKE_VERSION = '\K[^']+")
BUILD=$(git show "$COMMIT:public/version.php" | grep -oP "CLOAKE_BUILD = '\K[^']+")
[ "v$VER" = "$V" ] || { echo "Release branch $V contains version $VER — refusing (mislabelled release)."; exit 1; }
NAME="cloake-workdesk-v$VER-build$BUILD"
OUT="$RELEASES/$NAME"
mkdir -p "$RELEASES"
if [ ! -f "$OUT/MANIFEST_SHA256.txt" ]; then
  rm -rf "$OUT"; mkdir -p "$OUT"
  git archive "$COMMIT" public deploy | tar -x -C "$OUT"
  for f in INSTALL.md CHANGELOG.md TEST_REPORT.md; do git show "$COMMIT:$f" > "$OUT/$f" 2>/dev/null || rm -f "$OUT/$f"; done
  chmod +x "$OUT"/deploy/*.sh
  ( cd "$OUT" && find . -type f ! -name MANIFEST_SHA256.txt | sed 's|^\./||' | LC_ALL=C sort | xargs sha256sum > MANIFEST_SHA256.txt )
  echo "commit ${COMMIT:0:7}" > "$OUT/SOURCE_COMMIT.txt"
  ( cd "$OUT" && sha256sum SOURCE_COMMIT.txt >> MANIFEST_SHA256.txt )
fi
echo "Installed now: v$CUR    Release: $V (build $BUILD, commit ${COMMIT:0:7})"
echo "---- Dry run (changes nothing) ----"
cd "$OUT"
bash deploy/install.sh --dry-run </dev/null
echo "-----------------------------------"
# v0.20.1: read the answer from the keyboard (not from anything piped in), ignore spaces/case; --yes skips the question
if [ "${2:-}" = "--yes" ]; then ANSWER=y
else
  ANSWER=""
  if [ -r /dev/tty ]; then read -r -p "Install $V now? Type y to install, anything else to stop: " ANSWER </dev/tty || ANSWER=""
  else read -r -p "Install $V now? Type y to install, anything else to stop: " ANSWER || ANSWER=""; fi
  ANSWER="$(printf '%s' "$ANSWER" | tr -d '[:space:]' | tr '[:upper:]' '[:lower:]')"
fi
[ "$ANSWER" = "y" ] || [ "$ANSWER" = "yes" ] || { echo "Stopped. Nothing installed. (To install without the question: cm-update $V --yes)"; exit 0; }
bash deploy/install.sh
