# Install v0.15.0 (build 20261103.4) — from GitHub — and how to undo it

**What changes:** application files only (screens simplified, app renamed Cloake Workdesk). **No database change.** Your data, `private/config.php`, cPanel/PHP settings, Xero and WHMCS are untouched.
Allow 5 minutes.

## Steps (WHM Terminal)
1. `su -s /bin/bash - cloakecrea` (the prompt must read `[cloakecrea@server …]$`).
2. `cm-update v0.15.0`
   - It fetches the release from GitHub and runs the **dry run** (changes nothing). Expect `Currently installed: … '0.14.0'`, `Database: schema up to date`, the usual `carried:` lines, `Dry run complete`.
   - It then asks **Install v0.15.0 now?** — type `y` and press Enter. Expect `Verified: … v0.15.0 · build 20261103.4`.
3. **Check (2 minutes):**
   - Header reads **Cloake Workdesk v0.15.0 · build 20261103.4** → **More… → Release check**: all passed.
   - Computer: sidebar is Today, Tasks, Work Log, Clients, Enquiries, More…. **More…** lists everything else.
   - Open a plain task: **More options** is closed; click it to see pricing, materials, stages. Open a task with price lines: it's already open.

## Undo
- `cm-update v0.14.0` (same steps, puts v0.14.0 back), or `bash deploy/rollback.sh` in `~/cloake-manager/releases/cloake-workdesk-v0.15.0-build20261103.4/`.
