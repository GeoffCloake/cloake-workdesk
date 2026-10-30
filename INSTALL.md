# Install v0.9.0 (build 20261030.3) on manager-dev — and how to undo it

**What changes:** application files in `public_html/manager-dev.cloakecreative.co.nz/` only.
**What does not change:** the database schema, your data, `/home/cloakecrea/cloake-manager/private/config.php`, cPanel/PHP settings, anything outside the staging site.
Allow 10 minutes. The site is unavailable for well under a second during the folder swap.

---

## Option A — cPanel Terminal (recommended: backs up, checks, verifies and auto-rolls-back)

1. **Upload** `cloake-manager-v0.9.0-build20261030.3.zip` with File Manager to
   `/home/cloakecrea/cloake-manager/releases/` (create `releases` if needed; it is outside `public_html`, so nothing in it is web-visible).
2. Open **cPanel ▸ Terminal** and run:
   ```bash
   cd ~/cloake-manager/releases
   unzip -q cloake-manager-v0.9.0-build20261030.3.zip
   cd cloake-manager-v0.9.0-build20261030.3
   bash deploy/install.sh --dry-run     # checks only — changes nothing
   bash deploy/install.sh               # the real install
   ```
   The script, in order: verifies the package against `MANIFEST_SHA256.txt` and PHP syntax with the server's PHP → **backs up the database** (read-only `mysqldump` using the credentials already in your private config) to `~/cloake-manager/releases/db-before-v0.9.0-<time>.sql.gz` → stages the new version outside `public_html` → **keeps your live `.htaccess`, `.user.ini`, `php.ini`, `.well-known/` and any other server files** → swaps folders → checks `https://manager-dev.cloakecreative.co.nz/` shows `v0.9.0 · build 20261030.3`. If that check fails it **puts the previous version back automatically**.
   - If the database backup step says `mysqldump` is unavailable or fails: export the database in phpMyAdmin (Export ▸ Quick ▸ SQL) and run `bash deploy/install.sh --skip-db-backup`.
3. **Check it** (2 minutes): sign in → the header shows `v0.9.0 · build 20261030.3` → **More ▸ Release check** says "All checks passed" → type part of a client's name on Today → open the client → tap ＋ Log work → save a 15-minute test entry.

### Undo (Option A)
```bash
bash ~/cloake-manager/releases/cloake-manager-v0.9.0-build20261030.3/deploy/rollback.sh
```
This swaps the previous application folder back (it was kept intact at `~/cloake-manager/releases/previous-<time>/`). The database is not touched: any entries you made while v0.9.0 was live stay and remain valid in the previous version. Restoring the database itself is only needed if something went wrong with data — import the `db-before-…sql.gz` file in phpMyAdmin.

---

## Option B — File Manager only (if Terminal is not available)

1. **Back up the database:** phpMyAdmin ▸ `cloakecrea_manager_devt` ▸ Export ▸ Quick ▸ SQL. Keep the file.
2. **Back up the app:** in File Manager, open `public_html`, right-click `manager-dev.cloakecreative.co.nz` ▸ **Compress** ▸ Zip ▸ save as `manager-dev-before-v0.9.0.zip`. Move that zip to `/home/cloakecrea/cloake-manager/releases/`.
3. Upload and extract `cloake-manager-v0.9.0-build20261030.3.zip` in `/home/cloakecrea/cloake-manager/releases/`.
4. From the extracted `public/` folder, **copy these into** `public_html/manager-dev.cloakecreative.co.nz/`, overwriting:
   `index.php`, `version.php`, `lookup.php`, `add-work.php`, `add-enquiry.php`, `convert-enquiry.php`, `edit-task.php`, `edit-enquiry.php`, the whole `inc/` folder (including its hidden `.htaccess` — turn on *Settings ▸ Show Hidden Files*), and `assets/app.css`, `assets/app.js`.
   **Do not** copy the package's root `.htaccess` over yours (yours may contain cPanel PHP-handler lines). Leave `.user.ini`, `php.ini`, `.well-known` and `error_log` alone.
5. **Delete** the two retired files: `m7-bootstrap.php` and `assets/m5-work.js`.
6. Check as in Option A step 3. Also open `https://manager-dev.cloakecreative.co.nz/inc/bootstrap.php` — it must **not** show anything (403 or 404 is correct).

### Undo (Option B)
Delete the contents of `manager-dev.cloakecreative.co.nz/`, then extract `manager-dev-before-v0.9.0.zip` back into `public_html`. Database untouched.

---

## Files in this package
- `public/` — the complete web root for v0.9.0 (deploy target).
- `deploy/install.sh`, `deploy/rollback.sh` — guarded install and undo (tested here against a simulated copy of your cPanel layout, including a deliberately failed deploy).
- `CHANGELOG.md` — what changed and what deliberately did not.
- `TEST_REPORT.md` — what was tested, how, on which databases, and what was **not** tested.
- `MANIFEST_SHA256.txt` — checksums of every file; `SOURCE_COMMIT.txt` — the source commit this was built from.

## Requirements
PHP 8.0+ with `pdo_mysql`, `mbstring`, `json` (already in use by v0.8.x). MySQL 8.0 or MariaDB 10.6+ (both tested).
