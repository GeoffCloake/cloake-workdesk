#!/usr/bin/env bash
# Cloake Client & Services Manager — guarded install for cPanel (run in cPanel ▸ Terminal).
#
#   cd ~/cloake-manager/releases/<package folder>
#   bash deploy/install.sh            # backs up DB + app, migrates DB if needed, installs, verifies
#   bash deploy/install.sh --dry-run  # checks everything, changes nothing
#
# What it does, in order (stops at the first problem; nothing live changes until step 6):
#  1. Pre-flight: live folder, private config and PHP present; package complete; PHP syntax OK.
#  2. Database backup (mysqldump, read-only) to ~/cloake-manager/releases/ (outside public_html).
#  2b. Applies pending database migrations (deploy/migrations/, add-only and verified; the
#      previous app version keeps working with the migrated database).
#  3. Builds the new release in a staging folder OUTSIDE public_html.
#  4. Carries over server-specific files from the live folder (.htaccess, .user.ini, php.ini,
#     .well-known/, anything else that is not an application file) so cPanel settings survive.
#  5. Syntax-checks the staged release with the server's PHP.
#  6. Swaps folders (two renames): live -> releases/previous-<stamp>, staging -> live.
#  7. Verifies the site answers with the new version; rolls back automatically if it doesn't.
set -euo pipefail

LIVE="${CM_LIVE:-/home/cloakecrea/public_html/manager-dev.cloakecreative.co.nz}"
RELEASES="${CM_RELEASES:-/home/cloakecrea/cloake-manager/releases}"
CONFIG="${CM_CONFIG:-/home/cloakecrea/cloake-manager/private/config.php}"
URL="${CM_URL:-https://manager-dev.cloakecreative.co.nz/}"
DRY=0; SKIP_DB=0
for a in "$@"; do case "$a" in --dry-run) DRY=1;; --skip-db-backup) SKIP_DB=1;; *) echo "Unknown option $a"; exit 2;; esac; done

PKG="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$PKG/public"
VERSION="$(grep -oE "CLOAKE_VERSION = '[^']+'" "$SRC/version.php" | cut -d"'" -f2)"
BUILD="$(grep -oE "CLOAKE_BUILD = '[^']+'" "$SRC/version.php" | cut -d"'" -f2)"
[ -n "$VERSION" ] && [ -n "$BUILD" ] || { echo "Cannot read version from $SRC/version.php"; exit 1; }
STAMP="$(date +%Y%m%d-%H%M%S)"
STAGE="$RELEASES/stage-v$VERSION-$STAMP"
PREV="$RELEASES/previous-$STAMP"
LOG="$RELEASES/install-$STAMP.log"
# The CLI "php" on cPanel is often older than the site's PHP; fall back to cPanel's EA PHP 8 builds.
pick_php() {
  if [ -n "${CM_PHP:-}" ]; then echo "$CM_PHP"; return; fi
  local p; p="$(command -v php || true)"
  if [ -n "$p" ] && "$p" -r 'exit(version_compare(PHP_VERSION,"8.0.0",">=")?0:1);' 2>/dev/null; then echo "$p"; return; fi
  ls -d /opt/cpanel/ea-php8*/root/usr/bin/php 2>/dev/null | sort -V | tail -1
}
PHP="$(pick_php)"
# Files that belonged to earlier app versions and are retired (never carried over).
RETIRED=(m7-bootstrap.php assets/m5-work.js)

say() { printf '%s\n' "$*" | tee -a "$LOG"; }
die() { say "STOPPED: $*"; say "Nothing on the live site was changed by this step."; exit 1; }

