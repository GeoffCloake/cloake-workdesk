# Test report — v0.18.2 · build 20261105.1

**Result: 133 of 133 tests passed** on synthetic data, on MariaDB 10.11 and on MySQL 8.0.46. No database change.

| ID | Proven (new or changed) |
|---|---|
| R12 | (new) descriptions for hosting (with and without included domains and mailboxes), email, domain, DNS and monthly cycles match the new one-line wording; a label holding a web address, a login, a password and an email address comes out with none of them |
| R03 | (changed) Pending Invoicing shows "Website hosting – … – dd/mm/yyyy to … (6 months)", with no PHP version, plan name or "Billing Period" |
| D-tests, XD04 | (changed) included domains appear after "Includes"; the Xero draft line starts "Website hosting – …" |

---

# Test report — v0.18.1 · build 20261104.3

**Result: 132 of 132 tests passed** on your real-data replica (MySQL 8.0.46), on synthetic data (MariaDB 10.11), and on the copy installed by `cm-update v0.18.1` over v0.18.0. No database change; `cm-update v0.17.0` goes back and verifies.

| ID | Proven (new or changed) |
|---|---|
| XS01 | (changed) the client is flagged "? Probable match" with its match ticked; Link → flagged "✓ In sync" |
| XS02 | (changed) the "Not in Xero" filter shows only those; the client page says "✗ Not linked to Xero"; after Create → linked; renamed → flagged "≠ Different"; Update Xero → "✓ In sync"; client page "✓ Linked to Xero" |
| XS06 | A supplier (not a customer in Xero) is under "Other Xero contacts — no action needed", not under customers; "Not a client" moves a customer to "Marked not a client" without any change sent to Xero; "Show again" brings it back |

---

# Test report — v0.18.0 · build 20261104.2

**Result: 131 of 131 tests passed** on your real-data replica (MySQL 8.0.46) and on synthetic data (MariaDB 10.11), and on the copy installed by `cm-update v0.18.0` over v0.17.1. No database change; `cm-update v0.17.1` goes back and verifies.
Xero calls run against the local stand-in (`tests/xero-mock.php`), now also playing Xero's contacts, chart of accounts and tax rates.

| ID | Proven |
|---|---|
| XS01 | Same name ignoring "Ltd/Limited" → ticked probable match; Link stores the ContactID (audited); Undo clears it; a Xero-only contact → Add to Workdesk creates the client with name, email and link |
| XS02 | Create in Xero sends name + email (no ContactID) and links the new contact; renaming the client shows it under "different"; Update Xero sends the ContactID with the new name; then no difference |
| XS03 | Account 43504 ✓, an account Xero lacks (99990 on a service) ✗; OUTPUT2 and ZERORATEDOUTPUT ✓ |
| XS04 | Postal country United Kingdom → Pending Invoicing "overseas (United Kingdom) — zero rated", $100.00 incl = ex; draft line ZERORATEDOUTPUT; a typed Reference is sent; client page shows zero rated |
| XS05 | A pre-v0.18 connection (old permissions) → "Reconnect" on Xero sync and Xero connection; Create in Xero refused, nothing linked |
| XD04 | (changed) the automatic Reference is the service's domain |
| W01 | More… lists Xero sync |

On your data, overseas detection picks out 2 active clients (United Kingdom; Netherlands — its postal country is "Nederlands"). Every other client stays on 15% GST.

---

# Test report — v0.17.1 · build 20261104.1

**Result: 126 of 126 tests passed** on your real-data replica (MySQL 8.0.46) and on synthetic data (MariaDB 10.11), and on the copy installed by `cm-update v0.17.1` over v0.17.0. No database change. `cm-update v0.17.0` goes back and verifies.

| ID | Proven (new or changed) |
|---|---|
| XD04 | Every draft line is sent with TaxType **OUTPUT2**; no warning when Xero keeps the account |
| XD07 | A line whose account Xero drops (simulated like the Demo Company: unknown code removed) → draft still made, confirmation shows "Xero dropped account 99990 on line 1"; Billing settings shows Xero tax type OUTPUT2 by default and refuses a malformed code |

The Xero stand-in now behaves like your Demo Company result: an unknown account code is dropped silently on a draft.

---

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
