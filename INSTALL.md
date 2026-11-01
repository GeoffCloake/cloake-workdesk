# Install v0.11.0 (build 20261101.1) on manager-dev — and how to undo it

**What changes:** application files in `public_html/manager-dev.cloakecreative.co.nz/`, plus **database migration 003**: 9 new columns on `enquiries` and `tasks` (received date, contact, category/type, duplicate/withdrawn reason, task sign-off). No existing column or value is changed.
**What does not change:** your data, `private/config.php`, cPanel/PHP settings, anything outside the staging site.
Allow 10 minutes. The site is unavailable for under a second during the folder swap.

Proven on the 31 Oct and 1 Nov installs: use **WHM Terminal → `su -s /bin/bash - cloakecrea`** first (the prompt must read `[cloakecrea@server …]$`, not `root`). File Manager uploads tend to land in the live site folder; step 2 moves the zip wherever it landed.

---

## Steps (WHM Terminal, as cloakecrea)

1. **Upload** `cloake-manager-v0.11.0-build20261101.1.zip` in File Manager (any folder).
2. **Move, unpack and dry-run** (changes nothing):
   ```bash
   find ~/public_html ~/cloake-manager -maxdepth 3 -name 'cloake-manager-v0.11.0-*.zip' -not -path '*/cloake-manager/releases/*' -exec mv {} ~/cloake-manager/releases/ \;
   cd ~/cloake-manager/releases
   unzip -q cloake-manager-v0.11.0-build20261101.1.zip
   cd cloake-manager-v0.11.0-build20261101.1
   bash deploy/install.sh --dry-run
   ```
   Expect: `PHP: 8.3.x (/opt/cpanel/ea-php83/…)`, `Currently installed: … '0.10.0'`, `Database: migration pending — would apply:` **003_enquiry_signoff**, the same `carried:` lines as last time, and `Dry run complete`.
3. **Install:** `bash deploy/install.sh`
   It backs up the database → applies migration 003 → stages, swaps and verifies (automatic rollback if the site doesn't answer with the new version).
   - **If it stops with "the app's database user may not change table structure":** nothing has changed. Open **phpMyAdmin** → select `cloakecrea_manager_devt` → **SQL** tab → paste the whole of `deploy/migrations/003_enquiry_signoff.apply.sql` (open it in File Manager ▸ Edit, copy all) → **Go**. Then run `bash deploy/install.sh` again; it reports `Database: schema up to date` and continues.
   - If the database backup fails: export in phpMyAdmin (Export ▸ Quick ▸ SQL), then `bash deploy/install.sh --skip-db-backup`.
4. **Check (3 minutes):** header shows `v0.11.0 · build 20261101.1` → **More ▸ Release check**: "All checks passed", including *Migration 003* → **Enquiries**: open one, **Edit**, change something small, Save → the change shows and an **Undo** button appears.

## Undo
- **App only (normal undo):** `bash deploy/rollback.sh` — puts v0.10.0 back. The database stays migrated; v0.10.0 works with it (tested). Received dates, contacts and sign-offs entered in v0.11.0 are kept for when you reinstall.
- **Database migration (only if migration 003 itself went wrong):** `/opt/cpanel/ea-php83/root/usr/bin/php deploy/migrate.php rollback --confirm` — this now undoes **only 003** (001 and 002 stay). Or run `003_enquiry_signoff.rollback.sql` in phpMyAdmin. This drops the 9 new columns **and anything typed into them since**. To restore everything exactly as it was, import the `db-before-v0.11.0-….sql.gz` backup instead.

## Files
`public/` web root · `deploy/install.sh`, `rollback.sh`, `migrate.php`, `migrations/` · `CHANGELOG.md` · `TEST_REPORT.md` · `MANIFEST_SHA256.txt` · `SOURCE_COMMIT.txt`