mkdir -p "$RELEASES"; chmod 700 "$RELEASES"
# Work files live here. Never use <(...) process substitution: it needs /dev/fd, which jailed
# cPanel shells (CageFS) do not have — loops then silently run zero times.
TMPD="$(mktemp -d "$RELEASES/.install-tmp-XXXXXX")"; trap 'rm -rf "$TMPD"' EXIT
# Syntax-check with every PHP 8 the server offers (oldest first) as well as the chosen one,
# so code that only runs on newer PHP cannot slip through.
LINT_PHPS=()
lint_dir() { # <dir> <label>  — fails unless every file was checked by every PHP
  local list="$TMPD/lint.lst" n=0 p f files
  find "$1" -name '*.php' > "$list"; files=$(grep -c . "$list" || true)
  [ "$files" -ge 10 ] || die "syntax check found only $files PHP files in $2 (expected 20+)"
  for p in "${LINT_PHPS[@]}"; do
    while IFS= read -r f; do "$p" -l "$f" >/dev/null 2>>"$LOG" || die "PHP $("$p" -r 'echo PHP_VERSION;') syntax error in $2 file ${f#"$1"/}"; n=$((n+1)); done < "$list"
  done
  [ "$n" -eq $((files * ${#LINT_PHPS[@]})) ] || die "syntax check incomplete for $2 ($n of $((files * ${#LINT_PHPS[@]})) checks ran)"
  say "$2 PHP syntax: OK ($files files × ${#LINT_PHPS[@]} PHP versions)"
}
say "== Cloake Manager install v$VERSION (build $BUILD) — $STAMP"
say "Package: $PKG"; say "Live:    $LIVE"; [ "$DRY" = 1 ] && say "(dry run — no changes will be made)"

# 1. Pre-flight
[ -d "$LIVE" ] || die "live folder not found: $LIVE"
[ -f "$CONFIG" ] || die "private config not found: $CONFIG (must exist outside public_html)"
# Use the same PHP as the website (cPanel MultiPHP handler in the live .htaccess, e.g. ea-php83) when it is installed.
SITE_PHP_EARLY="$(grep -oE 'ea-php[0-9]{2}' "$LIVE/.htaccess" 2>/dev/null | tail -1 || true)"
if [ -z "${CM_PHP:-}" ] && [ -n "$SITE_PHP_EARLY" ] && [ -x "/opt/cpanel/$SITE_PHP_EARLY/root/usr/bin/php" ] \
   && "/opt/cpanel/$SITE_PHP_EARLY/root/usr/bin/php" -r 'exit(version_compare(PHP_VERSION,"8.0.0",">=")?0:1);' 2>/dev/null; then
  PHP="/opt/cpanel/$SITE_PHP_EARLY/root/usr/bin/php"
fi
[ -n "$PHP" ] || die "php not found on PATH (set CM_PHP=/path/to/php)"
say "PHP: $("$PHP" -r 'echo PHP_VERSION;') ($PHP — same as the website when available)"
"$PHP" -r 'exit(version_compare(PHP_VERSION,"8.0.0",">=")?0:1);' || die "PHP 8.0+ required (set CM_PHP=/opt/cpanel/ea-php8X/root/usr/bin/php)"
LINT_PHPS=("$PHP")
# Extra PHP 8 binaries for the syntax check. Each must itself report PHP 8+ (on some cPanel servers a path under
# ea-php8* resolves to the system default, e.g. 7.4, which would wrongly reject PHP 8 syntax). Same version only once.
LINT_SEEN=" $("$PHP" -r 'echo PHP_VERSION;') "
for p in $(ls -d /opt/cpanel/ea-php8*/root/usr/bin/php 2>/dev/null | sort -V); do
  [ "$p" != "$PHP" ] || continue
  v="$("$p" -r 'echo version_compare(PHP_VERSION,"8.0.0",">=") ? PHP_VERSION : "";' 2>/dev/null || true)"
  if [ -z "$v" ]; then echo "  lint: skipped $p (not PHP 8)" >>"$LOG"; continue; fi
  case "$LINT_SEEN" in *" $v "*) continue ;; esac
  LINT_SEEN="$LINT_SEEN$v "; LINT_PHPS+=("$p")
done
say "Syntax check will use PHP:$LINT_SEEN"
[ -f "$PKG/MANIFEST_SHA256.txt" ] || die "MANIFEST_SHA256.txt missing from package"
( cd "$PKG" && sha256sum -c --quiet MANIFEST_SHA256.txt ) >>"$LOG" 2>&1 || die "package files do not match MANIFEST_SHA256.txt (incomplete upload?)"
say "Package integrity: OK ($(grep -c . "$PKG/MANIFEST_SHA256.txt") files)"
grep -q "const CLOAKE_CONFIG_FILE = '$CONFIG';" "$SRC/inc/bootstrap.php" || [ -n "${CM_CONFIG:-}" ] || die "bootstrap config path does not match $CONFIG"
lint_dir "$SRC" "Package"
# The website's PHP is chosen by cPanel MultiPHP (handler line in .htaccess), not the CLI php above.
SITE_PHP="$(grep -oE 'ea-php[0-9]{2}' "$LIVE/.htaccess" 2>/dev/null | tail -1 || true)"
if [ -n "$SITE_PHP" ]; then
  SITE_NUM="${SITE_PHP#ea-php}"
  [ "$SITE_NUM" -ge 80 ] || die "this website runs PHP ${SITE_NUM:0:1}.${SITE_NUM:1} ($SITE_PHP). In cPanel > MultiPHP Manager set manager-dev to ea-php83, then rerun."
  say "Website PHP: $SITE_PHP (MultiPHP)"
else
  say "Website PHP: server default (no MultiPHP handler in .htaccess). If it is below 8.0 the app shows a clear message and the install rolls back."
fi
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

# 2b. Database migrations (add-only, verified). Old app versions keep working after this.
set +e; MIG_OUT="$(CM_CONFIG="$CONFIG" "$PHP" "$PKG/deploy/migrate.php" status 2>&1)"; MIG_RC=$?; set -e
printf '%s\n' "$MIG_OUT" | sed 's/^/  /' | tee -a "$LOG" >/dev/null
if [ "$MIG_RC" = 10 ]; then
  if [ "$DRY" = 1 ]; then say "Database: migration pending — would apply:"; printf '%s\n' "$MIG_OUT" | grep -E 'PENDING|not recorded' | sed 's/^ */    /' | tee -a "$LOG"
  else
    say "Database: applying migration (add-only: new columns/tables; existing data unchanged)…"
    set +e; CM_CONFIG="$CONFIG" "$PHP" "$PKG/deploy/migrate.php" apply 2>&1 | tee -a "$LOG" | sed 's/^/  /'; MIG_RC=${PIPESTATUS[0]}; set -e
    case "$MIG_RC" in
      0) say "Database migration: OK" ;;
      3) PENDING_SQL="$(printf '%s\n' "$MIG_OUT" | grep -oE '[0-9]{3}_[a-z_]+ — (PENDING|columns present, not recorded)' | cut -d' ' -f1 | sed "s|^|    $PKG/deploy/migrations/|; s|\$|.apply.sql|")"
         say "The app's database user may not change table structure. In phpMyAdmin: select the database, open the SQL tab, and run the contents of each file below ONCE, in this order:"
         printf '%s\n' "$PENDING_SQL" | tee -a "$LOG"
         die "then rerun this installer." ;;
      *) die "database migration failed (see $LOG). The live app is unchanged and still works. Send me the log." ;;
    esac
  fi
