# Cloake Client & Services Manager — source repository

This Git repository is the **single authoritative codebase**. Every release is built from a commit here
(`deploy/build-package.sh`), and the package records that commit in `SOURCE_COMMIT.txt`.

History starts with the exact files you supplied, so every later change is reviewable:
1. `Baseline v0.8.1` — your server snapshot, unchanged
2. `Candidate v0.8.2` — the update that was delivered but never confirmed installed
3. `v0.9.0` and onwards — see `CHANGELOG.md`

## Layout
```
public/                 web root (deploy target: public_html/manager-dev.cloakecreative.co.nz/)
  index.php             sign-in + router → inc/pages/<screen>.php (read-only DB session)
  add-work.php …        write pages (Log work, Quick Enquiry, convert, edit task/enquiry)
  lookup.php            read-only JSON for the instant finder and task pickers
  inc/bootstrap.php     ONLY place for config path, session, headers, DB connection
  inc/layout.php        header, sidebar, bottom nav, version label
  inc/forms.php         client picker
  inc/pages/*.php       one file per screen
  assets/app.css|app.js all CSS/JS (the CSP forbids inline CSS/JS)
deploy/                 install.sh, rollback.sh, build-package.sh
db/                     schema-only SQL used to build test databases (no data)
tests/                  integration + mobile-layout suite, synthetic fixture generator
```

## Rules this codebase follows
- No database schema changes without a reviewed, reversible migration in `db/` and a note in the changelog.
- Imported (Fabrik) records are never modified by the app; tests check this byte-for-byte after every run.
- No inline CSS/JS. No Xero calls. No credentials in the repository.
- Version and build live only in `public/version.php` and are shown on every screen.
- Status words: **built** → **tested locally** → **deployed** → **user-confirmed**. Never claim the next one early.

## Running the tests (developer machine)
Needs PHP 8.x with pdo_mysql, MariaDB or MySQL 8, Node 18+.
```bash
cd tests && npm install && npx playwright install chromium
# local-only private config at /home/cloakecrea/cloake-manager/private/config.php (password_hash, db_* keys)
./reset-db.sh fixture                                   # synthetic data, safe to share
(cd .. && PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8080 -t public) &
node run-tests.js --db cloake_fixture --out artifacts/run --shots
```
`MYSQL_ARGS="-S /path/mysql.sock -uroot"` points the test's database checks at another server.
`--only W,V` runs a subset. Test results are written to `results.md` / `results.json`.
