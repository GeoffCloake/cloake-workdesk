# Changelog — Cloake Workdesk (formerly Cloake Client & Services Manager)

Versions are shown on every screen (header) and on the sign-in page as `v<version> · build <build>`.
Status words used below: **built** (code exists) · **tested locally** (automated tests passed here) · **deployed** (installed on manager-dev) · **user-confirmed** (you have checked it on the server).

## v0.22.3 · build 20261105.9 — 5 November 2026
**Status: built and tested locally.** 149/149 on MariaDB 10.11 and MySQL 8.0.46. **No database change.**

### Added — archive quiet clients in one go (your request)
- **Clients › Archive quiet clients ›**: Active clients with **no live service, no open task and no open enquiry**, and nothing since the cut-off (work, tasks, enquiries, recorded invoices, WHMCS invoices) — choose 1, 2, 3 (default), 5 or 10 years. All ticked; untick any to keep; **Archive ticked clients** is one change with one Undo. Only the status changes; history stays; Xero is not touched.

## v0.22.2 · build 20261105.8 — 5 November 2026
**Status: built and tested locally.** 148/148 on MariaDB 10.11. **No database change.**

### Fixed — removing junk contacts (your question)
- A contact with no client (and even no name) can now be **archived**: open it › Status: **Archived** › Save — no client or name needed for that. Archived contacts drop off Clients › "Contacts without a client". Nothing is deleted; Undo works.

## v0.22.1 · build 20261105.7 — 5 November 2026
**Status: built and tested locally.** 147/147 on MariaDB 10.11. **No database change.** App screens unchanged.

### Fixed
- The command-line tools (WHMCS import, migrations, email inventory) now stop with a clear message when run with PHP 7 — the server's plain `php` — and show the command with the website's PHP (`/opt/cpanel/ea-php83/root/usr/bin/php …`), instead of stopping silently.

## v0.22.0 · build 20261105.6 — 5 November 2026
**Status: built and tested locally (Xero calls against the local stand-in).** 147/147 on MariaDB 10.11 and MySQL 8.0.46 (synthetic data). **No database change.** `cm-update v0.21.0` goes back.

### Added — all invoices (your request: "do both")
- **More… › Invoices — live from Xero, read-only:** everything awaiting payment, overdue first, with totals (awaiting / overdue), each linked to its Workdesk client; an Overdue filter; each number opens the invoice in Xero. Nothing is stored or sent to Xero.
- **A client's Xero invoices:** client page › Invoices › **In Xero ›** (all statuses, newest first) once the client is linked to its Xero contact.
- **WHMCS invoice history (import batch 2):** the WHMCS invoices export goes into the read-only archive and each client page lists them under Invoices › WHMCS invoices (number, date, total incl GST, status, date paid). A later batch needs no client map — it uses the clients matched in batch 1; the dry run says how many rows link to a client. Undo by batch as before.

## v0.21.0 · build 20261105.5 — 5 November 2026
**Status: built and tested locally.** 145/145 on MariaDB 10.11 and MySQL 8.0.46 (synthetic data). **Database migration 010** (add-only: one column, services.keep_billing). `cm-update v0.20.1` goes back.

