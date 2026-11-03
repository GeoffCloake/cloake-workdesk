# Test report — v0.12.0 · build 20261103.1

**Result: 91 of 91 tests passed** on your real-data replica (MySQL 8.0.46) and on synthetic data (MariaDB 10.11), and on a copy installed by `deploy/install.sh` over v0.11.0 by **both database routes**. Zero PHP warnings, zero security-policy violations and zero JavaScript errors in every run.
Run in a private workspace, **not** on your server. Deployed / user-confirmed status: pending.

## 1. Runs
| Database | Data | Code under test | Result |
|---|---|---|---|
| MariaDB 10.11 | Synthetic fixture (now with a fictional "Pro forma" client and 4 tasks), migrations 001–004 by deploy/migrate.php | dev tree, final commit | **91/91** |
| MySQL 8.0.46 | Real-data replica, migrations 001–004 by deploy/migrate.php | dev tree | **91/91** |
| MySQL 8.0.46 | Real-data replica at the server's state (v0.11.0 live, 001–003); 004 via the **phpMyAdmin SQL file** | **installed by deploy/install.sh** over v0.11.0 | **91/91** |
| MySQL 8.0.46 | Same; 004 applied **automatically** by the installer | **installed by deploy/install.sh** (final package, commit 4ae4d0b) | **91/91** |
| MySQL 8.0.46 | Real data with 004 applied | **v0.11.0 after `deploy/rollback.sh`** — v0.11.0's own suite | **75/75** |

## 2. New in this release (16 tests; the 75 from v0.11.0 still pass)
| ID | Proven |
|---|---|
| Q01 | Migration 004: every open "Pro forma" task is copied into a template (same title; instructions with the "rn" line breaks repaired); each set fee is the template's price line; a completed pro-forma task is not copied; the templates screen lists them all and offers "Retire pro-forma tasks" |
| Q02 | Template edit saved, audited, **Undo** restores it; an archived template is not offered on Quick Enquiry; restore works |
| Q03 | Quick Enquiry ▸ Start from a template: the title fills from the template; the enquiry gets it as a planned task whose price lines total the same as the template's |
| Q04 | Pricing panel on an enquiry: a whole-job line and a line for a planned task; qty, price, pricing type, account saved; **totals ex GST, GST 15% and incl GST equal the database**; edit + **Undo**; move up reorders; remove is audited |
| Q05 | Zero/negative quantity, non-numeric or missing price refused with a message, nothing saved; an **imported enquiry's pricing is read-only** (no forms; a forged post is refused) |
| Q06 | Milestone of 30%: amount = 30% of the enquiry's price; **ready** once the enquiry is accepted, listed on **Pending Invoicing**; back to **planned** when the enquiry is set back to waiting |
| Q07 | **Create tasks** from ticked planned tasks: one task per ticked plan (source key `<enquiry>-p<plan>`, client, enquiry, status open), its price lines and milestone assigned to it, whole-job lines untouched, enquiry "Task created"; re-posting (stale or fresh page) **never makes a second task**; the unticked plan can be created later |
| Q08 | Concurrency: 6 separate sessions create the same planned task at once → **exactly one task** |
| Q09 | The task's pricing panel shows the lines from its planned task with the right total; a task line saves; the billing choice (monthly) saves, is audited, and **Undo** clears it |
| Q10 | **Break out** into 2 stages, **Extend** with a 3rd: each copies client and enquiry, status open, numbered 1–3; the parent **cannot be signed off, nor set to Completed**, while a stage is open; a stage cannot have stages (forged post refused); a stage links back to its main task |
| Q11 | All stages signed off → the parent can be signed off; its "when signed off" milestone becomes **ready**; reopening the task puts it back to **planned** |
| Q12 | Manual milestone: mark ready (who), back to planned; a ready milestone **can't be removed**, only voided, and **void needs a reason**; restore; amount **and** % together refused; "when the enquiry is accepted" refused on a task without an enquiry |
| Q13 | **Make a template from this task** copies its price lines and milestones; asking again opens the same template; that template can be added to another enquiry with its milestones |
| Q14 | Deleting an app-made enquiry (no task) removes its planned tasks, price lines and milestones; the audit keeps a copy |
| Q15 | **Retire pro-forma tasks**: all set to Cancelled and the client archived in one step; they leave the open task list; **Undo** restores every task's status and the client; **Redo** retires again |
| Q16 | An imported task can have price lines and stages added; P01 then proves its imported record and locked fields are byte-for-byte unchanged |

Layout tests now also cover Task templates, a template, a new template and the price-line editor at 320/375/390/430/1280 px. P01 (imported records and locked fields unchanged) passed after every run.

## 3. Database migration 004
| Check | Result |
|---|---|
| Runner on real data (MySQL 8.0.46) | 3 tables created, 10 columns added; **10 templates** copied from the open pro-forma tasks, **3 set fees** ($1,800, $700, $600); verified |
| App DB user without CREATE/ALTER | installer stops **before anything changes** and names **only** 004's SQL file |
| phpMyAdmin `.apply.sql`, then installer | "schema up to date", installed, verified |
| `rollback --confirm` | undoes **only 004** (tables and columns dropped, template price lines removed first); re-apply works |
| v0.11.0 code on the migrated database | v0.11.0's full suite: 75/75 |
| Runner order | creates the new tables before adding columns, so a user that may ALTER but not CREATE is stopped with nothing half-done |

## 4. Install rehearsal (simulated server: v0.11.0 live on ea-php83, real data, MySQL 8.0.46)
- Dry run: migration 004 pending, the same server files carried (`.htaccess`, `.user.ini`, `.well-known/`). ✔
- Install with a DB user that cannot change structure: stops, names the SQL file; after running it: installed and verified. ✔ Full suite on the installed copy. ✔
- `deploy/rollback.sh`: v0.11.0 back and verified; works on the migrated database. ✔
- Install with a DB user that can change structure: migration applied and verified automatically, installed, verified. ✔ Full suite on the installed copy. ✔
- (The database-backup step ran through a small wrapper because the rehearsal database is on a second socket; on your server it connects normally, as on previous installs.)
- Package and source scanned for real client data (966 names, emails and template titles from your records): no real client or contact names or emails. Expected matches only: "Pro forma" (the client the code looks for), your generic template job titles, and names the tests create (e.g. "Nora Testwell"). One older synthetic fixture name that shared a word with a real client was renamed.

## 5. Not covered here
- Your real server (database user permissions, cPanel specifics). The installer handles both cases, as on 31 Oct, 1 Nov and 3 Nov.
- Phones other than Chromium's emulation.
