# Test report — v0.10.0 · build 20261031.1

**Result: 54 of 54 tests passed in each of 4 runs** (MySQL 8.0.46 and MariaDB; your real data replica and synthetic data; plus the installed package). Zero PHP warnings, zero security-policy violations, zero JavaScript errors in every run.
Run in a private workspace, **not** on your server. Deployed / user-confirmed status: pending.

## 1. Runs
| Database | Data | Code under test | Result |
|---|---|---|---|
| MariaDB 10.11 | Synthetic fixture | dev tree | **54/54** |
| MySQL 8.0.46 | Real-data replica, migrated by deploy/migrate.php | dev tree | **54/54** |
| MariaDB 10.11 | Real-data replica, migrated by deploy/migrate.php | dev tree | **54/54** |
| MariaDB 10.11 | Real-data replica, migrated via the phpMyAdmin SQL file | **installed by deploy/install.sh** over v0.9.0 (jailed shell, no /dev/fd) | **54/54** |

## 2. New in this release (13 tests; the other 41 from v0.9.0 still pass)
| ID | Proven |
|---|---|
| X01 | Edit an imported client → new columns updated, **original record unchanged**, audit has previous/current per field, client page shows it, finder finds the new phone immediately |
| X02 | Archive → tag shown, hidden from Active, found under All; restore → audited "Client restored" |
| X03 | New client is an app record; same name again blocked until "Create anyway" |
| X04 | Add contact with role tick-boxes; move to another client → audited on both clients |
| X05 | Contact with no client gets one from Clients ▸ "Contacts without a client"; original record unchanged |
| X06 | Two people edit the same client → second save refused, first person's change kept |
| X07 | Imported task: status/completed/notes save; **forged POST changing title, instructions, fee, pricing has no effect** |
| X08 | New task from a client page with pricing; invalid fee refused |
| X09 | New work entry: duration, client, billing decision, billable time saved; billable > worked refused |
| X10 | Imported work entry: task link + billing decision save; **forged POST changing description/time/staff has no effect** |
| X11 | Unconverted enquiry: client changed, audited |
| X12 | Giving an entry a billing decision removes it from Today's "Work to review"; returns to the filtered list |
| P01 (revised) | After all edits: original records of all imported clients/contacts, **locked fields** of all imported tasks (title, instructions, pricing, fees, invoice fields…) and work entries (description, durations, amounts, dates, staff), and all six legacy archives **byte-for-byte unchanged** — on the replica: 365 / 524 / 1,828 / 2,172 rows + 5,117 archive rows |

Also re-checked at 320/375/390/430/1280 px: the 8 new edit screens (26 screens in total).

## 3. Database migration 001
| Check | Result |
|---|---|
| Runner, real data on MySQL 8.0.46 | 26 columns added; 339 clients / 524 contacts filled; every non-empty source value present and identical |
| Re-run | "already applied", no changes |
| App DB user without ALTER | refuses cleanly (exit 3) with phpMyAdmin instructions; nothing changed |
| `rollback --confirm` | database **byte-identical** to before (mysqldump compare) |
| phpMyAdmin `.apply.sql` then `.rollback.sql` | applied, recognised by the runner, then **byte-identical** restore |
| v0.9.0 code on the migrated database | Today, client, clients search, Log work, lookup all load (so an app rollback needs no DB rollback) |

## 4. Install rehearsal (simulated server: v0.9.0 live on ea-php83, no /dev/fd, CLI php 7.4, real data)
- Dry run: reports website PHP ea-php83, pending migration, same carried server files. ✔
- Install with a DB user that **cannot** change structure: stops before anything changes, names the SQL file. ✔ → SQL run (as in phpMyAdmin) → re-run: "schema up to date", installed, verified. ✔ → full suite 54/54 on the installed copy. ✔
- Install with a DB user that **can**: backup → migration applied + verified → installed → verified. ✔
- `rollback.sh` → v0.9.0 verified, working on the migrated database. ✔

## 5. Found and fixed during testing
| Problem | Fix |
|---|---|
| Pre-chosen client not filled in on New contact / New task / New client (PHP `+` array merge kept the empty default) | `array_merge`; covered by X03, X04, X08 |
| Migration SQL files missing from the package (repository ignore rule) | Included; rehearsal confirmed |
| Finder cached the client list for 10 min, so an edited phone wasn't searchable | Cache cleared after any save; X01 |
| Installer had the version number hard-coded | Read from the package |

## 6. Not tested — needs your server / phone
- Whether your database user may change table structure (either way is handled: automatic, or one phpMyAdmin paste).
- Real phones (emulated at phone sizes). Apache/LiteSpeed specifics, as before.

