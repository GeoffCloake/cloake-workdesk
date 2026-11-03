# Install v0.12.0 (build 20261103.1) on manager-dev — and how to undo it

**What changes:** application files in `public_html/manager-dev.cloakecreative.co.nz/`, plus **database migration 004**: three new tables (`task_templates`, `enquiry_task_plans`, `billing_milestones`) and 10 new columns on `breakdown_items`, `enquiries` and `tasks`. It also **copies** your open "Pro forma" tasks into templates (the tasks themselves are not touched). No existing column or value is changed.
**What does not change:** your data, `private/config.php`, cPanel/PHP settings, anything outside the staging site.
Allow 10 minutes. The site is unavailable for under a second during the folder swap.

As on the 31 Oct, 1 Nov and 3 Nov installs: use **WHM Terminal → `su -s /bin/bash - cloakecrea`** first (the prompt must read `[cloakecrea@server …]$`, not `root`). File Manager uploads tend to land in the live site folder; step 2 moves the zip wherever it landed.

---

## Steps (WHM Terminal, as cloakecrea)

1. **Upload** `cloake-manager-v0.12.0-build20261103.1.zip` in File Manager (any folder).
2. **Move, unpack and dry-run** (changes nothing):
   ```bash
   find ~/public_html ~/cloake-manager -maxdepth 3 -name 'cloake-manager-v0.12.0-*.zip' -not -path '*/cloake-manager/releases/*' -exec mv {} ~/cloake-manager/releases/ \;
   cd ~/cloake-manager/releases
   unzip -q cloake-manager-v0.12.0-build20261103.1.zip
   cd cloake-manager-v0.12.0-build20261103.1
   bash deploy/install.sh --dry-run
   ```
   Expect: `PHP: 8.3.x (/opt/cpanel/ea-php83/…)`, `Currently installed: … '0.11.0'`, `Database: migration pending — would apply:` **004_pricing_templates**, the same `carried:` lines as last time, and `Dry run complete`. Paste me the output if anything else appears.
3. **Install:** `bash deploy/install.sh`
   It backs up the database → applies migration 004 → stages, swaps and verifies (automatic rollback if the site doesn't answer with the new version). Expect `created table …` ×3, `filled from imported records: 10 rows changed` (the templates) and `3 rows changed` (their set fees), then `verified`.
   - **If it stops with "the app's database user may not change table structure":** nothing has changed. Open **phpMyAdmin** → select `cloakecrea_manager_devt` → **SQL** tab → paste the whole of `deploy/migrations/004_pricing_templates.apply.sql` (open it in File Manager ▸ Edit, copy all) → **Go**. Then run `bash deploy/install.sh` again; it reports `Database: schema up to date` and continues.
   - If the database backup fails: export in phpMyAdmin (Export ▸ Quick ▸ SQL), then `bash deploy/install.sh --skip-db-backup`.
4. **Check (5 minutes):**
   - Header shows `v0.12.0 · build 20261103.1` → **More ▸ Release check**: "All checks passed", including *Migration 004*.
   - **More ▸ Task templates**: your 10 pro-forma tasks are listed ($1,800 / $700 / $600 on the set-fee ones). Open one; the instructions should read normally.
   - Open any enquiry → **Planned tasks ▸ Add from a template** → the template's price line appears under **Price & billing**.
   - When you're happy with the templates: **Retire pro-forma tasks** on the templates screen (Undo is offered straight afterwards).

## Undo
- **App only (normal undo):** `bash deploy/rollback.sh` — puts v0.11.0 back. The database stays migrated; v0.11.0 works with it (tested). Templates, price lines, milestones and stages entered in v0.12.0 are kept for when you reinstall. (Stages made in v0.12 show as ordinary tasks in v0.11.)
- **Database migration (only if migration 004 itself went wrong):** `/opt/cpanel/ea-php83/root/usr/bin/php deploy/migrate.php rollback --confirm` — undoes **only 004** (001–003 stay). Or run `004_pricing_templates.rollback.sql` in phpMyAdmin. This drops the three tables and ten columns **and anything entered into them since** (templates, extra price lines, milestones, planned tasks; stage tasks stay as ordinary tasks). To restore everything exactly as it was, import the `db-before-v0.12.0-….sql.gz` backup instead.

## Files
`public/` web root · `deploy/install.sh`, `rollback.sh`, `migrate.php`, `migrations/` · `CHANGELOG.md` · `TEST_REPORT.md` · `MANIFEST_SHA256.txt` · `SOURCE_COMMIT.txt`
