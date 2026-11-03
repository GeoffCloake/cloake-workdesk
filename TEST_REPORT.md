# Test report — v0.13.0 · build 20261103.2

**Result: 102 of 102 tests passed** on your real-data replica (MySQL 8.0.46) and on synthetic data (MariaDB 10.11), and on a copy installed by `deploy/install.sh` over v0.12.0 by **both database routes**. Your **real WHMCS import** was rehearsed end to end (dry run, import, undo, re-import, duplicate refused, undo refused while an invoice depends on it). Zero PHP warnings, zero security-policy violations and zero JavaScript errors in every run.
Run in a private workspace, **not** on your server. Deployed / user-confirmed status: pending.

## 1. Runs
| Database | Data | Code under test | Result |
|---|---|---|---|
| MariaDB 10.11 | Synthetic fixture + synthetic WHMCS files, migrations 001–005 | dev tree | **102/102** |
| MySQL 8.0.46 | Real-data replica, migrations 001–005 | dev tree | **102/102** |
| MySQL 8.0.46 | Real-data replica at the server's state (v0.12.0 live, 001–004); 005 via the **phpMyAdmin SQL file** | **installed by deploy/install.sh** over v0.12.0 | **102/102** |
| MySQL 8.0.46 | Same; 005 applied **automatically** by the installer; then **your real WHMCS import** with the installed tool | installed copy | **96/96** of the tests that don't load their own synthetic WHMCS files (R01–R05 and R11 need empty services/archive tables; they pass on the fresh copies above) |
| MySQL 8.0.46 | Real data with 005 applied | **v0.12.0 after `deploy/rollback.sh`** — v0.12.0's own suite | **91/91** |

## 2. New in this release (11 tests; the 91 from v0.12.0 still pass)
| ID | Proven |
|---|---|
| R01 | WHMCS import (synthetic files): dry run changes nothing; apply archives every row with **no Password column** and login-like note lines removed, creates the live services and Xero history; the same files again do nothing |
| R02 | Services list and client page: live services with their next period; ended and archive-only WHMCS items under "Former services"; filters match the database |
| R03 | Pending Invoicing: renewals due within 8 weeks and overdue, grouped by client, Xero description patterns, account, ex and incl GST |
| R04 | **One Xero invoice for two ticked lines:** charges saved with the number and who; each service moves on one cycle; **Undo** puts everything back |
| R05 | Recording refused (nothing saved) for lines of two clients, an already-recorded period, or a missing invoice number or name |
| R06 | Correcting the next period date needs a reason; saved and audited with it; Undo restores it |
| R07 | New service: a billed service needs a price and cycle; it then shows on the client and, when due, on Pending Invoicing |
| R08 | A ready milestone and a completed on-completion task (with an amount override) appear on Pending Invoicing; recording charges each once and ties the task's hours |
| R09 | Month-end claims: the month's hours × rate become a pending claim (hours tied); a progress claim over the remaining fee is refused; removing a claim frees its hours |
| R10 | Lead time setting: widening it to 20 weeks adds the later renewal to Pending Invoicing |
| R11 | Import undo: refused while a manager-recorded invoice uses its services; allowed after that invoice is undone; removes services, archive, history and the new client |

## 3. Your WHMCS import (real data, MySQL 8.0.46)
| Check | Result |
|---|---|
| Preparation (`tools/prepare-whmcs-import.py`, private) from the 21 Oct WHMCS exports, your edited reconciliation workbook, Cathy's renewals workbook and the 2 Nov Xero export | Re-run today: all six output files **identical** to the import zip |
| Passwords | Password column removed (208 values); **68 note lines** that looked like logins removed (clients 2, services 66). After import: **0** archive rows contain a password field |
| Dry run | 2 new clients (the two you decided to create), 575 archive rows (135 clients, 209 services, 231 domains), **173 services** (116 billed, 25 internal, 32 no charge), **175** invoiced periods from Xero |
| Import | As the dry run; batch 1 |
| Pending Invoicing straight after | **18 renewals due within 8 weeks, $3,208.10 ex GST; 5 overdue = $1,598.10** — the same 5 and the same total as your reconciliation workbook |
| Undo | Everything removed (clients back to 365); re-import works; same files again refused; undo **refused** while a manager-recorded invoice depends on the batch, nothing changed |

## 4. Install rehearsal (simulated server: v0.12.0 live on ea-php83, real data, MySQL 8.0.46)
- Dry run: migration 005 pending, the same server files carried. ✔
- DB user without CREATE/ALTER: stops before anything changes, names only 005's SQL file; after running it: installed and verified; full suite 102/102. ✔
- `deploy/rollback.sh`: v0.12.0 back and verified; works on the migrated database (its own suite 91/91). ✔
- DB user that can change structure: 005 applied and verified automatically; installed; import run with the installed tool. ✔
- Packages scanned for real client data: the app package holds none. The import zip is confidential by design (client names, domains, prices; no passwords).

## 5. Not covered here
- Your real server (database user permissions, cPanel specifics) — the installer handles both cases.
- Renewals Cathy has invoiced in Xero since the 2 Nov export: record them in Pending Invoicing after the import (see INSTALL.md).
- Phones other than Chromium's emulation.
