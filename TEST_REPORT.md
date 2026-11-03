# Test report — v0.16.0 · build 20261103.5

**Result: 119 of 119 tests passed** on your real-data replica (MySQL 8.0.46) and on synthetic data (MariaDB 10.11). Installed over v0.15.0 with **`cm-update v0.16.0` by both database routes**, and taken back with `cm-update v0.15.0`. Zero PHP warnings, zero security-policy violations, zero JavaScript errors.
Run in a private workspace, **not** on your server. Deployed / user-confirmed status: pending.

## 1. Runs
| Database | Data | Code under test | Result |
|---|---|---|---|
| MariaDB 10.11 | Synthetic fixture, migrations 001–007 | dev tree | **119/119** |
| MySQL 8.0.46 | Real-data replica, 001–007 | dev tree | **119/119** |
| MySQL 8.0.46 | Your server's state (WHMCS import batch 1, 001–006); 007 **applied by the installer** | installed by `cm-update v0.16.0` over v0.15.0 | **112/119** — all but R01–R05, R08 and R11, which build their own synthetic WHMCS/billing data and clash with the imported services (R01–R05/R11 as in earlier reports; R08 fails the same way on v0.15.0 with this snapshot, so it is not a v0.16 change). Every v0.16 test passed with your imported data |
| MySQL 8.0.46 | Same, app DB user **without** ALTER/CREATE | `cm-update` stopped before changing anything and named only 007's SQL file; after that file was run (as in phpMyAdmin), `cm-update v0.16.0` installed and verified | **112/119** (same as above) |
| MySQL 8.0.46 | Real data with 007 applied | **v0.15.0 after `cm-update v0.15.0`** — v0.15.0's own suite | **113/113** |

On your imported data the register shows **80 live domains**: 64 billed on their own, of which **27 have never been invoiced** (the "Billed, never invoiced" filter — the same 27 as the reconciliation), 2 expiring within 60 days, none expired.

## 2. New tests
| ID | Proven |
|---|---|
| D01 | Migration 007: six columns; "Set up hosting + domain" template with its checklist; Release check shows 007 applied |
| D02 | Domains page lists only domains (count = database); "Expiring within 60 days", "Expired", "Billed, never invoiced" and registrar filters match the database; amber / red expiry |
| D03 | Include a no-charge domain in the client's hosting: leaves "No charge, not linked", audited, shown on both pages; the hosting's Xero description reads "Additional Domains: …"; removing it can be undone; another client's hosting is refused |
| D04 | A billed domain included in hosting is set to no charge and leaves Pending Invoicing; even if forced back to billed, recording it on an invoice is refused |
| D05 | DNS host, name servers (lower-cased), auto-renew, provider reference save and show; audited; Undo restores; a forged auto-renew value is refused |
| D06 | "Start set-up task" creates "Set up: <domain>" for the client from the checklist template (planned, domain filled), linked both ways; a second one is refused |
| W01 | More… now also lists Domains |

Layout tests cover the Domains page at 320/375/390/430/1280 px. P01 (imported records and locked fields unchanged) passed in every run.

## 3. Not covered here
- Your real server's database user rights (the installer handles both cases).
- Any Vetta portal connection — only the provider-reference field for it is added.
