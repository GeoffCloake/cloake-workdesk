# Changelog — Cloake Client & Services Manager

Versions are shown on every screen (header) and on the sign-in page as `v<version> · build <build>`.
Status words used below: **built** (code exists) · **tested locally** (automated tests passed here) · **deployed** (installed on manager-dev) · **user-confirmed** (you have checked it on the server).

## v0.9.0 · build 20261030.3 — 30 October 2026
**Status: built and tested locally. Not yet deployed. Not yet user-confirmed.**
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

## v0.8.2 · build 20261030.2 — candidate, never confirmed installed
Compact Today (no counts) and a 5-item bottom nav with a More page. Included in v0.9.0.

## v0.8.1 · build 20261030.1 — last server snapshot you supplied
Baseline for v0.9.0.
