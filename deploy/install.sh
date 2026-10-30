#!/usr/bin/env bash
# Cloake Client & Services Manager — guarded install for cPanel (run in cPanel ▸ Terminal).
#
#   cd ~/cloake-manager/releases/cloake-manager-v0.9.0-build20261030.3
#   bash deploy/install.sh            # backs up DB + app, installs, verifies
#   bash deploy/install.sh --dry-run  # checks everything, changes nothing
#
# What it does, in order (stops at the first problem; nothing live changes until step 6):
#  1. Pre-flight: live folder, private config and PHP present; package complete; PHP syntax OK.
#  2. Database backup (mysqldump, read-only) to ~/cloake-manager/releases/ (outside public_html).
#  3. Builds the new release in a staging folder OUTSIDE public_html.
#  4. Carries over server-specific files from the live folder (.htaccess, .user.ini, php.ini,
#     .well-known/, anything else that is not an application file) so cPanel settings survive.
#  5. Syntax-checks the staged release with the server's PHP.
#  6. Swaps folders (two renames): live -> releases/previous-<stamp>, staging -> live.
#  7. Verifies the site answers with the new version; rolls back automatically if it doesn't.
# The database schema is NOT changed by this release.
set -euo pipefail

LIVE="${CM_LIVE:-/home/cloakecrea/public_html/manager-dev.cloakecreative.co.nz}"
RELEASES="${CM_RELEASES:-/home/cloakecrea/cloake-manager/releases}"
CONFIG="${CM_CONFIG:-/home/cloakecrea/cloake-manager/private/config.php}"
URL="${CM_URL:-https://manager-dev.cloakecreative.co.nz/}"
VERSION="0.9.0"; BUILD="20261030.3"
DRY=0; SKIP_DB=0
for a in "$@"; do case "$a" in --dry-run) DRY=1;; --skip-db-backup) SKIP_DB=1;; *) echo "Unknown option $a"; exit 2;; esac; done

PKG="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$PKG/public"
STAMP="$(date +%Y%m%d-%H%M%S)"
STAGE="$RELEASES/stage-v$VERSION-$STAMP"
PREV="$RELEASES/previous-$STAMP"
LOG="$RELEASES/install-$STAMP.log"
PHP="${CM_PHP:-$(command -v php || true)}"
# Files that belonged to earlier app versions and are retired (never carried over).
RETIRED=(m7-bootstrap.php assets/m5-work.js)

say() { printf '%s\n' "$*" | tee -a "$LOG"; }
die() { say "STOPPED: $*"; say "Nothing on the live site was changed by this step."; exit 1; }

mkdir -p "$RELEASES"; chmod 700 "$RELEASES"
say "== Cloake Manager install v$VERSION (build $BUILD) — $STAMP"
say "Package: $PKG"; say "Live:    $LIVE"; [ "$DRY" = 1 ] && say "(dry run — no changes will be made)"

# 1. Pre-flight
[ -d "$LIVE" ] || die "live folder not found: $LIVE"
[ -f "$CONFIG" ] || die "private config not found: $CONFIG (must exist outside public_html)"
[ -n "$PHP" ] || die "php not found on PATH (set CM_PHP=/path/to/php)"
say "PHP: $("$PHP" -r 'echo PHP_VERSION;')"
"$PHP" -r 'exit(version_compare(PHP_VERSION,"8.0.0",">=")?0:1);' || die "PHP 8.0+ required"
[ -f "$PKG/MANIFEST_SHA256.txt" ] || die "MANIFEST_SHA256.txt missing from package"
( cd "$PKG" && sha256sum -c --quiet MANIFEST_SHA256.txt ) >>"$LOG" 2>&1 || die "package files do not match MANIFEST_SHA256.txt (incomplete upload?)"
say "Package integrity: OK ($(grep -c . "$PKG/MANIFEST_SHA256.txt") files)"
grep -q "const CLOAKE_CONFIG_FILE = '$CONFIG';" "$SRC/inc/bootstrap.php" || [ -n "${CM_CONFIG:-}" ] || die "bootstrap config path does not match $CONFIG"
while IFS= read -r f; do "$PHP" -l "$f" >/dev/null 2>>"$LOG" || die "PHP syntax error in package file $f"; done < <(find "$SRC" -name '*.php')
say "Package PHP syntax: OK"
if [ -f "$LIVE/version.php" ]; then say "Currently installed: $(grep -oE "CLOAKE_(VERSION|BUILD) = '[^']+'" "$LIVE/version.php" | tr '\n' ' ')"; fi

