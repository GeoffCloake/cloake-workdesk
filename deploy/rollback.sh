#!/usr/bin/env bash
# Cloake Client & Services Manager — roll back the application files to the
# version that was live before the last install.sh run.
#   bash deploy/rollback.sh
# Application files only. The database is NOT touched (this release made no schema
# changes; entries created while v0.9.0 was live remain valid for the previous version).
set -euo pipefail
LIVE="${CM_LIVE:-/home/cloakecrea/public_html/manager-dev.cloakecreative.co.nz}"
RELEASES="${CM_RELEASES:-/home/cloakecrea/cloake-manager/releases}"
URL="${CM_URL:-https://manager-dev.cloakecreative.co.nz/}"
STAMP="$(date +%Y%m%d-%H%M%S)"
PREV="$(cat "$RELEASES/LAST_PREVIOUS" 2>/dev/null || true)"
[ -n "$PREV" ] && [ -d "$PREV" ] || { echo "No previous release recorded in $RELEASES/LAST_PREVIOUS — nothing to roll back to."; exit 1; }
[ -d "$LIVE" ] || { echo "Live folder missing: $LIVE"; exit 1; }
echo "Rolling back:"; echo "  current  -> $RELEASES/rolledback-$STAMP"; echo "  restore  <- $PREV"
grep -oE "CLOAKE_(VERSION|BUILD) = '[^']+'" "$PREV/version.php" 2>/dev/null | sed 's/^/  restoring /' || true
mv "$LIVE" "$RELEASES/rolledback-$STAMP"
mv "$PREV" "$LIVE"
rm -f "$RELEASES/LAST_PREVIOUS"
echo "Rolled back. The removed release is kept at $RELEASES/rolledback-$STAMP"
if command -v curl >/dev/null; then curl -ks --max-time 15 "$URL" | grep -oE 'v[0-9.]+ ?(<span>)?· build [0-9.]+' | head -1 | sed 's/^/Site now shows: /' || echo "Open $URL to confirm."; fi
