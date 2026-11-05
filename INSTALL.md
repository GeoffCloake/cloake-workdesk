# Install v0.18.2 (build 20261105.1) — and how to undo it

**What changes:** application files only. **No database change.** Xero descriptions on Pending Invoicing and drafts become one short line.

1. `su -s /bin/bash - cloakecrea`
2. `cm-update v0.18.2` → `Database: schema up to date` → `y` → `Verified: … v0.18.2 · build 20261105.1`.
3. Open Pending Invoicing: hosting lines read "Website hosting – domain – dates (6 months)".

**Undo:** `cm-update v0.18.1`.

---

# Install v0.18.1 (build 20261104.3) — and how to undo it

**What changes:** application files only. **No database change.** (Includes v0.17.1 and v0.18.0 — install v0.18.1 directly.)

## 1. Install (WHM Terminal, 2 minutes)
1. `su -s /bin/bash - cloakecrea`
2. `cm-update v0.18.1` → `Database: schema up to date` → `y` → `Verified: … v0.18.1 · build 20261104.3`.

## 2. Reconnect Xero once (new permissions)
More… → **Xero connection** → it says "Reconnect once…" → **Reconnect / switch organisation** → Xero lists the new permissions (contacts, settings) → **Allow access** → choose **Demo Company (NZ)** for now → Continue.

## 3. Try it on the Demo Company (10 minutes)
1. More… → **Xero sync**: every active client is flagged (✓ In sync, ≠ Different, ? Probable match, ✗ Not in Xero, ⚠ Contact gone) with its action; **Need action** filters to the ones to do. In the Demo Company nearly all will be ✗ Not in Xero — expected. Xero customers with no client can be marked **Not a client**; suppliers are listed apart. **Account codes and tax types**: in the Demo Company 43504 and 43601 show ✗ — your real organisation should show ✓.
2. Pending Invoicing → one line → **Create Xero draft instead**. In Xero the draft should now show **15% GST on Income** with the GST amount, and a **Reference** (the domain). Undo it here and delete the draft in Xero.

Don't use **Create in Xero** or **Add to Workdesk** on the Demo Company unless you want test contacts there — they work, but they're for your real organisation.

## Undo
`cm-update v0.17.0` (v0.17.1 was never installed; both work on this database).
