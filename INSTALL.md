# Install v0.17.0 (build 20261103.6) — from GitHub — then the Xero Demo Company test

**What changes:** application files, plus **database migration 008** (one new column, `charges.xero_invoice_id`). Nothing existing changes, and **nothing talks to Xero until you connect it** (step B).

## A. Install (WHM Terminal, 3 minutes)
1. `su -s /bin/bash - cloakecrea`
2. `cm-update v0.17.0` → dry run lists **008_xero_drafts** → `y` → expect `Database migration: OK`, `Verified: … v0.17.0 · build 20261103.6`.
   - If it stops asking for phpMyAdmin: run the `008_xero_drafts.apply.sql` it names (phpMyAdmin › SQL › Go), then `cm-update v0.17.0` again.
3. **More… → Release check**: all passed, including *Migration 008*.

## B. Xero test with the Demo Company (10 minutes, when you're ready)
1. **Demo Company:** in Xero, open your organisation menu → **Demo Company** (start it if asked). It's Xero's practice organisation with made-up data.
2. **Make the app:** go to **developer.xero.com** → sign in → **My Apps** → **New app**:
   - App name: `Cloake Workdesk` · Integration type: **Web app** · Company or application URL: `https://cloakecreative.co.nz`
   - Redirect URI: `https://manager-dev.cloakecreative.co.nz/xero-callback.php` (shown on the Workdesk Xero page too) → tick the terms → **Create app**.
   - **Configuration** → copy the **Client id**; **Generate a secret** → copy it.
3. **Workdesk → More… → Xero connection** → paste both → **Save app details** → **Connect to Xero** → Xero asks you to allow access → choose **Demo Company (NZ)** → **Allow access** → **Continue**. It should say *Connected to Demo Company (NZ)*.
4. **Pending Invoicing** → for one client, tick one line, enter *Recorded by* → **Create Xero draft instead**. It should say *Draft INV-… created in Xero (Demo Company)*.
5. In Xero (Demo Company) → **Invoices → Draft**: check the draft (line, amount ex GST, account, GST). Then back in Workdesk click **Undo** (it reminds you to delete the draft in Xero) and delete the draft in the Demo Company.

When you're happy, the same steps connect your real organisation (Reconnect / switch organisation). Disconnect at any time on the same page.

## Undo
- **The app:** `cm-update v0.16.0` (works on the migrated database — tested). The Xero connection file stays in the private folder; delete it with `rm ~/cloake-manager/private/xero.json` if you want it gone.
- **Migration 008 itself:** `deploy/migrate.php rollback --confirm` in the release folder, or its `.rollback.sql` in phpMyAdmin.
