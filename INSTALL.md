# Install v0.10.0 (build 20261031.1) on manager-dev — and how to undo it

**What changes:** application files in `public_html/manager-dev.cloakecreative.co.nz/`, plus **database migration 001**: 26 new columns on `clients` and `contacts`, filled from the original imported records. No existing column or value is changed.
**What does not change:** your data, `private/config.php`, cPanel/PHP settings, anything outside the staging site.
Allow 10 minutes. The site is unavailable for under a second during the folder swap.

Proven on the 31 Oct install: use **WHM Terminal → `su -s /bin/bash - cloakecrea`** first, and upload to `/home/cloakecrea/cloake-manager/releases/` (File Manager tends to reopen the live site folder — check the path before uploading).

---

## Steps (cPanel Terminal, as cloakecrea)

1. **Upload** `cloake-manager-v0.10.0-build20261031.1.zip` to `/home/cloakecrea/cloake-manager/releases/`.
2. **Unpack and dry-run** (changes nothing):
   ```bash
   cd ~/cloake-manager/releases
   unzip -q cloake-manager-v0.10.0-build20261031.1.zip
   cd cloake-manager-v0.10.0-build20261031.1
   bash deploy/install.sh --dry-run
   ```
   Expect: `Website PHP: ea-php83`, `Currently installed: … '0.9.0'`, `Database: migration pending — would apply: 001_editable_details`, the same `carried:` lines as last time, and `Dry run complete`.
3. **Install:** `bash deploy/install.sh`
   It backs up the database → applies the migration → stages, swaps and verifies (auto-rollback if the site doesn't answer with the new version).
   - **If it stops with "the app's database user may not change table structure":** nothing has changed. Open **phpMyAdmin** → select `cloakecrea_manager_devt` → **SQL** tab → paste the whole contents of `deploy/migrations/001_editable_details.apply.sql` (open it in File Manager ▸ Edit to copy) → **Go**. Then run `bash deploy/install.sh` again; it will report `Database: schema up to date` and continue.
   - If the database backup fails: export in phpMyAdmin (Export ▸ Quick ▸ SQL), then `bash deploy/install.sh --skip-db-backup`.
4. **Check (3 minutes):** sign in → header shows `v0.10.0 · build 20261031.1` → **Release check**: "All checks passed", including *Migration 001* → open a client → **Edit** → change something small → Save → the change shows.

## Undo
- **App only (normal undo):** `bash deploy/rollback.sh` — puts v0.9.0 back. The database stays migrated; v0.9.0 works with it (tested), and edits made in v0.10.0 are kept for when you reinstall.
- **Database migration (only if the migration itself went wrong):** `php deploy/migrate.php rollback --confirm` (use `/opt/cpanel/ea-php83/root/usr/bin/php` if `php` is 7.4), or paste `deploy/migrations/001_editable_details.rollback.sql` in phpMyAdmin. This drops the added columns **and anything typed into them since**. To restore everything exactly as it was, import the `db-before-v0.10.0-….sql.gz` backup instead.

## Files
`public/` web root · `deploy/install.sh`, `rollback.sh`, `migrate.php`, `migrations/` · `CHANGELOG.md` · `TEST_REPORT.md` · `MANIFEST_SHA256.txt` · `SOURCE_COMMIT.txt`
