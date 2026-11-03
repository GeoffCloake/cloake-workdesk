# Changelog — Cloake Workdesk (formerly Cloake Client & Services Manager)

Versions are shown on every screen (header) and on the sign-in page as `v<version> · build <build>`.
Status words used below: **built** (code exists) · **tested locally** (automated tests passed here) · **deployed** (installed on manager-dev) · **user-confirmed** (you have checked it on the server).

## v0.15.0 · build 20261103.4 — 3 November 2026
**Status: built and tested locally; first release installed from GitHub with `cm-update v0.15.0`.** Tested: 113/113 on MySQL 8.0.46 (real-data replica) and MariaDB (fixture); installed over v0.14.0 through `cm-update` (git route). **No database change.** Rollback target: v0.14.0.
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