elif [ "$MIG_RC" = 0 ]; then say "Database: schema up to date"
else die "could not check the database schema (see $LOG)"; fi

# 3–4. Stage the new release outside public_html and carry server-specific files
if [ "$DRY" = 1 ]; then say "Would stage into $STAGE"; STAGE="$(mktemp -d)"; fi
mkdir -p "$STAGE"; cp -a "$SRC/." "$STAGE/"
APP_FILES="$(cd "$SRC" && find . -type f | sed 's|^\./||')"
carried=0
(cd "$LIVE" && find . -type f) > "$TMPD/live.lst"
# Recreate the live folder's directory structure too (e.g. an empty .well-known/acme-challenge for AutoSSL).
(cd "$LIVE" && find . -mindepth 1 -type d) > "$TMPD/live-dirs.lst"
while IFS= read -r d; do mkdir -p "$STAGE/${d#./}"; done < "$TMPD/live-dirs.lst"
livecount=$(grep -c . "$TMPD/live.lst" || true)
[ "$livecount" -ge 1 ] && grep -qxF './index.php' "$TMPD/live.lst" || die "could not list the live folder's files ($livecount found) — refusing to continue"
while IFS= read -r f; do
  rel="${f#./}"
  case " ${RETIRED[*]} " in *" $rel "*) say "  retired (not carried): $rel"; continue;; esac
  if [ "$rel" = ".htaccess" ]; then cp -a "$LIVE/.htaccess" "$STAGE/.htaccess"; say "  kept live .htaccess (server rules preserved)"; carried=$((carried+1)); continue; fi
  if ! grep -qxF "$rel" <<<"$APP_FILES"; then mkdir -p "$STAGE/$(dirname "$rel")"; cp -a "$LIVE/$rel" "$STAGE/$rel"; say "  carried: $rel"; carried=$((carried+1)); fi
done < "$TMPD/live.lst"
if [ -f "$LIVE/.htaccess" ]; then cmp -s "$LIVE/.htaccess" "$STAGE/.htaccess" || die "live .htaccess was not preserved"; fi
for d in .well-known; do [ -d "$LIVE/$d" ] && { [ -d "$STAGE/$d" ] || die "$d was not carried over"; }; done
say "Checked $livecount live files; $carried server-specific files carried over"
# Make sure the root .htaccess still blocks sensitive file types even if the live one lacked it.
grep -q 'Require all denied' "$STAGE/.htaccess" || { cat "$SRC/.htaccess" >> "$STAGE/.htaccess"; say "  appended package deny rules to .htaccess"; }
find "$STAGE" -type d -exec chmod 755 {} +; find "$STAGE" -type f -exec chmod 644 {} +
say "Staged: $(find "$STAGE" -type f | wc -l) files ($carried carried from live)"

# 5. Syntax-check the staged release
lint_dir "$STAGE" "Staged release"
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
  # PHP path/opcode caches can serve the old folder for a few seconds after the swap: keep checking up to ~30 s.
  for i in 1 2 3 4 5 6 7 8 9 10; do
    body="$(curl -ks --max-time 15 "$URL" || true)"
    if grep -q "v$VERSION · build $BUILD" <<<"$body"; then ok=1; break; fi
    sleep 3
  done
  if [ "$ok" = 1 ]; then say "Verified: $URL shows v$VERSION · build $BUILD"
  else
    say "VERIFY FAILED: $URL did not show the new version. Rolling back automatically."
    mv "$LIVE" "$RELEASES/failed-v$VERSION-$STAMP"; mv "$PREV" "$LIVE"; rm -f "$RELEASES/LAST_PREVIOUS"
    say "Rolled back to the previous app. Failed release kept at $RELEASES/failed-v$VERSION-$STAMP for inspection."; exit 1
  fi
else say "curl not available — open $URL and check the sign-in page shows v$VERSION · build $BUILD"; fi
say "Done. Sign in, open More ▸ Release check (all checks should pass), then try: find a client, open it, Log work."
say "Undo at any time with: bash $PKG/deploy/rollback.sh"