### Changed — billing follows the service status (your new policy)
- **Only Active services are invoiced.** Suspended and **Pending — in development** are left off Pending Invoicing (and can't be recorded) unless **Keep billing while suspended or in development** is ticked on the service. The service page says "Suspended — not billed" / "Pending — in development — not billed".
- New status **Terminated** (for services that have gone; history stays on the client).

### Added — tidying up
- **cPanel check › No cPanel account found:** tick and **Mark ticked as terminated** — one undoable change (status only).
- **cPanel check › Suspended on the server, Active here:** **Set ticked to Suspended** (one undoable change), so they stop billing.
- **Domains › Expired:** **Mark ticked as terminated**; domains expired more than 30 days ago are pre-ticked.
- **Development accounts** (Billing settings › Development cPanel accounts, e.g. your Devt account): cPanel check lists the sites on them; **Record** with the client creates the service as "Pending — in development" with no charge, so the client's info sits with it and it is never invoiced. Those accounts no longer show as "accounts with no service".

## v0.20.1 · build 20261105.4 — 5 November 2026
**Status: built and tested locally.** 141/141 on MariaDB 10.11 and MySQL 8.0.46. **No database change.** `cm-update v0.20.0` goes back.

### Changed — management mailboxes aren't client mailboxes (your note)
- **Billing settings › Management mailboxes:** names before the @ that are yours (default `webmailer`; comma-separated, `*` = any characters, e.g. `webmailer, noreply, forms*`), and **mailboxes on a subdomain** (e.g. forms@mail.example.co.nz) count as management too (yes / no, default yes).
- They are still listed on the service page, marked **management — not billed**, but left out of the mailbox counts everywhere: client page, cPanel check, the "recorded here" flag and so the Xero line.

### Fixed — the install question
- `cm-update` reads the answer from the keyboard (not from anything piped in), ignores spaces and capitals, and accepts `y` or `yes`; `cm-update vX.Y.Z --yes` installs without asking. **Refresh your copy once** (see INSTALL.md).

## v0.20.0 · build 20261105.3 — 5 November 2026
**Status: built and tested locally.** 141/141 on MariaDB 10.11 and MySQL 8.0.46 (synthetic data; uapi and whmapi1 played by stand-ins). **No database change.** `cm-update v0.19.0` goes back.

### Added — what's provisioned on the server (your request)
- **Nightly read-only check, run by root** (`deploy/install-email-inventory.sh`, once in WHM Terminal): for every cPanel account it lists the domains, **mailboxes** and **forwards** (cPanel's own `uapi`; never passwords), plus **package, disk used / quota and suspended** (`whmapi1 listaccts`). It writes one file into Workdesk's private folder; **Workdesk holds no server credential** and changes nothing on the server. The script is copied to a root-only place, so nothing in the cloakecrea account can change what root runs.
- **Service page › On the server:** the account, package, disk, each mailbox and each forward (forwards marked free), and a flag when the mailbox count recorded in Workdesk differs.
- **Client page:** each hosting/email service shows "cPanel user · 3 mbx · 2 fwd" (and suspended).
- **More… › cPanel check:** mailbox counts to update (tick → **Update mailbox counts**, one undoable change; prices untouched), billed services suspended on the server, services with no account found, and cPanel accounts no service points to (possibly not billed).
- Release check shows when the server file was last written.
- Invoices are unchanged: the line still shows only domain, period, included domains and mailboxes.

## v0.19.0 · build 20261105.2 — 5 November 2026
**Status: built and tested locally (Xero calls against the local stand-in).** 139/139 on MariaDB 10.11 and MySQL 8.0.46 (synthetic data). **Database migration 009** (add-only: one new table, two new columns; rollback tested). Includes v0.18.2. **No Xero reconnect needed** — Xero items come under the invoices permission Workdesk already has.

### Added — Plans (More… › Billing › Plans) (your request)
- **The plan list** with today's price for a new client (ex GST): HOST-ESS Website hosting – Essential $135 / 6 months · HOST-STD Standard $299.70 / 6 months · HOST-ADV Advanced $390 / 6 months · MAILBOX $2.95 / month · DNS-FWD price per client · DOMAIN $49.95 / year. Add, edit, deactivate; edits are audited and undoable.
- **Old clients keep their price.** A service records its plan and its own price; when the price is below today's list price the service page says **legacy price** (with the list price now). Nothing ever changes a price for you.
- **Match services to plans:** lists live services without a plan with the plan their price points to — e.g. $152.70 / 6 months = Essential + 1 mailbox; $201 = Standard at the older price; email-only = mailboxes × $2.95. Clear matches are ticked; anything else (and Advanced) is yours to choose. **Link ticked services** records plan + mailboxes only; one Undo puts them all back.
- **Service form:** Plan and Mailboxes (provisioned). A new service with a plan and no price starts at the list price for its cycle (plus mailboxes). The old WHMCS plan name stays for reference.
- **Xero items:** each plan can be created in Xero's Products and services (code = plan code, name, price, account, 15% GST); Plans shows ✓ In Xero · ≠ differs → **Update Xero** · ✗ Not in Xero → tick, **Create ticked in Xero**; other Xero items are listed apart. Workdesk never deletes anything in Xero.
- **Drafts:** a line for a service whose plan is a Xero item carries the item code, so Xero can report sales by plan. Amount and description are still Workdesk's.
- **Xero descriptions** now show mailboxes when a service has them ("… Includes example.nz and 3 mailboxes").

## v0.18.2 · build 20261105.1 — 5 November 2026
**Status: built and tested locally.** 133/133 on MariaDB 10.11 and MySQL 8.0.46 (synthetic data). **No database change.** `cm-update v0.18.1` goes back.

### Changed — short Xero descriptions (your request)
- One line per item, no plan names or software (PHP) versions, no email set-up details, no cost breakdown:
  - `Website hosting – example.co.nz – 20/11/2026 to 19/05/2027 (6 months)` · with included domains `… (6 months). Includes example.nz`
  - `Email hosting – example.co.nz – 1 mailbox – …` · `Domain renewal – example.nz – … (1 year)` · `DNS and forwarding – example.co.nz – …`
- Mailboxes are mentioned only when a service has them (the count arrives with v0.19 plans).
- Safety net: email addresses, web addresses and anything that looks like a login or password are never put in a Xero description (Pending Invoicing, recorded invoices and drafts).
- The service form's "Plan" box is now for your records only (not on invoices).

## v0.18.1 · build 20261104.3 — 4 November 2026
**Status: built and tested locally (Xero calls against the local stand-in).** 132/132 on MySQL 8.0.46 (real-data replica) and MariaDB; installed with `cm-update v0.18.1` and the full suite passed on the installed copy; `cm-update v0.17.0` goes back. **No database change.** Includes everything in v0.18.0 and v0.17.1 — install this one.

### Changed — Xero sync shows what is in sync and what to do (your request)
- **One list of every active client**, each flagged: **✓ In sync** · **≠ Different** (name/email) → *Update Xero* · **? Probable match** → tick, *Link ticked matches* · **✗ Not in Xero** → tick, *Create ticked in Xero* · **⚠ Contact gone from Xero** → *Unlink*. The line under each name says what differs or what to do.
- Counts at the top (In sync / Need action / Xero customers with no client) and filter chips: All, **Need action**, and each status.
- **Client page:** "✓ Linked to Xero" or "✗ Not linked to Xero" (and zero rated if overseas), with a link to Xero sync.
- Account codes and tax types that fail the check now say what to do.

### Changed — Xero contacts that aren't clients (your note)
- **Xero customers with no client** (may need action): *Add to Workdesk*, or **Not a client** — hides it from the list for good (remembered by Workdesk; Xero is not changed), with *Show again* under "Marked not a client".
- **Other Xero contacts** (suppliers etc. — not customers in Xero) are listed apart, **no action needed**.

## v0.18.0 · build 20261104.2 — 4 November 2026
**Status: built and tested locally; superseded by v0.18.1 (install v0.18.1).** 131/131 on MySQL 8.0.46 (real-data replica) and MariaDB; installed over v0.17.1 with `cm-update` and the full suite passed on the installed copy; `cm-update v0.17.1` goes back. **No database change.**
**Reconnect Xero once** after installing (new permissions: create/update contacts, read account codes and tax rates).

### Added — Xero sync (More… › Billing › Xero sync)
- Reads your Xero contacts live and compares them with your active clients:
  - **Probable matches** — same name (ignoring case, punctuation, Ltd/Limited), same email, or the old contact number → tick and **Link** (stores Xero's ContactID on the client; audited; Undo works).
  - **Active clients not in Xero** → tick and **Create in Xero** (name, email, phone, postal address), then linked.
  - **Linked, but different** (name or email) → **Update Xero from Workdesk**.
  - **Linked contact not found in Xero** (archived/deleted there) → Unlink.
  - **Xero contacts with no client** → **Add to Workdesk**.
- **Account codes and tax types** used by Workdesk (Billing settings and every live service) are checked against Xero's chart of accounts and tax rates: ✓ name, or ✗ not in Xero / not active.
- Nothing changes without a click. Workdesk never deletes or archives anything in Xero and never overwrites Workdesk data from Xero.

### Added — tax by country (kept simple)
- Clients default to **15% GST on Income**. A client whose postal country (else physical country) is clearly another country — e.g. United Kingdom — is **zero rated**: Billing settings › *Xero tax type for overseas clients*, default **ZERORATEDOUTPUT**. Pending Invoicing shows "overseas (…) — zero rated" and incl = ex; the client page shows it. A town or postcode typed in the country box counts as New Zealand. On your data: 2 active clients (United Kingdom, Netherlands).

### Added — invoice Reference on Xero drafts
- Filled in automatically: the task's purchase order no (else job no, else title), or the service's domain; several lines → joined. Type your own in **Xero reference** on Pending Invoicing to override.

### Changed
- Client page: "Xero contact" shows "Linked (Xero sync)" when linked (the old text "null" from the import is no longer shown).

## v0.17.1 · build 20261104.1 — 4 November 2026
**Status: built and tested locally; superseded by v0.18.0 before install (install v0.18.0 directly).** 126/126 on MySQL 8.0.46 (real-data replica) and MariaDB; installed over v0.17.0 with `cm-update` (no database change) and the full suite passed on the installed copy; `cm-update v0.17.0` goes back cleanly.
Fixes from your real Xero Demo Company test (draft INV-0044: no account, GST $0).

### Fixed
- Every line of a Xero draft now carries the **tax type** — Billing settings › **Xero tax type**, default **OUTPUT2** (15% GST on Income). GST is right even when Xero doesn't recognise the account.
- If Xero drops an account code (it does this silently on drafts when the organisation has no such account — e.g. 43504 in the Demo Company), the confirmation now says **"Check the draft in Xero: Xero dropped account 43504 on line 1"** instead of saying nothing. Same if Xero doesn't apply the tax type.

## v0.17.0 · build 20261103.6 — 3 November 2026
**Status: deployed to manager-dev and user-confirmed 4 Nov 2026 (~10:39, "installed and looking good"). Real Xero Demo Company test 4 Nov 18:32: connect OK, draft INV-0044 created (description, ex-GST amount, due date right), undone and deleted. Found: Xero dropped the unknown account code and set no tax rate (GST $0) — fix in v0.17.1.** Tested: 125/125 on MySQL 8.0.46 (real-data replica) and MariaDB (fixture); installed over v0.16.0 with `cm-update` by both database routes; v0.16.0's own suite 119/119 after going back. **Includes database migration 008** (add-only, one column).
Xero draft invoices — test with Xero's **Demo Company** first.

### Added — Xero connection (More… › Billing › Xero connection)
- Paste your Xero app's **Client id** and **Client secret** (kept in the private folder on the server, outside the website, file readable only by your account; never in the database; never shown again).
- **Connect to Xero** → Xero's own sign-in and consent → choose the organisation (Demo Company for the test). Uses Xero's current granular permissions: *offline_access accounting.invoices accounting.contacts.read*.
- The connection renews itself whenever it's used (Xero's 60-day rule); **Disconnect** removes access (and tells Xero to revoke it).

### Added — "Create Xero draft instead" (Pending Invoicing, once connected)
- Sends the ticked lines of one client to Xero as **one DRAFT invoice** (amounts ex GST, your account codes, due on the period start or today). Xero gives it its number (INV-…), which is recorded here instead of a typed one; services move on a period, as when recording by hand.
- **Only drafts** — Workdesk never approves, emails or pays. Cathy reviews and approves in Xero › Invoices › Draft.
- Contact: the client's **Xero contact reference** if it holds a Xero ContactID, else the Xero contact with exactly the client's name. If none matches it stops (so no duplicate contacts are made) — except in the Demo Company, where Xero creates it.
- If Xero refuses (or can't be reached), **nothing is recorded** here and Xero's reason is shown. **Undo** removes it here and reminds you the draft is still in Xero to delete.
- Typing the number by hand ("Record invoice for ticked lines") works exactly as before.

### Fixed
- The double-submit guard disabled the pressed button before the form was sent, so a button's own value was lost (needed for "Create Xero draft instead"). Now kept.

### Database — migration 008 (add-only)
- charges.xero_invoice_id (Xero's InvoiceID for drafts made from Workdesk).

## v0.16.0 · build 20261103.5 — 3 November 2026
**Status: deployed to manager-dev and user-confirmed 3 Nov 2026 (~22:40, "looks fine"). Rollback target for v0.17.0.** Tested beforehand: 119/119 on MySQL 8.0.46 (real-data replica) and MariaDB (fixture); installed over v0.15.0 with `cm-update` by **both database routes** (installer applies 007 / phpMyAdmin SQL first); v0.15.0's own suite 113/113 after going back with `cm-update v0.15.0`. **Includes database migration 007** (add-only, reversible).
Domain register and provisioning (ready for a later Vetta portal sync).

### Added — Domains (More… › Billing › Domains)
- Every domain with its client, expiry (amber within 60 days, red when expired), registrar, DNS host, auto-renew and how it's billed: its own price, **in <hosting>**, no charge, or internal.
- Filters: **Expiring within 60 days**, **Expired**, **Billed, never invoiced** (billed on its own but no invoice on record — your 27 extra domains are here), **No charge, not linked**, **Included in hosting**; registrar; live/ended; search.

### Added — on each service
- New fields (Edit service › Domain & server): **DNS host**, **name servers**, **auto-renew** (yes / no / not known), **provider reference** (registrar / Vetta portal ID — never a transfer code), and for a domain **Included in hosting**.
- **Include in a hosting service** (on a domain's page): the domain is then not invoiced on its own (taken off Pending Invoicing; recording it is refused) and is listed as **Additional Domains: …** on that hosting's Xero description. The hosting's page lists its included domains. Audited; Undo works. Only a hosting service of the same client can be chosen.
- **Set-up task**: "Start set-up task" makes a task for the client from the new **Set up hosting + domain** template (checklist: register/transfer, DNS, cPanel, email, SSL, live, billing), titled "Set up: <domain>", linked both ways (the task shows "Sets up"). Edit the template's checklist like any other template.

### Database — migration 007 (add-only)
- services: nameservers, dns_host, auto_renew, included_in_service_id, provider_ref, provision_task_id. One task template seeded. Nothing existing changed. (A separate "registrar status" column was not needed — the service Status already has Active / Expired / Transferred away.)

## v0.15.0 · build 20261103.4 — 3 November 2026
**Status: deployed to manager-dev 3 Nov 2026 22:05 with `cm-update v0.15.0` (first install from GitHub) and user-confirmed ("looks good", 22:14). Rollback target for v0.16.0.** Tested: 113/113 on MySQL 8.0.46 (real-data replica) and MariaDB (fixture); installed over v0.14.0 through `cm-update` (git route). **No database change.** Rollback target: v0.14.0.
Simpler screens (your request: "minimal for basic things… step back higher level functions") and the new name.

### Changed — the app is now **Cloake Workdesk**
- Header, page titles, sign-in page and More page. Nothing else about it changes (same address, same sign-in, same data).

### Changed — fewer things on screen
- **Desktop sidebar:** Today · Tasks · Work Log · Clients · Enquiries · **More…** only. Workload, Task templates, Staff & hours, Pending Invoicing, Services, Month-end claims, Billing settings, Account Items and Release check are on **More…** (grouped: Everyday, Planning, Billing, System). More… is highlighted while you're on any of them. Phone navigation unchanged.
- **Task page:** Log work, status, sign-off, instructions, notes and time sheets as before. **Price & billing, Materials, Stages, Make a template** and the recorded pricing/administration fields are behind one **More options** — closed on a plain task, **open by itself when the task already uses any of them** (its heading says what, e.g. "2 price lines · 1 material"). Links back to a panel (after saving a price line, a material, a stage, or Undo) open it automatically.
- **Edit task form:** category, type and domains, and queue / job / order numbers, are under **More details** (open when already filled in). Pricing & billing and Notes were already folded.
- Nothing removed; every feature is where it was, one click further away.

### Added — installing from GitHub
- `deploy/cm-update.sh` (installed on the server as `cm-update`): lists releases on GitHub, or fetches one (`release/vX.Y.Z` branch), checks its version, builds the package, runs the dry run, asks, then runs the same installer (backup, verify, automatic rollback).

## v0.14.0 · build 20261103.3 — 3 November 2026
**Status: deployed to manager-dev and user-confirmed 3 Nov 2026 (~19:25). Rollback target for v0.15.0.** Tested locally beforehand: 111/111 on MySQL 8.0.46 (real-data replica) and MariaDB; installed over v0.13.0 by both database routes.
Scheduling & materials (roadmap v0.14, your points 19 and 20) and the desktop sidebar gap you found. **Includes database migration 006** (add-only, reversible).

### Added — Workload (who is doing what, and when)
- **Workload** (sidebar, and More on a phone): a section per person with the days ahead, each with the tasks and stages scheduled for them and the time booked against their hours that day (shown red when over). **Overdue on the schedule** and **Not scheduled** lists alongside. Filters: from date, 1/2/4 weeks, one person.
- **Drag a card** to another day, another person, or "Not scheduled" (computer). **Move** on each card opens a form for phones: day, time booked (blank = the estimate), person.
- Moving to another person replaces that person in Action for (others stay). Every move is an audited task update — **Undo** puts it back. Works for imported tasks too (scheduling is a workload field). Closed tasks can't be scheduled.
- Booked time = the time set when scheduling, else the task's estimate.

### Added — Staff & hours
- The people on the schedule (seeded from the names used in Action for: those with an open task or a task in the last 12 months start switched on), the name they're shown as, and the hours available each weekday (6 h Mon–Fri to start). Add people; switch people off (never deleted). Audited; hours changes can be undone.

### Added — Materials
- **Materials** panel on each task: item, supplier and order no, qty, cost and sell price each (ex GST), ordered / received / cancelled; totals and margin.
- Sell price defaults to cost + the **materials markup %** in Billing settings (blank = at cost); type a different price to override.
- **Received** items appear on **Pending Invoicing** as "Material" lines, invoiced like any other line (one INV number for ticked lines); each is charged once, and an invoiced item is locked until that invoice is undone.
- Cancelling needs a reason; an item entered by mistake can be deleted while still only ordered.

### Fixed
- **Desktop sidebar** now reaches Workload, **Month-end claims**, **Billing settings**, **Staff & hours** and **More…** (they were only on the phone's More list).

### Database — migration 006 (`deploy/migrations/006_schedule_materials.*`)
- New columns `tasks.scheduled_on`, `tasks.scheduled_minutes`; new tables `staff` (seeded) and `job_items`; settings `material_markup_pct` (blank) and `work_day_minutes` (360). No existing column or value is changed; v0.13.0 runs normally on the migrated database (tested).

## v0.13.0 · build 20261103.2 — 3 November 2026
**Status: deployed to manager-dev 3 Nov 2026 16:50 and user-confirmed (~17:00); WHMCS import batch 1 applied 16:52. Rollback target for v0.14.0.** Tested locally beforehand: 102/102 on MySQL 8.0.46 (real-data replica) and MariaDB; installed over v0.12.0 by both database routes; real import rehearsed.
Services & billing — the WHMCS replacement (roadmap v0.13, one release as you asked). **Includes database migration 005** (add-only, reversible) and a separate, undoable WHMCS import.

### Added — services register
- **More ▸ Services:** every hosting service, domain, email account, DNS and maintenance item — client, kind, status, price ex GST, billing cycle, Xero account, **next period to invoice**, domain expiry/registrar, cPanel username (never a password), check status. Filters: kind, live/ended, billed/no charge/internal, "Needs a look", overdue, due within the lead time.
- **Service page:** next invoice with its Xero description, every invoiced period (Xero history and manager-recorded), the original WHMCS record (read-only). **Correct the next period date** needs a reason; audited and undoable. Edit and ＋ New service.
- **Client page:** Services & domains with their next renewal, and **Former services** (ended ones and the WHMCS items that were never live).

### Added — Pending Invoicing (for Cathy)
- One list, grouped by client: **renewals** due within 8 weeks or overdue, **ready milestones**, **completed tasks** billed on completion (amount editable), and **month-end claims**. Each line has the suggested Xero description in your patterns ("WEBSITE HOSTING - {plan} - {domain} (dd/mm/yyyy - dd/mm/yyyy)" + Billing Period, "Domain Renewal - … - 1 Year/s", "Email Only", "DNS and Forwarding"), a **Copy** button, the Xero account, and ex/incl GST.
- **Record invoice:** tick the lines on one Xero invoice, enter the Xero number and your name → each line is recorded as invoiced; a service moves on one cycle; a task's billable hours are tied to the charge. **Undo** puts it all back. A period can never be recorded twice; lines of two clients on one invoice are refused.
- **Today** shows "To invoice: n (m overdue)".

### Added — month-end claims, task fees, settings
- **More ▸ Month-end claims:** for tasks billed monthly, the month's unbilled billable hours × hourly rate (editable) and a progress claim on the set fee (capped at what is left) → pending claims on Pending Invoicing. A pending claim can be removed (its hours are freed).
- **More ▸ Billing settings:** lead time (8 weeks), GST, Xero accounts (43504 hosting, 43601 domains, optional task account), hourly rate, and "task fees from" (set to the install date, so old completed tasks don't appear). Audited.

### Added — WHMCS import and archive (`deploy/import-whmcs.php`, run in the Terminal)
- **Archive:** every WHMCS row (135 clients, 209 services, 231 domains — cancelled and expired too) stored read-only with a fingerprint. **No passwords**: the Password column is dropped and note lines that look like logins are removed (68 on your data). Release check confirms the archive holds no password.
- **Services:** the 173 live items (116 billed, 25 internal, 32 no charge — 3 marked "Needs a decision"), on the clients you decided, with the 2 new clients created. Next period = the day after the last period invoiced in Xero (else WHMCS's date; domains: expiry). **175 invoiced periods** loaded from the Xero export.
- Dry run first; the import is one batch that can be undone (refused while an invoice recorded in the manager depends on it). Later WHMCS exports (invoices, payments, transactions, products) can be added as another batch.
- `tools/prepare-whmcs-import.py` (private machine only) builds the import folder from the WHMCS exports, your reconciliation workbook, Cathy's renewals workbook (her "Invoiced now?" and corrected dates are honoured) and the Xero export.

### Fixed
- New template form ignored its default pricing and chargeable values.

### Database — migration 005 (`deploy/migrations/005_services_billing.*`)
- New tables `services`, `charges` (UNIQUE kind + item + period start), `settings`, `archive_whmcs`; new column `work_log.charge_id`. Seeds the settings. No existing column or value is changed; v0.12.0 runs normally on the migrated database (tested).

### Not in this release
- Creating invoices in Xero (Cathy keys them in); WHMCS invoices/payments history (when you export it); product plans; credentials (Joomla access levels, later).

## v0.12.0 · build 20261103.1 — 3 November 2026
**Status: deployed to manager-dev and user-confirmed 3 Nov 2026 (~15:00). Was the rollback target for v0.13.0.** Tested locally beforehand: 91/91 on MySQL 8.0.46 (real-data replica) and MariaDB; installed over v0.11.0 by both database routes.
Pricing & templates (roadmap v0.12), with progressive billing recorded. **Includes database migration 004** (add-only, reversible). Design approved by you on 3 Nov.

### Added — pricing module (one panel on enquiries, tasks and task templates)
- **Price lines:** description × qty × price ex GST, pricing type (set fee / hourly / web service) and an optional account name. As many as needed; edit, move up, remove. Totals ex GST, GST 15% and incl GST.
- On an **enquiry** (the quote), a line can be **for** the whole job, one of its planned tasks, or one of its tasks. It is the same line that then shows on that task, so the enquiry and the task always agree.
- Any **task** can have price lines and a billing choice, including imported tasks (their imported record is not changed).
- **Billing:** on completion (as now), **monthly** (each month end), or **milestones**.
- **Milestones:** name, amount ex GST **or** % of the price, and when it becomes ready to invoice: when the enquiry is accepted (deposit), when its task is signed off (for the whole job: when all its tasks are), on a date, or when marked ready by hand. Readiness follows the record, so reopening a task or setting an enquiry back puts the milestone back to planned. A ready milestone can only be **voided**, with a reason (and restored).
- **Pending Invoicing** now lists **milestones ready to invoice** with their amounts. Invoicing them, and the month-end claims, come in v0.13.

### Added — task templates (replace the "Pro forma" client's tasks)
- **More ▸ Task templates:** title, instructions, category › type, Action for, pricing, chargeable, estimate, plus the template's own price lines and milestones. Archive / restore.
- Migration 004 **copies every open "Pro forma" task into a template** (10 on your data); a set fee becomes the template's price line ($1,800 Upgrade website, $700 Build new website – Stage 1, $600 Stage 3). Line breaks that Fabrik had turned into "rn" are put back.
- **Retire pro-forma tasks** (on the templates screen, once you've checked them): sets those tasks to Cancelled and archives the Pro forma client in one step; **Undo** puts them all back.
- **Make a template from this task** on any task page (copies its price lines and milestones).

### Added — several tasks per enquiry
- **Planned tasks** on the enquiry: **add from a template** (its price lines and milestones come too) or a blank one; edit or remove before it becomes a task.
- **Create tasks:** tick the planned tasks to create; each becomes its own task with its own price lines and milestones. The rest can be created later. Re-posting never makes a second task (row lock + unique key, tested with 6 sessions at once).
- An enquiry **without** planned tasks still becomes one task as before; its whole-job price lines and milestones go with it.
- **Quick Enquiry:** optional **Start from a template** (fills the title; adds it as a planned task).

### Added — task stages
- **Break out into stages** (up to 3 at once) or **Extend — add a stage**. Each stage is its own task (Work Log, sign-off, pricing), copying the client, contact, enquiry, category, Action for and required date. One level only.
- A task with open stages **can't be signed off or completed** until its stages are completed or cancelled (it can still be cancelled). Stages show on the main task and in the Tasks list ("Stage n").

### Changed
- Undo also covers price-line, milestone, template and planned-task edits, the billing choice, and retiring the pro-forma tasks.
- The enquiry page shows planned tasks, the pricing panel and all linked tasks. The task page's old "Pricing & billing" fields are now "Recorded pricing fields" (unchanged).
- Migration runner: can create tables; creates them before adding columns; a database user without CREATE/ALTER gets the phpMyAdmin route.

### Database — migration 004 (`deploy/migrations/004_pricing_templates.*`)
- New tables `task_templates`, `enquiry_task_plans`, `billing_milestones`. New columns: `breakdown_items` template_id, plan_id, line_order, account_name, created_at, updated_at; `enquiries.billing_mode`; `tasks` billing_mode, parent_task_id, stage_no. Copies the open pro-forma tasks into templates and verifies the copy. No existing column or value is changed; v0.11.0 runs normally on the migrated database (tested).

### Not in this release (planned)
- v0.13: services register and WHMCS import, Pending Invoicing for Cathy (renewals, milestones, month-end claims; Xero invoice numbers recorded), charges from the Work Log.

## v0.11.0 · build 20261101.1 — 1 November 2026
**Status: deployed to manager-dev and user-confirmed (reported 3 Nov 2026). Was the rollback target for v0.12.0.** Tested locally beforehand: 75/75 tests on MySQL 8.0.46 (real-data replica) and MariaDB.
First release of the enquiry-to-invoice roadmap (your 20 points, 1 Nov). **Includes database migration 003** (add-only, reversible).

### Added — enquiries
- **Received date** on every enquiry (defaults to today; set an earlier date for enquiries taken by phone). Existing enquiries keep the moment they were saved.
- **Contact** for the enquiry, chosen from the client's contacts, or **＋ New contact** (name, email, phone) added to the client without leaving the form.
- **Category › type** from your account names (as on tasks). Choosing a type **fills the title**, which you can still change.
- **Statuses:** New, Quoted, Waiting, Accepted, Task created, Declined, Withdrawn, Duplicate.
- **Enquiries list:** open enquiries by default; filter by status, category and received dates; search title, client, contact or number; shows received date, contact and type. (Was: last 50, no filters.)
- **Edit after a task is created:** enquiries made in this app stay editable at any status, to fix mis-entries.
- **Withdraw** or **mark as a duplicate of #n**, with a reason, optionally **cancelling the enquiry's open task(s)**.
- **Delete** only for an enquiry with no task (its price lines go with it; a full copy is kept in the audit log). Enquiries with a task are withdrawn or marked duplicate instead.
- Creating a task now also copies the **contact, category, type and received date**.

### Added — tasks
- **Sign off & close:** who, note, date; marks the task completed. Works on imported tasks too (status and completed date were already editable).
- **Reopen** a closed task, with a required reason; the completed date and sign-off are cleared (kept in the history).

### Added — everywhere
- **Cancel** on every form, back to where you came from.
- **Undo** after saving a client, contact, task, work entry or enquiry (including sign-off, reopen, withdraw). Shown once on the next page, usable for 30 minutes. It puts back the previous values only if nothing has changed since; otherwise it refuses and changes nothing. Undoing an undo = **Redo**.

### Changed
- **Time is hours and minutes only** (Log work, Work Log edit, billable time). The timer rounds to the nearest minute (at least 1). Times already stored with seconds keep them unless you change that time.
- Database rollback (`php deploy/migrate.php rollback --confirm`) now undoes **only the latest** migration (`--all` for every one). Previously it undid all of them.

### Database — migration 003 (`deploy/migrations/003_enquiry_signoff.*`)
- Adds 6 columns to `enquiries` (received_at, contact_id, task_category, task_type, duplicate_of, closed_reason) and 3 to `tasks` (signed_off_at, signed_off_by, sign_off_note). Fills received_at from each enquiry's saved time and verifies it. No existing column or value is changed. v0.10.0 runs normally on the migrated database (tested).

### Not in this release (planned)
- Description pre-fill comes with project templates (v0.12), as do several price lines, several tasks per enquiry, and task stages.

## v0.10.0 · build 20261031.2 — 31 October 2026
**Status: deployed to manager-dev 1 Nov 2026 ~11:40 with installer revision 2, and user-confirmed 11:49. Was the rollback target for v0.11.0.** Tested locally beforehand: 62/62 tests on MySQL 8.0.46 and MariaDB, real-data replica and synthetic data.
Editing, to the rules you approved on 31 October. **Includes database migrations 001 and 002** (add-only, reversible; see below).

### Added — from your Fabrik forms (build 2)
- **Task form** grouped like Fabrik (Client details · Administration · Pricing & billing): **contact** (lists the chosen client's contacts), **Action for** tick-boxes (+ anyone else), **queue**, **category → type** (types follow the category, from your archived Fabrik classification table), **domain(s)**; for tasks created here also **chargeable**, **time estimate in hours + minutes**, **job no / order no**, **invoice no / reference**, **billing advised**.
- **Status and priority use your Fabrik words:** Pending, Planned, In progress, Ongoing, Waiting, On hold, Completed, Cancelled (+ “Open (new)” for tasks from enquiries); Immediate, High, Normal, Low. Existing values (e.g. “urgent”) are kept and shown as “(current)”.
- **Imported tasks** can additionally have contact, Action for, queue, category, type and domains updated (workload fields). Billing fields remain locked.
- **Tasks list:** filter by status (incl. each Fabrik status), Action for, priority; sort by newest, required date, priority or **queue**; Immediate tasks flagged.
- **Work Log list:** filter by **staff, client and date range**, with **totals** (entries, worked, billable) for the filtered set; start–finish and imported charge rate shown.
- **Clients list:** filter by **contact role** (e.g. everyone with a Domain owner contact), showing who.
- **CSV export** on Clients, Tasks and Work Log — exactly the filtered list, Excel-friendly (UTF-8), formula-looking cells neutralised.
- **Task page:** contact (tap to call/email), Action for, category › type, queue, domains, and **time-sheet totals** (hours worked / billable; dollar amounts are never recalculated).
- **Client form:** postal region, credit approved, comments. **Contact form:** title.
- **Database migration 002** (add-only, verified, reversible like 001): fills these from your imported records — on your data: task contact 1,345, Action for 1,809, queue 1,828, category 1,813, type 1,725, domains 122.
- Not added (kept visible in each task's original imported record): additional costs, invoice details, Vetta ticket.

### Added — editing
- **Clients:** add and edit name, business name, email, phone, preferred contact, physical and postal address, notes, Xero reference. **Archive / restore** instead of delete. A new client with the same name or email as an existing one is stopped once ("Create anyway" to proceed), to keep one record per client for the WHMCS reconciliation.
- **Contacts:** add, edit, archive; contact roles as tick-boxes (Accounts, Project liaison, Domain owner/admin/tech, plus your own); **move a contact to another client**; the contacts that had no client in the old system are listed under Clients ▸ "Contacts without a client" so each can be given one.
- **Tasks:** **create tasks directly** (＋ Task on a client page or Tasks list), with pricing type, agreed fee and time estimate. Tasks created here: everything editable. **Imported tasks:** status, completed date, due date, priority, notes and client can now be updated — so the old "in progress" backlog can be closed. Their title, instructions and historical billing (pricing, amounts, invoice references) stay locked.
- **Work Log:** entries created here are fully editable (description, date, duration, client, task, billing decision, billable time, staff). **Imported entries** can be linked to a task or client and given a billing decision; their description, time and amounts stay locked.
- **Enquiries:** the client of an unconverted enquiry can be changed.
- Every create/update writes an audit event recording each changed field's previous and current value. **Two people editing the same record:** the second save is refused with a clear message instead of silently overwriting.
- Client page: read-only **"Original imported record"** panel showing every value exactly as imported.

### Database — migration 001 (`deploy/migrations/`)
- Adds 16 columns to `clients` and 10 to `contacts` and fills them from each imported row's original record (339 clients and 524 contacts on your data). **No existing column or value is changed**; the original record stays untouched as provenance.
- The installer applies it automatically after backing up, if the database user may change table structure; otherwise it stops and gives you a one-paste phpMyAdmin step. Every filled value is verified against its source before the migration is recorded.
- Undo: `php deploy/migrate.php rollback --confirm` or the `.rollback.sql` file — tested to restore the database byte-for-byte. Not needed to roll back the app: v0.9.0 runs normally on the migrated database (tested).

### Fixed
- Pre-chosen client was not filled in on New contact / New task / New client forms (found by the new tests before release).
- "Domains" shown on the client page was a 0/1 flag from the old system, not domain names — removed.
- Installer: reads the website's PHP version from cPanel MultiPHP (stops with instructions if below 8.0); version number now read from the package; empty server folders (e.g. `.well-known`) carried over; migration SQL files now included in the package.
- The app shows a plain "needs PHP 8 — set ea-php83 in MultiPHP Manager" page instead of a generic error if PHP is ever below 8.0.
- Today labels tasks due today as "Due today".
- The instant finder refreshes immediately after a save (it previously cached for up to 10 minutes).

### Installer revision 2 (package `…-installer2.zip`, 1 November 2026)
Application files unchanged (same build 20261031.2). The first dry run on the server stopped with "PHP 7.4.33 syntax error in edit-work.php": one of the server's `ea-php8*` paths actually runs PHP 7.4, and the new code uses PHP 8 syntax. The installer now runs its checks and migrations with the website's own MultiPHP version (ea-php83), and syntax-checks only with programs that report PHP 8 or later (on the server: 8.3, 8.0, 8.1, 8.2, 8.5).

## v0.9.0 · build 20261030.3 — 30 October 2026
**Status: deployed to manager-dev 31 Oct 2026 00:30 and user-confirmed 00:55** (Today, Release check, finder, phone Call, Log work). Site switched to PHP 8.3 (was 7.4).
Foundation: the v0.8.1 server snapshot you supplied, plus the v0.8.2 candidate's Today/More changes. Nothing from either was discarded; every route and write path still exists. **No database changes.**

### Fixed
- **Forms rendered unstyled on the server.** The security policy (`style-src 'self'`) blocks inline `<style>` blocks and `style=""` attributes, which Log work, Quick Enquiry, the enquiry/convert page and both edit pages relied on. On a phone they appeared as raw HTML and Log work scrolled sideways. All styling now lives in `assets/app.css`; there is no inline CSS or JS anywhere (verified by test: 0 policy violations on every screen).
- **Today's "needs attention" list was dominated by 2016–2021 imported tasks** still marked "in progress". Tasks overdue by more than 60 days are now folded into one "Older open backlog (n)" link.
- **Pending Invoicing counted all 2,172 imported work entries as "for review"** (they were all imported with `billability=review`). Counts now cover entries created in this app only, and say so.
- **Client search was case-sensitive for business names and client emails on MySQL 8** (JSON values come back with a binary collation there, unlike MariaDB). Found by testing on MySQL 8.0.46; fixed with `LOWER()` on both sides.
- Bottom navigation scrolled sideways with 7 items; it is now a fixed 5-item bar (Today · Clients · Tasks · Work · More) on every page, including the forms.
- The floating version badge covered list content on phones; the version is now in the header on every page.

### Added
- **Instant client finder** on Today and Clients: results as you type (about 10 ms once loaded) across client name, business name, contact names, email addresses and phone digits (spaces ignored); shows which contact matched; arrow keys + Enter; "See all n matches". Works without JavaScript via server search (which also matches contacts).
- **Recent clients** (last 6 opened) under the finder — stored on the device only and cleared on Sign out.
- **Client page rebuilt for phones:** Call / Email / ＋ Log work / ＋ Enquiry in one row; each contact has its own tap-to-call (mobile and phone separately) and email buttons; open tasks with one-tap "Log work"; completed/cancelled tasks folded; activity timeline (work, completed tasks, enquiries) with All/Work/Tasks/Enquiries filter; client details, addresses and Xero reference folded; honest "Services & domains" panel (WHMCS data not yet imported).
- **Today:** finder; quick actions (＋ Log work, ＋ Enquiry, Open tasks); one "Needs attention" queue combining overdue/due tasks (≤7 days ahead, ≤60 days overdue), enquiry follow-ups, enquiries not yet converted and new open tasks without dates — each with a direct action; Work to review (new entries needing a billing decision / with no task); Recent work with "Log again". No record-count tiles.
- **Log work:** log directly against a client (with or without a task); the task list follows the chosen client (open tasks first); search all tasks; one-tap duration presets (15m–1h 30m); **timer mode** restored from v13 (keeps running across page reloads and phone lock; stopping fills the duration); staff name remembered for the session; after saving, links back to the task or client.
- **Quick Enquiry:** opens with the client pre-chosen from a client page; client type-ahead instead of a 365-item drop-down; after saving, a direct "Review / create task" link.
- **Tasks:** Open / Completed-cancelled / All filters (default Open) and "Sort by due date"; task page links to its client and has one-tap Log work.
- **Work Log:** filters for New entries / Needs billing decision / No task (used by Today's links).
- **Release check** (More ▸ Release check): shows the running build and verifies every table/column this version relies on, so a deploy can be confirmed without phpMyAdmin.
- Sign-in returns you to the page you were trying to open.

### Changed (structure)
- One shared bootstrap (`inc/bootstrap.php`) is now the **only** place the private config path, session settings, security headers and database connection are defined. Each screen lives in its own file (`inc/pages/*.php`), so changing one screen can no longer silently drop another (the v0.8.x failure mode). `inc/` is denied to the web by `.htaccess` and each file also refuses direct requests.
- Retired: `m7-bootstrap.php` (merged into the shared bootstrap), `assets/m5-work.js` (merged into `assets/app.js`).

### Unchanged (deliberately)
- Login, session name `cloake_m4`, cookie flags, CSP and other headers; the private config path.
- Every write path's validation rules, columns, transactions, CSRF tokens and audit events: Log work, Quick Enquiry, enquiry → task conversion (row lock + re-check + unique key), edit task, edit enquiry. Edit pages' logic is byte-identical to v0.8.2.
- Imported (Fabrik) tasks and work entries remain read-only. Billing remains review-only; no Xero calls exist in the code.

### Installer revision 2 (package `…-installer2.zip`, 31 October 2026)
Application files unchanged (same build 20261030.3). `deploy/install.sh` fixed after the first dry run on the server:
- cPanel's jailed shell (CageFS) has no `/dev/fd`, so three loops silently ran zero times — the dry run reported "syntax OK" without checking and would have carried 0 server files (losing `.well-known/` and the live `.htaccess`). Loops now read plain temp files, and every check fails loudly if it inspected nothing.
- The CLI `php` on the server is 7.4; the script now picks cPanel's EA PHP 8 automatically and syntax-checks with **every** installed PHP 8 version (8.0–8.5).

## v0.8.2 · build 20261030.2 — candidate, never confirmed installed
Compact Today (no counts) and a 5-item bottom nav with a More page. Included in v0.9.0.

## v0.8.1 · build 20261030.1 — last server snapshot you supplied
Baseline for v0.9.0.
