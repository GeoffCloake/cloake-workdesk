# Install v0.13.0 (build 20261103.2) on manager-dev — and how to undo it

**What changes:** application files in `public_html/manager-dev.cloakecreative.co.nz/`, plus **database migration 005**: four new tables (`services`, `charges`, `settings`, `archive_whmcs`) and one new column (`work_log.charge_id`). No existing column or value is changed.
**Then, separately:** the **WHMCS import** (step 5) loads your services, the Xero invoiced periods and the WHMCS archive. It is one batch you can undo.
**What does not change:** your existing data, `private/config.php`, cPanel/PHP settings, WHMCS (stays offline), Xero.
Allow 15 minutes.

As before: **WHM Terminal → `su -s /bin/bash - cloakecrea`** first (the prompt must read `[cloakecrea@server …]$`).

You need two files:
- `cloake-manager-v0.13.0-build20261103.2.zip` — the app (no client data)
- `whmcs-import-2026-11-03-CONFIDENTIAL.zip` — your WHMCS data for the import (**confidential**: no passwords, but client names, domains and prices). It goes only into `~/cloake-manager/private/`, never the website folder.

---

## Steps (WHM Terminal, as cloakecrea)

1. **Upload both zips** in File Manager (any folder).
2. **Move them out of the website folder, unpack the app, dry-run** (changes nothing):
   ```bash
   find ~/public_html ~/cloake-manager -maxdepth 3 -name 'cloake-manager-v0.13.0-*.zip' -not -path '*/cloake-manager/releases/*' -exec mv {} ~/cloake-manager/releases/ \;
   find ~/public_html ~/cloake-manager -maxdepth 3 -name 'whmcs-import-2026-11-03-CONFIDENTIAL.zip' -not -path '*/cloake-manager/private/*' -exec mv {} ~/cloake-manager/private/ \;
   cd ~/cloake-manager/releases
   unzip -q cloake-manager-v0.13.0-build20261103.2.zip
   cd cloake-manager-v0.13.0-build20261103.2
   bash deploy/install.sh --dry-run
   ```
   Expect: `Currently installed: … '0.12.0'`, `Database: migration pending — would apply:` **005_services_billing**, the same `carried:` lines as last time, `Dry run complete`.
3. **Install:** `bash deploy/install.sh`
   Expect `created table` ×4, `added 1 columns to work_log`, `Database migration: OK`, `Verified: … v0.13.0 · build 20261103.2`.
   - **If it stops with "the app's database user may not change table structure":** nothing has changed. In **phpMyAdmin** → `cloakecrea_manager_devt` → **SQL** tab → paste the whole of `deploy/migrations/005_services_billing.apply.sql` → **Go**. Then `bash deploy/install.sh` again.
4. **Check:** header `v0.13.0 · build 20261103.2` → **More ▸ Release check**: all passed, including *Migration 005*. **More ▸ Services** is empty until step 5.
5. **WHMCS import** — dry run first (changes nothing), then import:
   ```bash
   cd ~/cloake-manager/private && unzip -q whmcs-import-2026-11-03-CONFIDENTIAL.zip && chmod 700 whmcs-import-2026-11-03
   cd ~/cloake-manager/releases/cloake-manager-v0.13.0-build20261103.2
   /opt/cpanel/ea-php83/root/usr/bin/php deploy/import-whmcs.php dry-run --dir ~/cloake-manager/private/whmcs-import-2026-11-03
   ```
   Expect: `new client: [new client A]`, `new client: [new client B]`, `Archive: 575 rows`, `Services: 173 new`, `Invoiced periods from Xero: 175`, `Pending Invoicing after import: 18 due within 8 weeks (5 overdue), $3,208.10 ex GST`. Paste me the output if anything differs. Then:
   ```bash
   /opt/cpanel/ea-php83/root/usr/bin/php deploy/import-whmcs.php apply --dir ~/cloake-manager/private/whmcs-import-2026-11-03 --by "Geoff"
   ```
   It prints `Imported as batch 1`.
6. **Check (5 minutes):**
   - **More ▸ Services**: 173 items; filter "Needs a look" shows the 3 priced items with no cycle and the check statuses.
   - **More ▸ Pending Invoicing**: the 5 overdue at the top ([oldest overdue domain] since 25 Feb …), each with its Xero description and **Copy**.
   - Open a client with hosting: **Services & domains**, and **Former services** for old WHMCS items.
   - **More ▸ Billing settings**: set your **hourly rate** (used for month-end claims and hourly tasks).
7. **Tidy up:** once you're happy, delete the confidential zip (the unpacked folder can stay in `private/`, or delete it too):
   `rm ~/cloake-manager/private/whmcs-import-2026-11-03-CONFIDENTIAL.zip`

**Important for Cathy:** the import starts each service from the last period invoiced in Xero **as at the 2 Nov export**. If Cathy has invoiced any renewals in Xero since then, record those in Pending Invoicing (tick the line, enter the Xero number) right after the import — or tell me before installing and I'll rebuild the import file from her updated workbook.

## Undo
- **The import only:** `php deploy/import-whmcs.php undo --batch 1 --confirm` (with the ea-php83 path) — removes the services, invoiced periods, archive rows and the 2 new clients. It refuses while an invoice recorded in the manager uses those services (undo those on Pending Invoicing first).
- **The app:** `bash deploy/rollback.sh` — puts v0.12.0 back. The database stays migrated; v0.12.0 works with it (tested). Services and charges are kept for when you reinstall.
- **Migration 005 itself (only if it went wrong):** `php deploy/migrate.php rollback --confirm` (undoes only 005), or `005_services_billing.rollback.sql` in phpMyAdmin. This drops the four tables **and everything in them** (services, invoiced periods, archive). To restore exactly, import the `db-before-v0.13.0-….sql.gz` backup instead.

## Files
`public/` web root · `deploy/install.sh`, `rollback.sh`, `migrate.php`, `migrations/`, `import-whmcs.php` · `CHANGELOG.md` · `TEST_REPORT.md` · `MANIFEST_SHA256.txt` · `SOURCE_COMMIT.txt`
