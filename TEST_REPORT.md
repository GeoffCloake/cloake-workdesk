# Test report — v0.9.0 · build 20261030.3

**Result: 43 of 43 tests passed in each of 5 runs** (2 database engines × synthetic and real-data replica, plus the installed package).
All runs: zero PHP warnings/notices in the server log; zero Content-Security-Policy violations; zero JavaScript errors.

**Where this was run:** a private Linux workspace (PHP 8.3.6 built-in server with 8 workers, Chromium 153 via Playwright). **Not** on your server. Deployed and user-confirmed status are still pending — see "Not tested" below.

## 1. Runs

| Database engine | Data | Code under test | Result | When |
|---|---|---|---|---|
| MariaDB 10.11.14 | Synthetic fixture (60 fictional clients, edge cases) | dev tree | **43/43** | 2026-10-30 08:46 UTC |
| MySQL 8.0.46 | Synthetic fixture | dev tree | **43/43** | 2026-10-30 08:47 UTC |
| MariaDB 10.11.14 | Real-data replica (365 clients, 524 contacts, 1,831 tasks, 2,173 work) | dev tree | **43/43** | 2026-10-30 08:46 UTC |
| MySQL 8.0.46 | Real-data replica, rebuilt by replaying your M2→M3B→M3C→M3D scripts | dev tree | **43/43** | 2026-10-30 08:45 UTC |
| MariaDB 10.11.14 | Synthetic fixture | **installed by deploy/install.sh** from the final ZIP over v0.8.2 | **43/43** | 2026-10-30 08:48 UTC |

