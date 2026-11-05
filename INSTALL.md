# Install v0.22.3 (build 20261105.9)

Application files only; no database change. `cm-update v0.22.3` → `y`. Undo: `cm-update v0.22.2`.

Then Clients › **Archive quiet clients ›**.

---

# Install v0.22.2 (build 20261105.8)

Application files only; no database change. `cm-update v0.22.2` → `y`. Undo: `cm-update v0.22.1`.

Then Clients › Contacts without a client › open a junk one › Status: Archived › Save.

---

# Install v0.22.1 (build 20261105.7)

**What changes:** the command-line tools only explain themselves when run with the wrong PHP. No database change; screens unchanged.

`su -s /bin/bash - cloakecrea` → `cm-update v0.22.1` → `y`. Undo: `cm-update v0.22.0`.

When running a tool by hand, use the website's PHP: `/opt/cpanel/ea-php83/root/usr/bin/php ~/cloake-manager/releases/<release>/deploy/<tool>.php …`

---

# Install v0.22.0 (build 20261105.6) — and how to undo it

**What changes:** application files only. **No database change.**

## 1. Install
1. `su -s /bin/bash - cloakecrea`
2. `cm-update v0.22.0` → `y` → `Verified: … v0.22.0 · build 20261105.6`.
3. More… › **Invoices** (needs Xero connected — with the Demo Company you'll see its test invoices).

## 2. WHMCS invoice history (import batch 2, ~2 minutes)
1. Upload `whmcs-batch2-invoices-2026-11-05-CONFIDENTIAL.zip` with File Manager, then (as cloakecrea):
   ```
   find ~/public_html ~ -maxdepth 2 -name 'whmcs-batch2-invoices-*.zip' -exec mv {} ~/cloake-manager/private/ \;
   cd ~/cloake-manager/private && unzip -o whmcs-batch2-invoices-2026-11-05-CONFIDENTIAL.zip && cd ~
   php ~/cloake-manager/releases/cloake-workdesk-v0.22.0-build20261105.6/deploy/import-whmcs.php dry-run --dir ~/cloake-manager/private/whmcs-batch2
   ```
   Expect `Archive: 2989 rows (invoices 2989); … linked to a Workdesk client`.
2. `php ~/cloake-manager/releases/cloake-workdesk-v0.22.0-build20261105.6/deploy/import-whmcs.php apply --dir ~/cloake-manager/private/whmcs-batch2 --by "Geoff"`
3. Open a client: Invoices › WHMCS invoices.
4. Delete the uploaded copies: `rm -r ~/cloake-manager/private/whmcs-batch2 ~/cloake-manager/private/whmcs-batch2-invoices-*.zip`

Undo the history import: `php …/deploy/import-whmcs.php undo --batch <n> --confirm` (the number the apply printed).

**Undo the app:** `cm-update v0.21.0`.

---

# Install v0.21.0 (build 20261105.5) — and how to undo it

**What changes:** application files and **database migration 010** (add-only: services.keep_billing). From now on **only Active services are invoiced** — Suspended and Pending (in development) are not, unless "Keep billing" is ticked on the service.

1. `su -s /bin/bash - cloakecrea`
2. `cm-update v0.21.0` → dry run lists `010_billing_status — PENDING` → `y` → `Verified: … v0.21.0 · build 20261105.5`.
3. More… › **Release check**: Migration 010 applied.
4. **Billing settings › Development cPanel accounts:** the cPanel username(s) of your development account(s).
5. **cPanel check:** mark the gone ones as terminated, set server-suspended ones to Suspended, record development sites. **Domains › Expired:** mark the gone ones as terminated.
6. **Pending Invoicing:** anything Suspended / Pending you *do* still want to bill → open the service › Edit › tick "Keep billing".

**Undo:** `cm-update v0.20.1` (the older app ignores the new column and bills Suspended/Pending again).

---

# Install v0.20.1 (build 20261105.4) — and how to undo it

**What changes:** application files only. **No database change.**

1. `su -s /bin/bash - cloakecrea`
2. `cm-update v0.20.1` → the dry run → type `y` → `Verified: … v0.20.1 · build 20261105.4`. (If the question misbehaves again: `bash ~/cloake-manager/releases/cloake-workdesk-v0.20.1-build20261105.4/deploy/install.sh`.)
3. **Refresh cm-update itself once** (it doesn't update itself):
   `install -m 755 ~/cloake-manager/releases/cloake-workdesk-v0.20.1-build20261105.4/deploy/cm-update.sh ~/bin/cm-update`
4. More… › **Billing settings** › *Management mailboxes*: add any other names you use besides `webmailer` (comma-separated; `*` = any characters). Subdomain mailboxes are already treated as management.

**Undo:** `cm-update v0.20.0`.

---

# Install v0.20.0 (build 20261105.3) — and how to undo it

**What changes:** application files only. **No database change.** Plus a one-off root step for the nightly server check.

## 1. Install (WHM Terminal)
1. `su -s /bin/bash - cloakecrea`
2. `cm-update v0.20.0` → `Database: schema up to date` → `y` → `Verified: … v0.20.0 · build 20261105.3`.
3. `exit` (back to root for the next step).

## 2. Email inventory — once, as root
```
bash /home/cloakecrea/cloake-manager/releases/cloake-workdesk-v0.20.0-build20261105.3/deploy/install-email-inventory.sh
```
It installs the check (root only) and a nightly job (02:17), runs it once and prints e.g. `Email inventory: 68 accounts, 120 mailboxes, 45 forwarders`. Then in Workdesk: **More… › cPanel check**, and any hosting service page shows **On the server**.

To remove it later: the same command with `--remove`.

## Undo
`cm-update v0.19.0` (the server check can stay; v0.19 just ignores its file).

---

# Install v0.19.0 (build 20261105.2) — and how to undo it

**What changes:** application files and **database migration 009** (add-only: a new `plans` table with your six plans, and `plan_id` + `mailboxes` on services). Includes v0.18.2 (short Xero descriptions). **No Xero reconnect needed.**

## 1. Install (WHM Terminal, 2 minutes)
1. `su -s /bin/bash - cloakecrea`
2. `cm-update v0.19.0` → the dry run lists `009_service_plans — PENDING` → `y` → the installer backs up the database, applies 009 and shows `Verified: … v0.19.0 · build 20261105.2`.
3. More… › **Release check**: "Migration 009 — service plans: applied — 6 plans, 0 services on a plan".

## 2. Set up plans (10 minutes)
1. More… › **Plans**: check the six prices (today's price for new clients). Edit any that are wrong.
2. **Match … services without a plan**: clear matches are ticked; choose a plan for the rest (e.g. Advanced for sites with booking or e-commerce), untick any you're unsure of → **Link ticked services**. Undo is offered straight after.
3. While Xero is connected to the Demo Company, Plans shows ✗ Not in Xero — you can try **Create ticked in Xero** there. When you connect your real organisation, do it once there.

## Undo
`cm-update v0.18.2` (or `v0.18.1`) — the older app ignores the new table and columns. Only if the migration itself must go: `php deploy/migrate.php rollback --confirm` from the v0.19.0 release folder (drops plans and the two columns).

---

# Install v0.18.2 (build 20261105.1) — and how to undo it

**What changes:** application files only. **No database change.** Xero descriptions on Pending Invoicing and drafts become one short line.

1. `su -s /bin/bash - cloakecrea`
2. `cm-update v0.18.2` → `Database: schema up to date` → `y` → `Verified: … v0.18.2 · build 20261105.1`.
3. Open Pending Invoicing: hosting lines read "Website hosting – domain – dates (6 months)".

**Undo:** `cm-update v0.18.1`.

---

# Install v0.18.1 (build 20261104.3) — and how to undo it

**What changes:** application files only. **No database change.** (Includes v0.17.1 and v0.18.0 — install v0.18.1 directly.)

## 1. Install (WHM Terminal, 2 minutes)
1. `su -s /bin/bash - cloakecrea`
2. `cm-update v0.18.1` → `Database: schema up to date` → `y` → `Verified: … v0.18.1 · build 20261104.3`.

## 2. Reconnect Xero once (new permissions)
More… → **Xero connection** → it says "Reconnect once…" → **Reconnect / switch organisation** → Xero lists the new permissions (contacts, settings) → **Allow access** → choose **Demo Company (NZ)** for now → Continue.

## 3. Try it on the Demo Company (10 minutes)
1. More… → **Xero sync**: every active client is flagged (✓ In sync, ≠ Different, ? Probable match, ✗ Not in Xero, ⚠ Contact gone) with its action; **Need action** filters to the ones to do. In the Demo Company nearly all will be ✗ Not in Xero — expected. Xero customers with no client can be marked **Not a client**; suppliers are listed apart. **Account codes and tax types**: in the Demo Company 43504 and 43601 show ✗ — your real organisation should show ✓.
2. Pending Invoicing → one line → **Create Xero draft instead**. In Xero the draft should now show **15% GST on Income** with the GST amount, and a **Reference** (the domain). Undo it here and delete the draft in Xero.

Don't use **Create in Xero** or **Add to Workdesk** on the Demo Company unless you want test contacts there — they work, but they're for your real organisation.

## Undo
`cm-update v0.17.0` (v0.17.1 was never installed; both work on this database).