## Appendix — fixture run detail
| ID | Test | Result | Notes |
|---|---|---|---|
| S01 | Signed-out visitors cannot reach any screen, form or lookup | PASS (49 ms) |  |
| S02 | Internal PHP files return nothing when requested directly | PASS (23 ms) | Apache also denies inc/ via inc/.htaccess (not exercised by the PHP built-in server). |
| S03 | Security headers present on pages, forms and lookups | PASS (366 ms) |  |
| S04 | Sign-in: wrong password rejected; correct password opens Today; cookie flags | PASS (1495 ms) |  |
| S05 | Deep link survives sign-in; external redirect targets are ignored | PASS (587 ms) |  |
| L320 | Every screen at 320px: loads, no sideways scroll, no CSP/JS errors, navigation usable, content clear of nav | PASS (1458 ms) | 26 screens checked |
| L375 | Every screen at 375px: loads, no sideways scroll, no CSP/JS errors, navigation usable, content clear of nav | PASS (5738 ms) | 26 screens checked |
| L390 | Every screen at 390px: loads, no sideways scroll, no CSP/JS errors, navigation usable, content clear of nav | PASS (1358 ms) | 26 screens checked |
| L430 | Every screen at 430px: loads, no sideways scroll, no CSP/JS errors, navigation usable, content clear of nav | PASS (1512 ms) | 26 screens checked |
| L1280 | Every screen at 1280px: loads, no sideways scroll, no CSP/JS errors, navigation usable, content clear of nav | PASS (10049 ms) | 26 screens checked |
| F01 | Typing a client name on Today shows matching clients instantly | PASS (370 ms) | first results after 180 ms (includes typing and one-time index load); with index cached: 10 ms |
| F02 | Finder matches a contact person and shows which contact matched | PASS (18 ms) |  |
| F03 | Finder matches phone digits (any spacing) | PASS (15 ms) |  |
| F04 | Finder matches an email fragment | PASS (34 ms) |  |
| F05 | Keyboard: Arrow + Enter opens the client; it then appears under Recent on Today | PASS (139 ms) |  |
| F06 | "See all" appears when more than 8 clients match | PASS (44 ms) |  |
| F07 | Without JavaScript, server search finds clients by contact name | PASS (323 ms) |  |
| F08 | Server search is case-insensitive for business names and client emails (MySQL 8 JSON collation) | PASS (379 ms) | client email: "OFFICE" found |
| C01 | Client page: call, email, log work and enquiry within one tap; contacts and open tasks match database | PASS (163 ms) |  |
| C02 | Activity filter chips show only the chosen kind | PASS (133 ms) |  |
| W01 | Log work from a client page: client pre-chosen, 30m preset, saved with correct fields and audit event | PASS (597 ms) |  |
| W02 | Log work from a task with start/finish times: task and its client recorded | PASS (341 ms) |  |
| W03 | Server refuses a task that belongs to a different client (nothing saved) | PASS (240 ms) |  |
| W04 | Validation: finish before start, zero duration, billable over worked — all rejected, nothing saved | PASS (868 ms) |  |
| W05 | Timer: start, survives reload, stop fills duration, saved as exact seconds | PASS (3061 ms) |  |
| W06 | Double-tapping Save creates exactly one entry | PASS (458 ms) |  |
| W07 | Task search on Log work finds a task and records its client | PASS (649 ms) |  |
| E01 | Quick Enquiry from a client page: client pre-chosen; follow-up and breakdown saved with audit event | PASS (481 ms) |  |
| E02 | Quick Enquiry without a client shows a clear message and saves nothing | PASS (521 ms) |  |
| E03 | Today lists the new enquiry follow-up | PASS (76 ms) |  |
| V01 | Convert enquiry to task: one open task, enquiry marked, audit event, lands on task | PASS (210 ms) |  |
| V02 | Re-opening or re-posting a converted enquiry never creates a second task | PASS (98 ms) |  |
| V03 | Concurrency: 8 separate sessions convert the same enquiry at once → exactly one task | PASS (2892 ms) | responses: 303,303,303,303,303,303,303,303 → index.php?page=task&id=2050 |
| D01 | Edit an enquiry-generated task: saved, completed date set, change audited | PASS (210 ms) |  |
| D03 | Converted enquiries cannot be edited; unconverted ones save with an audit event | PASS (236 ms) |  |
| X01 | Edit an imported client: saved to the new columns, original record untouched, audited, finder finds the new phone at once | PASS (495 ms) |  |
| X02 | Archive and restore a client: tag shown, hidden from Active list, still found under All | PASS (557 ms) |  |
| X03 | New client: created as an app record; same name again is blocked until "Create anyway" | PASS (640 ms) |  |
| X04 | Add a contact (types as checkboxes), then move it to another client: both clients audited | PASS (499 ms) |  |
| X05 | A contact with no client can be given one from the Clients page | PASS (288 ms) |  |
| X06 | Two people editing the same client: the second save is refused, nothing is overwritten | PASS (647 ms) |  |
| X07 | Imported task: status/completed/notes/client save; title, instructions and billing stay locked even to a forged POST | PASS (347 ms) |  |
| X08 | New task from a client page with pricing; invalid fee refused | PASS (426 ms) |  |
| X09 | Edit a new Work Log entry: duration, client, billing decision and billable time saved; billable > worked refused | PASS (476 ms) |  |
| X10 | Imported Work Log entry: task link and billing decision save; description and time stay locked even to a forged POST | PASS (331 ms) |  |
| X11 | Unconverted enquiry: client can be changed, audited | PASS (179 ms) |  |
| X12 | Giving a new entry a billing decision removes it from Today's "Work to review" | PASS (383 ms) |  |
| T01 | Today: no record-count tiles; queue items are real open/due records; backlog count matches database | PASS (114 ms) | queue items: 6; due/overdue in window: 1; stale backlog: 15 |
| T02 | Today "Work to review" counts match new (non-imported) entries in the database | PASS (169 ms) |  |
| M01 | Lookup API: client index, tasks for one client only, task search needs 2+ characters | PASS (41 ms) |  |
| M02 | Release check page: all schema checks pass and shows this build | PASS (94 ms) |  |
| M03 | Keyboard: first Tab reaches "Skip to content"; finder shows a visible focus ring | PASS (384 ms) |  |
| M04 | Sign out clears device-side client traces and ends the session | PASS (168 ms) |  |
| P01 | Original imported records, locked task/work fields and all six legacy archives are byte-for-byte unchanged after every edit test | PASS (118 ms) | clients_original=60, contacts_original=121, fabrik_tasks_locked=240, fabrik_work_locked=480, legacy_clients=0, legacy_contacts=0, legacy_tasks=0, legacy_work_log=0, legacy_classification=0, legacy_enquiries=0 |

---
_Previous release's report (v0.9.0) is in the Git history._