**Real-data replica.** Built by replaying the actual migration packages you supplied (M2 schema → M3B archive → M3C clients/contacts → M3D tasks/work log), then the M5 `work_date` column and the rows from your staging tests (enquiries → tasks #2048–2050, work entry #4096). Its counts match your verified checkpoints exactly, including 2 tasks without a client, 5 work entries without a client and 59 without a task. Results of real-data runs are summarised here only; no client details appear in this report or the package. Screenshots in this release use the fictional fixture only.

## 2. What the 43 tests cover

| Group | Tests | What is proven |
|---|---|---|
| Security & sign-in | S01–S05 | Signed-out requests to every screen, form, POST and lookup are refused; internal files return nothing; CSP/X-Frame-Options/nosniff/no-store on every response; wrong password rejected; cookie `cloake_m4` is HttpOnly, Secure, SameSite=Strict, Path=/; deep links survive sign-in; off-site redirect attempts ignored |
| Phone & desktop layout | L320, L375, L390, L430, L1280 | 18 screens × 5 widths: HTTP 200, no sideways scroll, no CSP/JS errors, version label visible, 5-item bottom nav fits with ≥44 px tap targets, last content never hidden behind the nav, desktop sidebar instead of bottom nav |
| Instant client finder | F01–F08 | Matches by client name, contact name (and shows which contact), phone digits with any spacing, email fragment, business name; keyboard Arrow/Enter; Recent clients; "See all"; works without JavaScript; case-insensitive on MySQL 8 |
| Client page | C01–C02 | Call (tel:), Email, Log work and Enquiry links; per-contact call links; contact and open-task counts equal the database; activity filter |
| Log work | W01–W07 | From client page (client pre-chosen, task list limited to that client) and from a task; exact database row checked (client, task, seconds, billability `review`, `source_system=m5`, NZ `work_date`, audit event); task/client mismatch refused; invalid times, zero duration, billable > worked refused with nothing saved; timer survives a reload and saves exact seconds; double-tap Save creates exactly one row; task search |
| Quick Enquiry | E01–E03 | Client pre-chosen; follow-up and breakdown line saved with audit event; no-client submit blocked with a message; follow-up appears on Today |
| Conversion | V01–V03 | One task, enquiry marked `task_created`, one audit event; re-opening shows "Already progressed"; stale re-post refused (403); **8 separate signed-in sessions converting the same enquiry simultaneously → exactly one task**, all landing on it |
| Edits & protection | D01–D03 | Enquiry-generated task edit saves with audit payload; imported Fabrik task refuses a forged POST and is unchanged; converted enquiry not editable; unconverted enquiry edit audited |
| Today | T01–T02 | No count tiles; due/overdue items equal the database; backlog count equals the database; "Work to review" counts and drill-down equal the database |
| Misc | M01–M04 | Lookup API scoping and input checks; Release check passes; keyboard skip link and focus ring; Sign out clears device-side traces and ends the session |
| Data protection | P01 | After all write tests: imported clients, contacts, Fabrik tasks, Fabrik work entries and all six `legacy_*` archives (5,117 rows on the replica) are byte-for-byte unchanged |

## 3. Proof the tests can fail (mutation tests)
- Removed the conversion row lock and in-transaction re-check → V03 **failed** (requests no longer all landed on one task).
- Additionally dropped the tasks unique key in the test DB → V03 **failed with 7 duplicate tasks**.
- Code restored from Git and re-verified. The real code relies on the row lock, with the unique key as a database backstop.

## 4. Problems found and fixed during this work
| Found in | Problem | Fix |
|---|---|---|
| v0.8.2 baseline run (9 CSP violations) | Inline CSS blocked by the security policy → unstyled forms, Log work wider than the phone | All CSS in `app.css`; test L* enforces zero violations |
| v0.8.2 baseline | Today dominated by 2016–2021 "in progress" imports | 60-day window + folded backlog |
| Code review | Pending Invoicing counted 2,172 imported entries as "for review" | Counts new entries only |
| MySQL 8 run (test F08 failed) | Business-name/email search case-sensitive on MySQL 8 | `LOWER()` both sides; F08 now passes on both engines |
| Deploy test | After an automatic rollback, the "last previous" pointer was left stale | Cleared in that path |

## 5. Deployment scripts (tested on a simulated copy of your cPanel layout)
Simulated `/home/cloakecrea/public_html/manager-dev.cloakecreative.co.nz` containing your v0.8.1 snapshot (and separately the v0.8.2 candidate) plus a cPanel PHP-handler block in `.htaccess`, `.user.ini`, `.well-known/acme-challenge/`, `error_log`.
- `--dry-run`: all checks, no changes. ✔
- Install: database backup created (mode 600) and **restored successfully into a scratch database**; server files preserved (handler block, `.user.ini`, `.well-known`); retired files dropped; swap; version verified. ✔
- `rollback.sh`: site back on v0.8.1, verified. ✔
- Forced failure (verification URL unreachable): **automatic rollback**, live stayed on v0.8.1, failed release kept for inspection. ✔
- Final package installed over v0.8.2, then the full suite run against the installed copy: 43/43; installed application files byte-identical to the repository. ✔

## 6. Not tested — needs your server or your phone
- **Your actual server**: Apache/LiteSpeed behaviour (the `inc/.htaccess` deny is backed up by a PHP-level 404 guard, tested), its PHP version and extensions, HTTPS cookie behaviour on the real domain, whether cPanel Terminal and `mysqldump` are available to your account (Option B in INSTALL.md avoids both).
- **Real devices**: iOS Safari and Android Chrome were emulated with Chromium at phone sizes, not run on physical phones. Native `tel:`/`mailto:` hand-off, date pickers and on-screen keyboard behaviour should be glanced at once on your phone.
- **The live database's exact `work_date` DDL** (not supplied). The code only needs the column to exist; More ▸ Release check verifies it on the server.
- Not in scope for this release and therefore not tested: WHMCS services/domains import, renewals, Xero, invoice drafting.

## 7. Re-running
See README.md ▸ Running the tests. The suite selects its test clients/tasks from whatever database it is pointed at, so the same 43 tests run on fixture or replica.

## Appendix — fixture run detail (MariaDB 10.11, fictional data)
| ID | Test | Result | Notes |
|---|---|---|---|
| S01 | Signed-out visitors cannot reach any screen, form or lookup | PASS (49 ms) |  |
| S02 | Internal PHP files return nothing when requested directly | PASS (25 ms) | Apache also denies inc/ via inc/.htaccess (not exercised by the PHP built-in server). |
| S03 | Security headers present on pages, forms and lookups | PASS (392 ms) |  |
| S04 | Sign-in: wrong password rejected; correct password opens Today; cookie flags | PASS (1508 ms) |  |
| S05 | Deep link survives sign-in; external redirect targets are ignored | PASS (666 ms) |  |
| L320 | Every screen at 320px: loads, no sideways scroll, no CSP/JS errors, navigation usable, content clear of nav | PASS (1157 ms) | 18 screens checked |
| L375 | Every screen at 375px: loads, no sideways scroll, no CSP/JS errors, navigation usable, content clear of nav | PASS (4563 ms) | 18 screens checked |
| L390 | Every screen at 390px: loads, no sideways scroll, no CSP/JS errors, navigation usable, content clear of nav | PASS (1188 ms) | 18 screens checked |
| L430 | Every screen at 430px: loads, no sideways scroll, no CSP/JS errors, navigation usable, content clear of nav | PASS (1136 ms) | 18 screens checked |
| L1280 | Every screen at 1280px: loads, no sideways scroll, no CSP/JS errors, navigation usable, content clear of nav | PASS (8220 ms) | 18 screens checked |
| F01 | Typing a client name on Today shows matching clients instantly | PASS (365 ms) | first results after 180 ms (includes typing and one-time index load); with index cached: 9 ms |
| F02 | Finder matches a contact person and shows which contact matched | PASS (23 ms) |  |
| F03 | Finder matches phone digits (any spacing) | PASS (24 ms) |  |
| F04 | Finder matches an email fragment | PASS (17 ms) |  |
| F05 | Keyboard: Arrow + Enter opens the client; it then appears under Recent on Today | PASS (116 ms) |  |
| F06 | "See all" appears when more than 8 clients match | PASS (39 ms) |  |
| F07 | Without JavaScript, server search finds clients by contact name | PASS (337 ms) |  |
| F08 | Server search is case-insensitive for business names and client emails (MySQL 8 JSON collation) | PASS (376 ms) | client email: "OFFICE" found |
| C01 | Client page: call, email, log work and enquiry within one tap; contacts and open tasks match database | PASS (140 ms) |  |
| C02 | Activity filter chips show only the chosen kind | PASS (137 ms) |  |
| W01 | Log work from a client page: client pre-chosen, 30m preset, saved with correct fields and audit event | PASS (574 ms) |  |
| W02 | Log work from a task with start/finish times: task and its client recorded | PASS (311 ms) |  |
| W03 | Server refuses a task that belongs to a different client (nothing saved) | PASS (211 ms) |  |
| W04 | Validation: finish before start, zero duration, billable over worked — all rejected, nothing saved | PASS (881 ms) |  |
| W05 | Timer: start, survives reload, stop fills duration, saved as exact seconds | PASS (2983 ms) |  |
| W06 | Double-tapping Save creates exactly one entry | PASS (481 ms) |  |
| W07 | Task search on Log work finds a task and records its client | PASS (663 ms) |  |
| E01 | Quick Enquiry from a client page: client pre-chosen; follow-up and breakdown saved with audit event | PASS (497 ms) |  |
| E02 | Quick Enquiry without a client shows a clear message and saves nothing | PASS (530 ms) |  |
| E03 | Today lists the new enquiry follow-up | PASS (78 ms) |  |
| V01 | Convert enquiry to task: one open task, enquiry marked, audit event, lands on task | PASS (214 ms) |  |
| V02 | Re-opening or re-posting a converted enquiry never creates a second task | PASS (110 ms) |  |
| V03 | Concurrency: 8 separate sessions convert the same enquiry at once → exactly one task | PASS (3155 ms) | responses: 303,303,303,303,303,303,303,303 → index.php?page=task&id=2050 |
| D01 | Edit an enquiry-generated task: saved, completed date set, change audited | PASS (217 ms) |  |
| D02 | Imported (Fabrik) tasks stay read-only, even to a forged POST | PASS (154 ms) |  |
| D03 | Converted enquiries cannot be edited; unconverted ones save with an audit event | PASS (253 ms) |  |
| T01 | Today: no record-count tiles; queue items are real open/due records; backlog count matches database | PASS (118 ms) | queue items: 6; due/overdue in window: 2; stale backlog: 15 |
| T02 | Today "Work to review" counts match new (non-imported) entries in the database | PASS (168 ms) |  |
| M01 | Lookup API: client index, tasks for one client only, task search needs 2+ characters | PASS (49 ms) |  |
| M02 | Release check page: all schema checks pass and shows this build | PASS (91 ms) |  |
| M03 | Keyboard: first Tab reaches "Skip to content"; finder shows a visible focus ring | PASS (388 ms) |  |
| M04 | Sign out clears device-side client traces and ends the session | PASS (180 ms) |  |
| P01 | Imported clients, contacts, tasks, work entries and all six legacy archives are byte-for-byte unchanged | PASS (126 ms) | clients=60, contacts=121, fabrik_tasks=240, fabrik_work=480, legacy_clients=0, legacy_contacts=0, legacy_tasks=0, legacy_work_log=0, legacy_classification=0, legacy_enquiries=0 |
