#!/usr/bin/env bash
# Install (or remove) the nightly email inventory for Cloake Workdesk. RUN AS ROOT (WHM Terminal, before `su`).
#   bash ~cloakecrea/cloake-manager/releases/<installed release>/deploy/install-email-inventory.sh   install + run once now
#   bash …/deploy/install-email-inventory.sh --remove   remove the job and the copy
# The script is COPIED to a root-owned place, so nothing in the cloakecrea account can change what root runs.
# It reads mailboxes, forwarders and domains with cPanel's uapi (no passwords) and writes one file into
# ~cloakecrea/cloake-manager/private/ for Workdesk to read.
set -euo pipefail
[ "$(id -u)" = 0 ] || { echo "Run this as root (WHM Terminal, before su)."; exit 1; }
DEST=/usr/local/sbin/cm-email-inventory
CRON=/etc/cron.d/cm-email-inventory
if [ "${1:-}" = "--remove" ]; then
  rm -f "$DEST" "$CRON"; echo "Removed $DEST and $CRON. (Workdesk's last file stays until you delete ~cloakecrea/cloake-manager/private/email-inventory.json.)"; exit 0
fi
SRC="$(cd "$(dirname "$0")" && pwd)/email-inventory.php"
[ -f "$SRC" ] || { echo "Not found: $SRC"; exit 1; }
PHP=/usr/local/cpanel/3rdparty/bin/php; [ -x "$PHP" ] || PHP="$(command -v php)"
"$PHP" -l "$SRC" >/dev/null || { echo "Syntax check failed — nothing installed."; exit 1; }
{ echo "#!$PHP"; cat "$SRC"; } > "$DEST.new"
chown root:root "$DEST.new"; chmod 700 "$DEST.new"; mv "$DEST.new" "$DEST"
printf '# Cloake Workdesk: nightly email inventory (mailboxes/forwarders, no passwords)\n17 2 * * * root %s >/dev/null 2>&1\n' "$DEST" > "$CRON"
chmod 644 "$CRON"
echo "Installed $DEST (root only) and the nightly job $CRON (02:17)."
"$DEST"