# 2. Database backup (read-only)
if [ "$SKIP_DB" = 1 ]; then say "Database backup skipped (--skip-db-backup). Make sure you exported it in phpMyAdmin."
elif [ "$DRY" = 1 ]; then say "Would back up database to $RELEASES/db-before-v$VERSION-$STAMP.sql.gz"
else
  command -v mysqldump >/dev/null || die "mysqldump not available — export the database in phpMyAdmin, then rerun with --skip-db-backup"
  DEF="$(mktemp)"; chmod 600 "$DEF"
  "$PHP" -r '$c=require $argv[1]; printf("[client]\nuser=%s\npassword=\"%s\"\nhost=%s\n",$c["db_user"],addcslashes($c["db_password"],"\\\""),$c["db_host"]??"localhost");' "$CONFIG" > "$DEF"
  DBNAME="$("$PHP" -r '$c=require $argv[1]; echo $c["db_name"]??"cloakecrea_manager_devt";' "$CONFIG")"
  DUMP="$RELEASES/db-before-v$VERSION-$STAMP.sql.gz"
  if mysqldump --defaults-extra-file="$DEF" --single-transaction --no-tablespaces --skip-lock-tables "$DBNAME" 2>>"$LOG" | gzip > "$DUMP" && [ "$(gzip -cd "$DUMP" | grep -c 'CREATE TABLE')" -ge 10 ]; then
    rm -f "$DEF"; chmod 600 "$DUMP"; say "Database backup: $DUMP ($(du -h "$DUMP" | cut -f1))"
  else rm -f "$DEF" "$DUMP"; die "database backup failed (see $LOG). Export it in phpMyAdmin, then rerun with --skip-db-backup"; fi
fi

# 3–4. Stage the new release outside public_html and carry server-specific files
if [ "$DRY" = 1 ]; then say "Would stage into $STAGE"; STAGE="$(mktemp -d)"; fi
mkdir -p "$STAGE"; cp -a "$SRC/." "$STAGE/"
APP_FILES="$(cd "$SRC" && find . -type f | sed 's|^\./||')"
carried=0
while IFS= read -r f; do
  rel="${f#./}"
  case " ${RETIRED[*]} " in *" $rel "*) say "  retired (not carried): $rel"; continue;; esac
  if [ "$rel" = ".htaccess" ]; then cp -a "$LIVE/.htaccess" "$STAGE/.htaccess"; say "  kept live .htaccess (server rules preserved)"; carried=$((carried+1)); continue; fi
  if ! grep -qxF "$rel" <<<"$APP_FILES"; then mkdir -p "$STAGE/$(dirname "$rel")"; cp -a "$LIVE/$rel" "$STAGE/$rel"; say "  carried: $rel"; carried=$((carried+1)); fi
done < <(cd "$LIVE" && find . -type f)
# Make sure the root .htaccess still blocks sensitive file types even if the live one lacked it.
grep -q 'Require all denied' "$STAGE/.htaccess" || { cat "$SRC/.htaccess" >> "$STAGE/.htaccess"; say "  appended package deny rules to .htaccess"; }
find "$STAGE" -type d -exec chmod 755 {} +; find "$STAGE" -type f -exec chmod 644 {} +
say "Staged: $(find "$STAGE" -type f | wc -l) files ($carried carried from live)"

# 5. Syntax-check the staged release
while IFS= read -r f; do "$PHP" -l "$f" >/dev/null 2>>"$LOG" || die "PHP syntax error in staged file $f"; done < <(find "$STAGE" -name '*.php')
grep -q "CLOAKE_BUILD = '$BUILD'" "$STAGE/version.php" || die "staged version.php is not build $BUILD"
say "Staged release checks: OK"
if [ "$DRY" = 1 ]; then rm -rf "$STAGE"; say "Dry run complete — everything checks out. Run again without --dry-run to install."; exit 0; fi

# 6. Swap (two renames on the same filesystem)
mv "$LIVE" "$PREV"
mv "$STAGE" "$LIVE"
echo "$PREV" > "$RELEASES/LAST_PREVIOUS"
say "Swapped. Previous app kept at: $PREV"

# 7. Verify; automatic rollback if the new version doesn't answer
ok=0
if command -v curl >/dev/null; then
  for i in 1 2 3; do
    body="$(curl -ks --max-time 15 "$URL" || true)"
    if grep -q "v$VERSION · build $BUILD" <<<"$body"; then ok=1; break; fi
    sleep 2
  done
  if [ "$ok" = 1 ]; then say "Verified: $URL shows v$VERSION · build $BUILD"
  else
    say "VERIFY FAILED: $URL did not show the new version. Rolling back automatically."
    mv "$LIVE" "$RELEASES/failed-v$VERSION-$STAMP"; mv "$PREV" "$LIVE"
    say "Rolled back to the previous app. Failed release kept at $RELEASES/failed-v$VERSION-$STAMP for inspection."; exit 1
  fi
else say "curl not available — open $URL and check the sign-in page shows v$VERSION · build $BUILD"; fi
say "Done. Sign in, open More ▸ Release check (all checks should pass), then try: find a client, open it, Log work."
say "Undo at any time with: bash $PKG/deploy/rollback.sh"
