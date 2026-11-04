# Install v0.17.1 (build 20261104.1) — and how to undo it

**What changes:** application files only. **No database change.** Your Xero connection stays as it is.

## Steps (WHM Terminal, 2 minutes)
1. `su -s /bin/bash - cloakecrea`
2. `cm-update v0.17.1` → dry run shows `Database: schema up to date` → `y` → `Verified: … v0.17.1 · build 20261104.1`.

## Check with the Demo Company (5 minutes)
1. **More… → Billing settings**: *Xero tax type* shows **OUTPUT2**.
2. **Pending Invoicing**: tick one line → *Recorded by* → **Create Xero draft instead**.
   Expect the confirmation **and** "Check the draft in Xero: Xero dropped account 43504 …" (the Demo Company has no account 43504 — your real organisation does).
3. In Xero (Demo Company) the draft now shows **Tax rate 15% GST on Income** and the GST amount (e.g. $58.50 on $390.00).
4. Tidy up as before: **Undo** in Workdesk, delete the draft in Xero.

## Undo
`cm-update v0.17.0`
