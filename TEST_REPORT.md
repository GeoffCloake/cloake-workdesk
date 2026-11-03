# Test report — v0.14.0 · build 20261103.3

**Result: 111 of 111 tests passed** on your real-data replica (MySQL 8.0.46) and on synthetic data (MariaDB 10.11), and on a copy installed by `deploy/install.sh` over v0.13.0 by **both database routes**. Zero PHP warnings, zero security-policy violations and zero JavaScript errors in every run.
Run in a private workspace, **not** on your server. Deployed / user-confirmed status: pending.

## 1. Runs
| Database | Data | Code under test | Result |
|---|---|---|---|
| MariaDB 10.11 | Synthetic fixture, migrations 001–006 | dev tree | **111/111** |
| MySQL 8.0.46 | Real-data replica, migrations 001–006 | dev tree | **111/111** |
| MySQL 8.0.46 | Your server's state (v0.13.0 live, 001–005, **your WHMCS import batch 1**); 006 via the **phpMyAdmin SQL file** | **installed by deploy/install.sh** over v0.13.0 | **105/111** — all but R01–R05 and R11, which load their own synthetic WHMCS files and need empty services/archive tables (as in the v0.13 report); every v0.14 test passed with your imported data present |
| MySQL 8.0.46 | Real data, v0.13.0 live, 006 applied **automatically** by the installer | installed copy (final package) | **111/111** |
| MySQL 8.0.46 | Real data with 006 applied | **v0.13.0 after `deploy/rollback.sh`** — v0.13.0's own suite | **102/102** |

## 2. New in this release (9 tests; the 102 from v0.13.0 still pass)
| ID | Proven |
|---|---|
| K01 | Staff seeded from the names in Action for; 7.5 h on Monday saved as 450 minutes, audited, **Undo** restores; a new person can be added (6 h Mon–Fri) and appears on Workload; switched off, they leave it |
| K02 | A task scheduled today shows under its person with "1h 30m / 6h"; tasks scheduled on a past day appear under "Overdue on the schedule"; the "Not scheduled" count equals the database |
| K03 | **Move** form: another day, 2 h booked and another person → Action for has the new person instead of the old (others kept), audited; **Undo** puts day, time and person back exactly |
| K04 | **Drag and drop** at desktop width: a card dragged to a later day is rescheduled; dragged to "Not scheduled" it comes off the schedule; no JavaScript or security-policy errors |
| K05 | A closed task can't be scheduled; a person not on Staff & hours is refused; nothing changes |
| K06 | Materials: $100 cost with a 20% markup defaults to $120 sell (margin shown); a bad cost is refused (nothing saved); an edit is audited and **Undo** restores it; an ordered item entered by mistake can be deleted |
| K07 | A **received** item appears on Pending Invoicing as a Material line; recording it with a Xero number makes one charge of $120 and takes it off the list; an invoiced item can't be changed; **Undo** of the invoice puts it back on the list |
| K08 | A received item can't be deleted; cancelling needs a reason; a cancelled item is not on Pending Invoicing |
| K09 | The desktop sidebar has Workload, Month-end claims, Billing settings, Staff & hours and More…; each loads and is highlighted |

Layout tests now also cover Workload, Staff & hours and the Move form at 320/375/390/430/1280 px. P01 (imported records and locked fields unchanged) passed in every run.

## 3. Database migration 006
| Check | Result |
|---|---|
| Runner on real data (MySQL 8.0.46) | 2 tables created, 2 columns added; 2 settings; **7 people** seeded from Action for, 4 switched on (an open task or one in the last 12 months — based on the 30 Oct snapshot; adjust on Staff & hours) |
| App DB user without CREATE/ALTER | installer stops before anything changes and names only 006's SQL file |
| phpMyAdmin `.apply.sql`, then installer | "schema up to date", installed, verified; staff seeded the same way |
| v0.13.0 on the migrated database | its own suite 102/102 |

## 4. Not covered here
- Your real server (database user permissions, cPanel specifics) — the installer handles both cases.
- Touch drag on phones: phones use **Move** (drag is for computers).
- Phones other than Chromium's emulation.
