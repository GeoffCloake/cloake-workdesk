# Test report — v0.11.0 · build 20261101.1

**Result: 75 of 75 tests passed** on your real-data replica (MySQL 8.0.46) and on synthetic data (MariaDB), and on a copy installed by `deploy/install.sh` over v0.10.0 by both database routes. Zero PHP warnings, zero security-policy violations and zero JavaScript errors in every run.
Run in a private workspace, **not** on your server. Deployed / user-confirmed status: pending.

## 1. Runs
| Database | Data | Code under test | Result |
|---|---|---|---|
| MariaDB 10.11 | Synthetic fixture, migrations 001–003 by deploy/migrate.php | dev tree | **75/75** |
| MySQL 8.0.46 | Real-data replica, migrations 001–003 by deploy/migrate.php | dev tree | **74/74** (before N13 was added) |
| MySQL 8.0.46 | Real-data replica at the server's state (v0.10.0, 001+002); 003 via the **phpMyAdmin SQL file** | **installed by deploy/install.sh** over v0.10.0 | **74/74** (before N13) |
| MySQL 8.0.46 | Same; 003 applied **automatically** by the installer | **installed by deploy/install.sh** (final package) | **75/75** |

## 2. New in this release (13 tests; the 62 from v0.10.0 still pass, 8 of them updated for the new behaviour)
| ID | Proven |
|---|---|
| N01 | Quick Enquiry: received date (3 days ago) saved; category › type fills the title; ＋ New contact created as an app contact of that client and linked; enquiry and contact both audited |
| N02 | A future received date, or a contact belonging to another client (forged), is refused and nothing is saved |
| N03 | Enquiries list: open by default (withdrawn hidden); status filter count equals the database; search by contact name; received-date filter count equals the database |
| N04 | Withdraw with reason + cancel the open task → enquiry withdrawn, task cancelled, both audited; **Undo** restores the enquiry's status and clears the reason; **Redo** withdraws it again; the undo is audited |
| N05 | Mark duplicate of an unknown number refused (nothing changed); of a real enquiry saved; list shows "Duplicate of #n" |
| N06 | Delete an enquiry with no task → it and its price line are gone, the audit keeps a full copy; an enquiry with a task offers no delete and a **forged delete POST is refused** |
| N07 | Create task copies contact, category, type and received date exactly |
| N08 | Sign off (who + note) → completed, completed date, sign-off saved and shown; reopen **without** a reason (forged) refused with a message; reopen with a reason → in progress, completed date and sign-off cleared, audited |
| N09 | An imported task can be signed off; Undo puts status and completed date back exactly (its locked fields are checked by P01) |
| N10 | Undo of a client phone change restores the old value; if the value changed again since, Undo refuses and changes nothing; a bad token is refused (403) |
| N11 | Work Log edit shows hours + minutes only; a stored 1h 2m 5s keeps its seconds on an unrelated edit; changing the minutes saves whole minutes |
| N12 | Every form (Log work, Quick Enquiry, client, contact, task, work entry, enquiry, new client) has a Cancel link back to where it came from |
| N13 | Today: a withdrawn enquiry with a follow-up due is not listed; an accepted enquiry without a task is |

Updated for v0.11 behaviour: W04/W05/X09 (no seconds box; the timer saves whole minutes, at least 1), E01 (wording "price estimate"), X01 (the saved message is now the Undo bar), D03 (an app-made enquiry with a task is now editable, with no delete). Layout tests re-checked every screen at 320/375/390/430/1280 px, including the new enquiry screens and the sign-off panel.

## 3. Database migration 003
| Check | Result |
|---|---|
| Runner on real data (MySQL 8.0.46) | 9 columns added; received_at filled from each enquiry's saved time; verified (no enquiry without a date, none different) |
| App DB user without ALTER | installer stops before anything changes and names **only** 003's SQL file |
| phpMyAdmin `.apply.sql`, then installer | "schema up to date", installed, verified |
| `rollback --confirm` | undoes **only 003** (001 and 002 stay); database identical apart from the migration ledger; re-apply works |
| v0.10.0 code on the migrated database | today, clients, tasks, enquiries, work, Release check, enquiry and task pages all load; Release check passes |

## 4. Install rehearsal (simulated server: v0.10.0 live on ea-php83, real data, MySQL 8.0.46)
- Dry run: PHP 8.3 from the website's MultiPHP setting, migration 003 pending, the same 5 server files carried. ✔
- Install with a DB user that cannot change structure: stops, names the SQL file; after running it: installed and verified. ✔ Full suite on the installed copy. ✔
- `deploy/rollback.sh`: v0.10.0 back and verified, works on the migrated database. ✔
- Install with a DB user that can change structure: migration applied and verified automatically, installed, verified. ✔ Full suite on the installed copy. ✔
- Package scanned for real client data (940 names and emails from your records): 0 hits.

## 5. Not covered here
- Your real server (permissions of the database user, cPanel specifics) — the installer handles both cases, as on 31 Oct and 1 Nov.
- Phones other than Chromium's emulation (iPhone Safari date pickers look different but use the same fields).
