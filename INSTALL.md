# Install v0.16.0 (build 20261103.5) — from GitHub — and how to undo it

**What changes:** application files, plus **database migration 007**: six new columns on `services` (name servers, DNS host, auto-renew, included in, provider reference, set-up task) and one new task template ("Set up hosting + domain"). No existing value is changed.
Allow 5 minutes.

## Steps (WHM Terminal)
1. `su -s /bin/bash - cloakecrea`
2. `cm-update v0.16.0`
   - Dry run first. Expect `Database: migration pending — would apply:` **007_domains_provisioning**, `Dry run complete`.
   - **Install v0.16.0 now?** → `y`. Expect `Database migration: OK` and `Verified: … v0.16.0 · build 20261103.5`.
   - **If it stops with "the app's database user may not change table structure":** nothing has changed. phpMyAdmin → `cloakecrea_manager_devt` → **SQL** → paste the whole of the `007_domains_provisioning.apply.sql` file it names → **Go**. Then `cm-update v0.16.0` again.
3. **Check (5 minutes):**
   - **More… → Release check**: all passed, including *Migration 007*.
   - **More… → Domains** → **Billed, never invoiced**: your extra domains. Open one → **Include in a hosting service** → pick its hosting → Save. It leaves Pending Invoicing and shows on the hosting's invoice as an Additional Domain.
   - On a hosting service: **Start set-up task** (try it on a test client, or skip).

## Undo
- **The app:** `cm-update v0.15.0`. v0.15.0 works on the migrated database (tested); the new fields are kept for when you reinstall.
- **Migration 007 itself (only if it went wrong):** `/opt/cpanel/ea-php83/root/usr/bin/php deploy/migrate.php rollback --confirm` in `~/cloake-manager/releases/cloake-workdesk-v0.16.0-build20261103.5/`, or its `.rollback.sql` in phpMyAdmin — removes the six columns and what was entered in them. To restore exactly, import the `db-before-v0.16.0-….sql.gz` backup.
