# Changelog — Cloake Client & Services Manager

Versions are shown on every screen (header) and on the sign-in page as `v<version> · build <build>`.
Status words used below: **built** (code exists) · **tested locally** (automated tests passed here) · **deployed** (installed on manager-dev) · **user-confirmed** (you have checked it on the server).

## v0.11.0 · build 20261101.1 — 1 November 2026
**Status: built and tested locally (74/74 tests on MySQL 8.0.46 with your real-data replica, and on MariaDB with synthetic data). Not yet deployed. Not yet user-confirmed.**
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
**Status: deployed to manager-dev 1 Nov 2026 ~11:40 with installer revision 2, and user-confirmed 11:49. Rollback target for v0.11.0.** Tested locally beforehand: 62/62 tests on MySQL 8.0.46 and MariaDB, real-data replica and synthetic data.
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
