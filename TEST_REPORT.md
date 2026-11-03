# Test report — v0.17.0 · build 20261103.6

**Result: 125 of 125 tests passed** on your real-data replica (MySQL 8.0.46) and on synthetic data (MariaDB 10.11). Installed over v0.16.0 with `cm-update v0.17.0` by **both database routes**; back to v0.16.0 with `cm-update v0.16.0`. Zero PHP warnings, security-policy violations or JavaScript errors.
**Xero itself was not contacted** — this workspace cannot reach Xero. The Xero tests run against a local stand-in (`tests/xero-mock.php`) that behaves like Xero's sign-in, token and invoice calls. The real check is your Demo Company test (INSTALL.md, part B).

## 1. Runs
| Database | Data | Code under test | Result |
|---|---|---|---|
| MariaDB 10.11 | Synthetic fixture, 001–008 | dev tree | **125/125** |
| MySQL 8.0.46 | Real-data replica, 001–008 | dev tree | **125/125** |
| MySQL 8.0.46 | Your server's state (import batch 1, 001–007); 008 **applied by the installer** | installed by `cm-update v0.17.0` over v0.16.0 | **118/125** — all but R01–R05, R08, R11 (their own synthetic WHMCS data clashes with the imported services, as in the v0.16 report); every v0.17 test passed |
| MySQL 8.0.46 | Same, app DB user without ALTER | stopped before changing anything, named only 008's SQL; after running it, installed and verified | installed ✔ |
| MySQL 8.0.46 | Real data with 008 | **v0.16.0 after `cm-update v0.16.0`** — v0.16.0's own suite | **119/119** |

## 2. New tests
| ID | Proven |
|---|---|
| XD01 | Migration 008 column; More lists Xero connection; not set up → no "Create Xero draft" button |
| XD02 | Bad client id refused; app saved to the private folder, file mode 600, outside the website; the secret never appears on the page or in the audit |
| XD03 | Connect → Xero sign-in asked for *offline_access accounting.invoices* (not the retired broad scope) → back to Workdesk → "Connected to Demo Company (NZ)"; the return link can't be replayed (400) |
| XD04 | "Create Xero draft instead" sends ONE invoice: Status **DRAFT**, ACCREC, amounts **ex GST**, the hosting account 43504, the Xero description, the client's name as contact (Demo Company), the organisation header, due today; Xero's number INV-9001 and InvoiceID recorded on the charge; the service moves on a period; Undo removes it here and says the draft is still in Xero |
| XD05 | Xero refuses (validation error) → the reason is shown, **nothing recorded**, service still due |
| XD06 | Expired access token → renewed, the rotated refresh token saved, draft made; a dead connection → "connection has expired", nothing recorded, button hidden; Disconnect revokes and removes access |
| W01 | More… lists Xero connection |

The fix to the double-submit guard (a pressed button's value was dropped) was found by XD04; every older test still passes with it.

## 3. Not covered here
- Real Xero (your Demo Company test, then your organisation): wording of Xero's consent screen, its InvoiceNumber sequence, GST default on account 43504.
- Matching real Xero contacts by name (the Demo Company creates contacts instead). Before using it on your real organisation, put Xero ContactIDs in the clients' Xero contact reference where names differ.
