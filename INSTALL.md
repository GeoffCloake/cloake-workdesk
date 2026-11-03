# Install v0.14.0 (build 20261103.3) on manager-dev — and how to undo it

**What changes:** application files in `public_html/manager-dev.cloakecreative.co.nz/`, plus **database migration 006**: two new columns on `tasks` (`scheduled_on`, `scheduled_minutes`) and two new tables (`staff`, `job_items`). It fills `staff` from the names used in "Action for". No existing column or value is changed; your services, invoices and WHMCS archive are untouched.
**What does not change:** your data, `private/config.php`, cPanel/PHP settings, Xero, WHMCS (offline).
Allow 10 minutes. No import this time.

As before: **WHM Terminal → `su -s /bin/bash - cloakecrea`** first (the prompt must read `[cloakecrea@server …]$`).

---

## Steps (WHM Terminal, as cloakecrea)

1. **Upload** `cloake-manager-v0.14.0-build20261103.3.zip` in File Manager (any folder).
2. **Move, unpack and dry-run** (changes nothing):
   ```bash
   find ~/public_html ~/cloake-manager -maxdepth 3 -name 'cloake-manager-v0.14.0-*.zip' -not -path '*/cloake-manager/releases/*' -exec mv {} ~/cloake-manager/releases/ \;
   cd ~/cloake-manager/releases
   unzip -q cloake-manager-v0.14.0-build20261103.3.zip
   cd cloake-manager-v0.14.0-build20261103.3
   bash deploy/install.sh --dry-run
   ```
   Expect: `Currently installed: … '0.13.0'`, `Database: migration pending — would apply:` **006_schedule_materials**, the same `carried:` lines as last time, `Dry run complete`.
3. **Install:** `bash deploy/install.sh`
   Expect `created table staff`, `created table job_items`, `added 2 columns to tasks`, `Database migration: OK`, `Verified: … v0.14.0 · build 20261103.3`.
   - **If it stops with "the app's database user may not change table structure":** nothing has changed. In **phpMyAdmin** → `cloakecrea_manager_devt` → **SQL** → paste the whole of `deploy/migrations/006_schedule_materials.apply.sql` → **Go**. Then `bash deploy/install.sh` again.
4. **Check (5 minutes):**
   - Header `v0.14.0 · build 20261103.3` → **Release check**: all passed, including *Migration 006*.
   - On a computer: the sidebar now has **Workload**, **Month-end claims**, **Billing settings**, **Staff & hours** and **More…**.
   - **Staff & hours**: switch on/off the people who should be on the schedule, and set their hours.
   - **Workload**: drag a task to a day (or use **Move**); the **Undo** bar appears.
   - Open a task → **Materials** → add an item → **Received** → it appears on **Pending Invoicing** as a Material line. (Remove the test item, or cancel it with a reason.)
   - Optional: **Billing settings → Materials markup %**.

## Undo
- **The app:** `bash deploy/rollback.sh` — puts v0.13.0 back. The database stays migrated; v0.13.0 works with it (tested). Schedules, staff and materials are kept for when you reinstall.
- **Migration 006 itself (only if it went wrong):** `/opt/cpanel/ea-php83/root/usr/bin/php deploy/migrate.php rollback --confirm` (undoes only 006), or `006_schedule_materials.rollback.sql` in phpMyAdmin. This drops the two tables and two columns **and everything in them**. To restore exactly, import the `db-before-v0.14.0-….sql.gz` backup instead.

## Files
`public/` web root · `deploy/install.sh`, `rollback.sh`, `migrate.php`, `migrations/`, `import-whmcs.php` · `CHANGELOG.md` · `TEST_REPORT.md` · `MANIFEST_SHA256.txt` · `SOURCE_COMMIT.txt`
