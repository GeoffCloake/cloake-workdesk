/*
 * Cloake Manager — integration + mobile-layout test suite.
 *
 *   node run-tests.js --db cloake_fixture --out artifacts/fixture [--shots]
 *
 * Runs a real browser (Chromium) against the PHP app served at BASE, and checks
 * results directly in the database. Test subjects (clients, contacts, tasks)
 * are chosen from whichever database is under test.
 * Writes <out>/results.json and <out>/results.md. Exit code 1 on any failure.
 */
const { chromium, request } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const args = process.argv.slice(2);
const arg = (k, d) => { const i = args.indexOf(k); return i >= 0 ? args[i + 1] : d; };
const DB = arg('--db', 'cloake_fixture');
const OUT = arg('--out', 'artifacts/run');
const SHOTS = args.includes('--shots');
const ONLY = (arg('--only', '') || '').split(',').filter(Boolean);
const BASE = process.env.BASE || 'http://localhost:8080/';
const PASSWORD = process.env.CM_PASSWORD || 'local-test-pass';
const PHONES = [320, 375, 390, 430];
const DESKTOP = 1280;
fs.mkdirSync(OUT, { recursive: true });

/* ---------- db helpers ---------- */
function sql(query) {
  const outp = execFileSync('mysql', [...(process.env.MYSQL_ARGS || '').split(' ').filter(Boolean), '--default-character-set=utf8mb4', '-N', '-B', '--raw', DB], { input: query, encoding: 'utf8' });
  return outp.split('\n').filter(l => l.length).map(l => l.split('\t').map(v => v === 'NULL' ? null : v));
}
const one = q => (sql(q)[0] || [])[0];
const num = q => parseInt(one(q) || '0', 10);
const esc = s => String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
const VERSION_LABEL = (() => { const v = fs.readFileSync(path.join(__dirname, '../public/version.php'), 'utf8'); return 'v' + /CLOAKE_VERSION = '([^']+)'/.exec(v)[1] + ' · build ' + /CLOAKE_BUILD = '([^']+)'/.exec(v)[1]; })();
const todayNZ = new Intl.DateTimeFormat('en-CA', { timeZone: 'Pacific/Auckland' }).format(new Date());

/* ---------- tiny test harness ---------- */
const results = [];
let current = null;
async function test(id, name, fn) {
  if (ONLY.length && !ONLY.some(o => id.startsWith(o))) return;
  const t0 = Date.now(); current = { id, name, notes: [] };
  try { await fn(current); results.push({ ...current, ok: true, ms: Date.now() - t0 }); console.log(`  PASS ${id} ${name}`); }
  catch (e) { results.push({ ...current, ok: false, ms: Date.now() - t0, error: String(e && e.message || e).slice(0, 600) }); console.log(`  FAIL ${id} ${name}\n       ${String(e && e.message || e).slice(0, 300)}`); }
}
function assert(c, msg) { if (!c) throw new Error(msg); }
function note(s) { current.notes.push(s); }

/* ---------- browser helpers ---------- */
let browser;
async function newSession(width = 375, opts = {}) {
  const ctx = await browser.newContext({ viewport: { width, height: width >= 760 ? 900 : 760 }, deviceScaleFactor: 2, hasTouch: width < 760, isMobile: width < 760, javaScriptEnabled: opts.js !== false });
  const page = await ctx.newPage();
  page._problems = [];
  page.on('console', m => { if (m.type() === 'error' || /Content Security Policy|Refused/.test(m.text())) page._problems.push(m.text().slice(0, 200)); });
  page.on('pageerror', e => page._problems.push('pageerror: ' + e.message.slice(0, 200)));
  if (opts.login !== false) await login(page);
  return { ctx, page };
}
async function login(page, next) {
  await page.goto(BASE + (next || ''));
  await page.fill('#pw', PASSWORD);
  await Promise.all([page.waitForNavigation(), page.click('form button[type=submit]')]);
}
async function go(page, url) { const r = await page.goto(BASE + url); return r; }
async function shot(page, name) { if (SHOTS) await page.screenshot({ path: path.join(OUT, name + '.png'), fullPage: true }); }

/* ---------- subjects from the DB under test ---------- */
const CLOSED = "LOWER(TRIM(COALESCE(%s,''))) IN ('completed','complete','closed','cancelled','canceled','done')";
const closed = c => CLOSED.replace('%s', c);
function subjects() {
  const s = {};
  // client with an active contact that has a dialable phone, and an email somewhere
  let r = sql(`SELECT c.id,c.name,k.display_name,k.phone FROM clients c JOIN contacts k ON k.client_id=c.id
     WHERE LOWER(COALESCE(c.status,''))<>'archive' AND k.phone REGEXP '[0-9]{3}.*[0-9]{4}' AND k.display_name IS NOT NULL
       AND COALESCE(k.status,'')<>'archive'
       AND LOCATE(LOWER(SUBSTRING_INDEX(k.display_name,' ',-1)), LOWER(c.name))=0
       AND (SELECT COUNT(*) FROM contacts k2 WHERE k2.display_name=k.display_name)=1
     ORDER BY c.id LIMIT 1`)[0];
  s.client = { id: +r[0], name: r[1] }; s.contact = { name: r[2], phone: r[3] };
  r = sql(`SELECT c.id,c.name,k.email FROM clients c JOIN contacts k ON k.client_id=c.id WHERE k.email LIKE '%@%.%' AND LOWER(COALESCE(c.status,''))<>'archive'
     AND (SELECT COUNT(DISTINCT k2.client_id) FROM contacts k2 WHERE k2.email=k.email)=1 ORDER BY c.id DESC LIMIT 1`)[0];
  s.emailClient = { id: +r[0], name: r[1], email: r[2] };
  // open task with a client, and a second client different from it
  r = sql(`SELECT t.id,t.client_id FROM tasks t WHERE t.client_id IS NOT NULL AND NOT (${closed('t.status')}) AND t.completed_at IS NULL ORDER BY t.id DESC LIMIT 1`)[0];
  s.openTask = { id: +r[0], client: +r[1] };
  s.otherClient = +one(`SELECT id FROM clients WHERE id<>${s.openTask.client} AND LOWER(COALESCE(status,''))<>'archive' ORDER BY id LIMIT 1`);
  s.fabrikTask = +one(`SELECT id FROM tasks WHERE source_system='fabrik' ORDER BY id LIMIT 1`);
  s.fabrikTaskWithClient = +one(`SELECT id FROM tasks WHERE source_system='fabrik' AND client_id IS NOT NULL ORDER BY id DESC LIMIT 1`);
  s.anyClient = +one(`SELECT id FROM clients WHERE LOWER(COALESCE(status,''))<>'archive' ORDER BY name LIMIT 1 OFFSET 3`);
  s.manyMatch = 'a';
  s.contactId = +one(`SELECT id FROM contacts WHERE client_id=${s.client.id} ORDER BY id LIMIT 1`);
  s.staffTop = one(`SELECT TRIM(SUBSTRING_INDEX(assigned_to, ',', 1)) FROM tasks WHERE assigned_to IS NOT NULL GROUP BY 1 ORDER BY COUNT(*) DESC LIMIT 1`) || 'geoff';
  s.fabrikWork = +one(`SELECT id FROM work_log WHERE source_system='fabrik' ORDER BY id LIMIT 1`);
  return s;
}
/* checksums of data that must never change */
function protectedChecksums() {
  const rowsum = (table, cols, where = '') => one(`SELECT CONCAT(COUNT(*),':',COALESCE(SUM(CRC32(CONCAT_WS('|',${cols}))),0)) FROM ${table} ${where}`);
  // v0.10.0 rules: imported records' original copy (legacy_record) and locked fields never change.
  const out = {
    clients_original: rowsum('clients', 'id,source_system,source_id,COALESCE(CAST(legacy_record AS CHAR),"")', "WHERE source_system='fabrik'"),
    contacts_original: rowsum('contacts', 'id,source_system,source_id,COALESCE(CAST(legacy_record AS CHAR),"")', "WHERE source_system='fabrik'"),
    fabrik_tasks_locked: rowsum('tasks', 'id,source_id,title,COALESCE(instructions,""),COALESCE(pricing,""),COALESCE(chargeable,""),COALESCE(estimate_minutes,""),COALESCE(agreed_fee_ex_gst,""),COALESCE(legacy_billing_amount_ex_gst,""),COALESCE(billing_advised,""),COALESCE(invoice_number,""),COALESCE(invoice_reference,""),COALESCE(purchase_order_no,""),COALESCE(job_number,""),COALESCE(received_at,""),COALESCE(CAST(legacy_record AS CHAR),"")', "WHERE source_system='fabrik'"),
    fabrik_work_locked: rowsum('work_log', 'id,source_id,details,duration_seconds,billable_seconds,COALESCE(rate_ex_gst,""),COALESCE(billed_amount_ex_gst,""),COALESCE(work_date,""),COALESCE(started_at,""),COALESCE(ended_at,""),COALESCE(staff_key,""),COALESCE(CAST(legacy_record AS CHAR),"")', "WHERE source_system='fabrik'"),
  };
  for (const t of ['legacy_clients', 'legacy_contacts', 'legacy_tasks', 'legacy_work_log', 'legacy_classification', 'legacy_enquiries']) out[t] = rowsum(t, 'source_id,row_sha256,CAST(source_record AS CHAR)');
  return out;
}

(async () => {
  browser = await chromium.launch();
  const S = subjects();
  console.log(`Database: ${DB}  today(NZ): ${todayNZ}\nSubjects:`, JSON.stringify({ client: S.client.id, openTask: S.openTask.id, fabrikTask: S.fabrikTask }));
  const before = protectedChecksums();
  const api = await request.newContext({ baseURL: BASE, maxRedirects: 0 });

  /* ================= security & HTTP ================= */
  await test('S01', 'Signed-out visitors cannot reach any screen, form or lookup', async () => {
    const r1 = await api.get('index.php?page=clients'); assert(r1.status() === 401, 'index status ' + r1.status()); assert((await r1.text()).includes('id="pw"'), 'login form missing');
    for (const u of ['add-work.php', 'add-enquiry.php', 'convert-enquiry.php?id=1', 'edit-task.php?id=1', 'edit-enquiry.php?id=1']) {
      const r = await api.get(u); assert(r.status() === 302 && r.headers()['location'] === './', `${u} -> ${r.status()} ${r.headers()['location']}`);
    }
    const r2 = await api.get('lookup.php?clients=1'); assert(r2.status() === 401, 'lookup status ' + r2.status()); assert((await r2.json()).error === 'signed_out', 'lookup body');
    const r3 = await api.post('add-work.php', { form: { details: 'x', csrf: 'x' } }); assert(r3.status() === 302, 'POST add-work signed out ' + r3.status());
  });
  await test('S02', 'Internal PHP files return nothing when requested directly', async () => {
    for (const u of ['inc/bootstrap.php', 'inc/layout.php', 'inc/forms.php']) { const r = await api.get(u); assert(r.status() === 404, `${u} -> ${r.status()}`); }
    for (const u of ['inc/pages/today.php', 'inc/pages/client.php', 'inc/pages/check.php']) { const r = await api.get(u); const b = await r.text(); assert(b.trim() === '', `${u} leaked ${b.length} bytes`); }
    note('Apache also denies inc/ via inc/.htaccess (not exercised by the PHP built-in server).');
  });
  await test('S03', 'Security headers present on pages, forms and lookups', async () => {
    const { ctx, page } = await newSession(375);
    const urls = ['index.php?page=today', 'index.php?page=client&id=' + S.client.id, 'add-work.php', 'add-enquiry.php', 'lookup.php?clients=1', 'index.php?page=check'];
    for (const u of urls) {
      const r = await page.request.get(BASE + u); const h = r.headers();
      assert(h['content-security-policy'] === "default-src 'self'; style-src 'self'; script-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'", u + ' CSP: ' + h['content-security-policy']);
      assert(h['x-frame-options'] === 'DENY' && h['x-content-type-options'] === 'nosniff' && /no-store/.test(h['cache-control'] || '') && h['referrer-policy'] === 'no-referrer', u + ' headers');
    }
    await ctx.close();
  });
  await test('S04', 'Sign-in: wrong password rejected; correct password opens Today; cookie flags', async () => {
    const { ctx, page } = await newSession(375, { login: false });
    await page.goto(BASE); await page.fill('#pw', 'wrong-password'); await Promise.all([page.waitForNavigation(), page.click('form button[type=submit]')]);
    assert(await page.locator('[role=alert]').innerText() === 'Incorrect password.', 'no error shown');
    await page.fill('#pw', PASSWORD); await Promise.all([page.waitForNavigation(), page.click('form button[type=submit]')]);
    assert(await page.locator('#finder-q').count() === 1, 'Today finder not shown after login');
    const ck = (await ctx.cookies()).find(c => c.name === 'cloake_m4');
    assert(ck && ck.httpOnly && ck.secure && ck.sameSite === 'Strict' && ck.path === '/', 'cookie flags ' + JSON.stringify(ck));
    await ctx.close();
  });
  await test('S05', 'Deep link survives sign-in; external redirect targets are ignored', async () => {
    const { ctx, page } = await newSession(375, { login: false });
    await login(page, 'index.php?page=client&id=' + S.client.id);
    assert(page.url().includes('page=client&id=' + S.client.id), 'landed on ' + page.url());
    await ctx.close();
    const c2 = await browser.newContext(); const p2 = await c2.newPage();
    await p2.goto(BASE); await p2.evaluate(() => { document.querySelector('input[name=next]').value = 'https://evil.example/'; });
    await p2.fill('#pw', PASSWORD); await Promise.all([p2.waitForNavigation(), p2.click('form button[type=submit]')]);
    assert(p2.url().startsWith(BASE), 'redirected off-site: ' + p2.url()); await c2.close();
  });

  /* ================= layout at phone widths + desktop ================= */
  const routes = ['index.php?page=today', 'index.php?page=clients', 'index.php?page=client&id=' + S.client.id, 'index.php?page=tasks', 'index.php?page=task&id=' + S.openTask.id,
    'index.php?page=task&id=' + S.fabrikTask, 'index.php?page=work', 'index.php?page=enquiries', 'index.php?page=invoicing', 'index.php?page=items', 'index.php?page=more', 'index.php?page=check',
    'add-work.php', 'add-work.php?client_id=' + S.client.id, 'add-enquiry.php', 'convert-enquiry.php?id=1', 'edit-task.php?id=2048', 'edit-enquiry.php?id=1',
    'edit-client.php?id=' + S.client.id, 'edit-client.php', 'edit-contact.php?client_id=' + S.client.id, 'edit-contact.php?id=' + S.contactId,
    'edit-task.php?id=' + S.fabrikTask, 'edit-task.php?client_id=' + S.client.id, 'edit-work.php?id=4096', 'edit-work.php?id=' + S.fabrikWork,
    'index.php?page=tasks&status=all&sort=queue&priority=immediate', 'index.php?page=work&staff=geoff&from=2020-01-01', 'index.php?page=clients&role=accounts'];
  for (const w of [...PHONES, DESKTOP]) {
    await test('L' + w, `Every screen at ${w}px: loads, no sideways scroll, no CSP/JS errors, navigation usable, content clear of nav`, async () => {
      const { ctx, page } = await newSession(w);
      const issues = [];
      for (const u of routes) {
        page._problems.length = 0;
        const r = await go(page, u);
        if (r.status() !== 200) issues.push(`${u}: HTTP ${r.status()}`);
        const m = await page.evaluate(() => {
          const de = document.documentElement, nav = document.querySelector('nav.bottom'), side = document.querySelector('aside.side');
          const navVisible = nav && getComputedStyle(nav).display !== 'none';
          const links = navVisible ? [...nav.querySelectorAll('a')].map(a => a.getBoundingClientRect()) : [];
          window.scrollTo(0, de.scrollHeight);
          const main = document.querySelector('main'); const last = main && main.lastElementChild;
          const lastRect = last ? last.getBoundingClientRect() : null; const navRect = navVisible ? nav.getBoundingClientRect() : null;
          return { overflow: de.scrollWidth > de.clientWidth + 1, navVisible, sideVisible: side && getComputedStyle(side).display !== 'none', navLinks: links.length,
            navMinH: links.length ? Math.min(...links.map(l => l.height)) : 0, navFits: navVisible ? nav.scrollWidth <= nav.clientWidth + 1 : true,
            hidden: lastRect && navRect ? lastRect.bottom > navRect.top + 1 : false, version: (document.querySelector('[data-testid=version]') || {}).textContent || '',
            wide: [...document.querySelectorAll('main *')].filter(e => e.getBoundingClientRect().right > de.clientWidth + 1 && getComputedStyle(e).position !== 'fixed').slice(0, 3).map(e => e.tagName + '.' + e.className) };
        });
        if (m.overflow) issues.push(`${u}: horizontal overflow ${m.wide.join(',')}`);
        if (m.version.trim() !== VERSION_LABEL) issues.push(`${u}: version label "${m.version}" (expected ${VERSION_LABEL})`);
        if (w < 760) {
          if (!m.navVisible || m.navLinks !== 5) issues.push(`${u}: bottom nav ${m.navVisible} links=${m.navLinks}`);
          if (m.navMinH < 44) issues.push(`${u}: nav tap target ${m.navMinH}px`);
          if (!m.navFits) issues.push(`${u}: bottom nav scrolls sideways`);
          if (m.hidden) issues.push(`${u}: last content hidden behind bottom nav`);
        } else if (m.navVisible || !m.sideVisible) issues.push(`${u}: desktop nav wrong (bottom ${m.navVisible}, side ${m.sideVisible})`);
        if (page._problems.length) issues.push(`${u}: ${page._problems.join(' | ')}`);
        if (SHOTS && (w === 375 || w === DESKTOP)) await shot(page, `w${w}_${u.replace(/[^a-z0-9]+/gi, '_')}`);
      }
      note(`${routes.length} screens checked`);
      assert(!issues.length, issues.join('\n'));
      await ctx.close();
    });
  }

  /* ================= instant client finder ================= */
  let shared = await newSession(375);
  const P = shared.page;
  await test('F01', 'Typing a client name on Today shows matching clients instantly', async () => {
    await go(P, 'index.php?page=today');
    await P.click('#finder-q'); const t0 = Date.now();
    await P.keyboard.type(S.client.name.slice(0, 5), { delay: 30 });
    await P.waitForSelector('[data-finder-results] .fr-item', { timeout: 3000 });
    note(`first results after ${Date.now() - t0} ms (includes typing and one-time index load)`);
    const names = await P.$$eval('[data-finder-results] .fr-item strong', els => els.map(e => e.textContent));
    assert(names.some(n => n.includes(S.client.name)), `"${S.client.name}" not in ${names.join(' | ')}`);
    await P.fill('#finder-q', ''); await P.keyboard.type(S.client.name.slice(0, 5));
    const t1 = Date.now(); await P.waitForSelector('[data-finder-results] .fr-item'); note(`with index cached: ${Date.now() - t1} ms`);
  });
  await test('F02', 'Finder matches a contact person and shows which contact matched', async () => {
    await P.fill('#finder-q', S.contact.name);
    await P.waitForSelector('[data-finder-results] .fr-item');
    const items = await P.$$eval('[data-finder-results] .fr-item', els => els.map(e => e.textContent));
    assert(items.some(t => t.includes(S.client.name) && t.includes(S.contact.name)), `contact ${S.contact.name} -> ${items.join(' | ')}`);
  });
  await test('F03', 'Finder matches phone digits (any spacing)', async () => {
    const digits = S.contact.phone.replace(/\D/g, '').slice(-6);
    await P.fill('#finder-q', digits.slice(0, 3) + ' ' + digits.slice(3));
    await P.waitForSelector('[data-finder-results] .fr-item');
    const items = await P.$$eval('[data-finder-results] .fr-item strong', els => els.map(e => e.textContent));
    assert(items.some(n => n.includes(S.client.name)), `phone ${digits} -> ${items.join(' | ')}`);
  });
  await test('F04', 'Finder matches an email fragment', async () => {
    const frag = S.emailClient.email.split('@')[0].slice(0, 8);
    await P.fill('#finder-q', frag);
    await P.waitForSelector('[data-finder-results] .fr-item');
    const items = await P.$$eval('[data-finder-results] .fr-item strong', els => els.map(e => e.textContent));
    assert(items.some(n => n.includes(S.emailClient.name)), `email ${frag} -> ${items.join(' | ')}`);
  });
  await test('F05', 'Keyboard: Arrow + Enter opens the client; it then appears under Recent on Today', async () => {
    await P.fill('#finder-q', S.client.name);
    await P.waitForSelector('[data-finder-results] .fr-item');
    await Promise.all([P.waitForNavigation(), P.keyboard.press('Enter')]);
    assert(P.url().includes('page=client&id=' + S.client.id), 'went to ' + P.url());
    await go(P, 'index.php?page=today');
    const recent = await P.$$eval('[data-recent-clients] a', els => els.map(e => e.textContent));
    assert(recent[0] === S.client.name, 'recent: ' + recent.join(','));
  });
  await test('F06', '"See all" appears when more than 8 clients match', async () => {
    await P.fill('#finder-q', S.manyMatch);
    await P.waitForSelector('[data-finder-results] .fr-more');
    const t = await P.locator('.fr-more').innerText(); assert(/See all \d+ matches/.test(t), t);
  });
  await test('F07', 'Without JavaScript, server search finds clients by contact name', async () => {
    const { ctx, page } = await newSession(375, { js: false });
    await go(page, 'index.php?page=clients&q=' + encodeURIComponent(S.contact.name));
    const names = await page.$$eval('.client-list .record strong', els => els.map(e => e.textContent));
    assert(names.some(n => n.includes(S.client.name)), names.join(' | '));
    await ctx.close();
  });

  await test('F08', 'Server search is case-insensitive for business names and client emails (MySQL 8 JSON collation)', async () => {
    const r = sql(`SELECT id,name,JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$.business_name')) b FROM clients
       WHERE COALESCE(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$.business_name')),'') NOT IN ('','null')
         AND LOCATE(LOWER(SUBSTRING(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$.business_name')),1,6)), LOWER(name))=0 ORDER BY id LIMIT 1`)[0];
    const e = sql(`SELECT id,JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$.email')) FROM clients WHERE JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$.email')) LIKE '%@%' ORDER BY id LIMIT 1`)[0];
    const { ctx, page } = await newSession(375, { js: false });
    const checks = [];
    if (r) checks.push([+r[0], r[2].slice(0, 6).toUpperCase(), 'business name']);
    if (e) checks.push([+e[0], e[1].split('@')[0].slice(0, 6).toUpperCase(), 'client email']);
    for (const [cid, term, label] of checks) {
      await go(page, 'index.php?page=clients&show=all&q=' + encodeURIComponent(term));
      const hrefs = await page.$$eval('.client-list a.record', as => as.map(a => a.getAttribute('href')));
      assert(hrefs.some(h => h.endsWith('id=' + cid)), `${label} search "${term}" missed client ${cid}`);
      note(`${label}: "${term}" found`);
    }
    await ctx.close();
  });

  /* ================= client page ================= */
  await test('C01', 'Client page: call, email, log work and enquiry within one tap; contacts and open tasks match database', async () => {
    await go(P, 'index.php?page=client&id=' + S.client.id);
    assert((await P.locator('h1').innerText()).trim() === S.client.name.trim(), 'h1');
    const actions = await P.$$eval('.client-actions a', as => as.map(a => a.getAttribute('href')));
    assert(actions.some(h => h.startsWith('tel:')), 'no tel: link ' + actions);
    assert(actions.includes('add-work.php?client_id=' + S.client.id) && actions.includes('add-enquiry.php?client_id=' + S.client.id), 'action links ' + actions);
    const contactLinks = await P.$$eval('.contact-links a', as => as.map(a => a.getAttribute('href')));
    assert(contactLinks.some(h => h.startsWith('tel:') && h.replace(/\D/g, '').endsWith(S.contact.phone.replace(/\D/g, '').slice(-6))), 'contact tel link missing');
    const shownContacts = await P.locator('.contact-list .contact-card').count();
    const dbActive = num(`SELECT COUNT(*) FROM contacts WHERE client_id=${S.client.id} AND COALESCE(status,'')<>'archive'`);
    assert(shownContacts === dbActive, `contacts shown ${shownContacts} vs db ${dbActive}`);
    const dbOpen = num(`SELECT COUNT(*) FROM tasks t WHERE client_id=${S.client.id} AND NOT (${closed('t.status')}) AND completed_at IS NULL`);
    const shownOpen = parseInt(await P.locator('.client-summary div:first-child strong').innerText(), 10);
    assert(shownOpen === Math.min(dbOpen, 60), `open tasks ${shownOpen} vs db ${dbOpen}`);
  });
  await test('C02', 'Activity filter chips show only the chosen kind', async () => {
    await go(P, 'index.php?page=client&id=' + S.client.id);
    if (!(await P.locator('[data-timeline] .tl-item').count())) { note('client has no activity'); return; }
    await P.click('[data-timeline-filter] [data-k=work]');
    const kinds = await P.$$eval('[data-timeline] .tl-item', els => els.filter(e => !e.hidden).map(e => e.dataset.k));
    assert(kinds.every(k => k === 'work'), 'kinds ' + kinds);
  });

  /* ================= Log work ================= */
  const workMax = () => num('SELECT COALESCE(MAX(id),0) FROM work_log');
  await test('W01', 'Log work from a client page: client pre-chosen, 30m preset, saved with correct fields and audit event', async () => {
    await go(P, 'index.php?page=client&id=' + S.client.id);
    await Promise.all([P.waitForNavigation(), P.click('.client-actions a.primary')]);
    assert((await P.locator('.ta-chosen span').innerText()).trim() === S.client.name.trim(), 'chosen client pill');
    await P.waitForTimeout(150);
    const opts = await P.$$eval('#task_id option', os => os.map(o => o.value).filter(Boolean));
    const dbTasks = sql(`SELECT id FROM tasks WHERE client_id=${S.client.id}`).map(r => r[0]);
    assert(opts.every(v => dbTasks.includes(v)), 'task list contains other clients\' tasks');
    const before = workMax();
    await P.fill('#details', 'Test W01 phone call about site');
    await P.click('[data-presets] button[data-min="30"]');
    await Promise.all([P.waitForNavigation(), P.click('button.save')]);
    assert(await P.locator('.success').count() === 1, 'no success message');
    const row = sql(`SELECT client_id,task_id,duration_seconds,billable_seconds,billability,source_system,work_date,details FROM work_log WHERE id>${before}`);
    assert(row.length === 1, 'rows created: ' + row.length);
    const [cid, tid, d, b, bill, src, wd] = row[0];
    assert(+cid === S.client.id && tid === null && +d === 1800 && +b === 0 && bill === 'review' && src === 'm5' && wd === todayNZ, 'row ' + row[0].join(','));
    assert(num(`SELECT COUNT(*) FROM activity_events WHERE work_log_id=${before + 1} AND event_kind='work_log_created' AND client_id=${S.client.id}`) === 1 || num(`SELECT COUNT(*) FROM activity_events e JOIN work_log w ON w.id=e.work_log_id WHERE w.id>${before} AND e.event_kind='work_log_created'`) === 1, 'audit event');
  });
  await test('W02', 'Log work from a task with start/finish times: task and its client recorded', async () => {
    await go(P, 'add-work.php?task_id=' + S.openTask.id);
    assert(await P.inputValue('#task_id') === String(S.openTask.id), 'task not preselected');
    const before = workMax();
    await P.fill('#details', 'Test W02 timed session');
    await P.check('input[name=mode][value=times]');
    await P.fill('#start', '09:00'); await P.fill('#end', '10:30');
    await Promise.all([P.waitForNavigation(), P.click('button.save')]);
    const row = sql(`SELECT client_id,task_id,duration_seconds,started_at,ended_at FROM work_log WHERE id>${before}`);
    assert(row.length === 1 && +row[0][0] === S.openTask.client && +row[0][1] === S.openTask.id && +row[0][2] === 5400 && row[0][3].endsWith('09:00:00'), 'row ' + JSON.stringify(row));
  });
  await test('W03', 'Server refuses a task that belongs to a different client (nothing saved)', async () => {
    await go(P, 'add-work.php?task_id=' + S.openTask.id);
    const before = workMax();
    await P.fill('#details', 'Test W03 mismatch');
    await P.evaluate(cid => { const s = document.querySelector('[data-client-select]'); s.value = String(cid); }, S.otherClient);
    await Promise.all([P.waitForNavigation(), P.click('button.save')]);
    const alert = await P.locator('.alert').innerText();
    assert(/different client/.test(alert), 'alert: ' + alert);
    assert(workMax() === before, 'row was created');
  });
  await test('W04', 'Validation: finish before start, zero duration, billable over worked — all rejected, nothing saved', async () => {
    const before = workMax();
    await go(P, 'add-work.php'); await P.fill('#details', 'Test W04a'); await P.check('input[name=mode][value=times]'); await P.fill('#start', '11:00'); await P.fill('#end', '10:00');
    await Promise.all([P.waitForNavigation(), P.click('button.save')]); assert(/Finish must follow start/.test(await P.locator('.alert').innerText()), 'times msg');
    await go(P, 'add-work.php'); await P.fill('#details', 'Test W04b'); await P.fill('#hours', '0'); await P.fill('#minutes', '0'); await P.fill('#seconds', '0');
    await Promise.all([P.waitForNavigation(), P.click('button.save')]); assert(/greater than zero/.test(await P.locator('.alert').innerText()), 'zero msg');
    await go(P, 'add-work.php'); await P.fill('#details', 'Test W04c'); await P.click('details summary:has-text("Billing")');
    await P.selectOption('#billability', 'billable'); await P.fill('#bill_hours', '1');
    await Promise.all([P.waitForNavigation(), P.click('button.save')]); assert(/cannot exceed/.test(await P.locator('.alert').innerText()), 'billable msg');
    assert(workMax() === before, 'a row was created');
  });
  await test('W05', 'Timer: start, survives reload, stop fills duration, saved as exact seconds', async () => {
    await P.evaluate(() => localStorage.removeItem('cm_timer'));
    await go(P, 'add-work.php?client_id=' + S.client.id);
    await P.check('input[name=mode][value=timer]');
    await P.click('[data-timer-start]');
    await P.waitForTimeout(1200);
    await P.reload();
    assert(await P.isChecked('input[name=mode][value=timer]'), 'timer mode not restored after reload');
    await P.waitForTimeout(1300);
    const clock = await P.locator('[data-timer-clock]').innerText();
    assert(/^0:00:0[2-4]$/.test(clock), 'clock ' + clock);
    await P.click('[data-timer-stop]');
    const secs = +(await P.inputValue('#seconds')), mins = +(await P.inputValue('#minutes'));
    assert(mins === 0 && secs >= 2 && secs <= 4, `filled ${mins}m ${secs}s`);
    const before = workMax();
    await P.fill('#details', 'Test W05 timer');
    await Promise.all([P.waitForNavigation(), P.click('button.save')]);
    const d = num(`SELECT duration_seconds FROM work_log WHERE id>${before}`);
    assert(d >= 2 && d <= 4, 'saved ' + d);
  });
  await test('W06', 'Double-tapping Save creates exactly one entry', async () => {
    await go(P, 'add-work.php'); const before = workMax();
    await P.fill('#details', 'Test W06 double tap');
    await Promise.all([P.waitForNavigation(), P.evaluate(() => { const b = document.querySelector('button.save'); b.click(); b.click(); b.click(); })]);
    await P.waitForTimeout(300);
    assert(workMax() - before === 1, 'rows: ' + (workMax() - before));
  });
  await test('W07', 'Task search on Log work finds a task and records its client', async () => {
    await go(P, 'add-work.php');
    const title = one(`SELECT title FROM tasks WHERE id=${S.fabrikTaskWithClient}`);
    await P.fill('#task-find', title.slice(0, Math.min(12, title.length)));
    await P.waitForSelector('[data-task-results] .fr-item');
    const items = P.locator('[data-task-results] .fr-item');
    const n = await items.count(); let clicked = false;
    for (let i = 0; i < n; i++) { if ((await items.nth(i).innerText()).includes(title)) { await items.nth(i).click(); clicked = true; break; } }
    assert(clicked, 'task not in search results');
    const before = workMax();
    await P.fill('#details', 'Test W07 via task search');
    await Promise.all([P.waitForNavigation(), P.click('button.save')]);
    const r = sql(`SELECT task_id,client_id FROM work_log WHERE id>${before}`)[0];
    assert(+r[0] === S.fabrikTaskWithClient && +r[1] === +one(`SELECT client_id FROM tasks WHERE id=${S.fabrikTaskWithClient}`), 'row ' + r);
  });

  /* ================= Quick Enquiry ================= */
  const enqMax = () => num('SELECT COALESCE(MAX(id),0) FROM enquiries');
  let newEnquiry = 0;
  await test('E01', 'Quick Enquiry from a client page: client pre-chosen; follow-up and breakdown saved with audit event', async () => {
    await go(P, 'index.php?page=client&id=' + S.client.id);
    await Promise.all([P.waitForNavigation(), P.click('.client-actions a[href^="add-enquiry.php"]')]);
    assert((await P.locator('.ta-chosen span').innerText()).trim() === S.client.name.trim(), 'client not preselected');
    const before = enqMax();
    await P.fill('#title', 'Test E01 new gallery page');
    await P.click('summary:has-text("Next action")'); await P.fill('#next_action', 'Send draft layout');
    const tomorrow = new Intl.DateTimeFormat('en-CA', { timeZone: 'Pacific/Auckland' }).format(new Date(Date.now() + 86400000));
    await P.fill('#next_action_due', tomorrow);
    await P.click('summary:has-text("breakdown")'); await P.fill('#line_description', 'Gallery page build'); await P.fill('#line_unit_price', '450.00');
    await Promise.all([P.waitForNavigation(), P.click('button.save')]);
    assert(await P.locator('.success a[href^="convert-enquiry.php"]').count() === 1, 'no convert link after save');
    const r = sql(`SELECT id,client_id,status,next_action_due FROM enquiries WHERE id>${before}`);
    assert(r.length === 1 && +r[0][1] === S.client.id && r[0][2] === 'enquiry' && r[0][3] === tomorrow, 'row ' + JSON.stringify(r));
    newEnquiry = +r[0][0];
    assert(num(`SELECT COUNT(*) FROM breakdown_items WHERE enquiry_id=${newEnquiry} AND unit_price_ex_gst=450.00 AND status='proposed'`) === 1, 'breakdown row');
    assert(num(`SELECT COUNT(*) FROM activity_events WHERE enquiry_id=${newEnquiry} AND event_kind='enquiry_created'`) === 1, 'audit');
  });
  await test('E02', 'Quick Enquiry without a client shows a clear message and saves nothing', async () => {
    await go(P, 'add-enquiry.php'); const before = enqMax();
    await P.fill('#title', 'Test E02 no client');
    await P.click('button.save');
    await P.waitForTimeout(300);
    assert(await P.locator('.client-picker .form-error').isVisible(), 'no inline message');
    assert(enqMax() === before, 'saved without client');
  });
  await test('E03', 'Today lists the new enquiry follow-up', async () => {
    await go(P, 'index.php?page=today');
    const items = await P.$$eval('[data-testid=attention-queue] li', ls => ls.map(l => l.textContent));
    assert(items.some(t => t.includes('Test E01 new gallery page') && /Follow up/i.test(t)), items.join(' | ').slice(0, 400));
  });

  /* ================= conversion & idempotency ================= */
  const tasksFor = e => num(`SELECT COUNT(*) FROM tasks WHERE enquiry_id=${e}`);
  await test('V01', 'Convert enquiry to task: one open task, enquiry marked, audit event, lands on task', async () => {
    await go(P, 'convert-enquiry.php?id=' + newEnquiry);
    await Promise.all([P.waitForNavigation(), P.click('form[data-once] button')]);
    const tid = one(`SELECT id FROM tasks WHERE enquiry_id=${newEnquiry}`);
    assert(P.url().includes('page=task&id=' + tid), 'landed ' + P.url());
    assert(tasksFor(newEnquiry) === 1 && one(`SELECT status FROM tasks WHERE id=${tid}`) === 'open' && one(`SELECT status FROM enquiries WHERE id=${newEnquiry}`) === 'task_created', 'state');
    assert(num(`SELECT COUNT(*) FROM activity_events WHERE enquiry_id=${newEnquiry} AND event_kind='enquiry_converted'`) === 1, 'event');
  });
  await test('V02', 'Re-opening or re-posting a converted enquiry never creates a second task', async () => {
    await go(P, 'convert-enquiry.php?id=' + newEnquiry);
    assert(await P.locator('form[data-once]').count() === 0, 'convert form still offered');
    assert(/Already progressed/.test(await P.locator('.success').innerText()), 'no already-progressed message');
    const r = await P.request.post(BASE + 'convert-enquiry.php', { form: { id: String(newEnquiry), csrf: 'stale-token' }, maxRedirects: 0 });
    assert(r.status() === 403, 'stale post status ' + r.status());
    assert(tasksFor(newEnquiry) === 1, 'tasks ' + tasksFor(newEnquiry));
  });
  await test('V03', 'Concurrency: 8 separate sessions convert the same enquiry at once → exactly one task', async () => {
    const cid = S.otherClient;
    sql(`INSERT INTO enquiries (client_id,title,priority,status) VALUES (${cid},'Test V03 race','normal','enquiry')`);
    const e = num('SELECT MAX(id) FROM enquiries');
    const sessions = [];
    for (let i = 0; i < 8; i++) { const s = await newSession(375); await go(s.page, 'convert-enquiry.php?id=' + e); const csrf = await s.page.inputValue('form[data-once] input[name=csrf]'); sessions.push({ ...s, csrf }); }
    const res = await Promise.all(sessions.map(s => s.page.request.post(BASE + 'convert-enquiry.php', { form: { id: String(e), csrf: s.csrf }, maxRedirects: 0 })));
    const codes = res.map(r => r.status()); const locs = [...new Set(res.map(r => r.headers()['location']))];
    for (const s of sessions) await s.ctx.close();
    note('responses: ' + codes.join(',') + ' → ' + locs.join(' '));
    assert(tasksFor(e) === 1, 'tasks created: ' + tasksFor(e));
    assert(codes.every(c => c === 303) && locs.length === 1, 'all requests should land on the same task');
    assert(num(`SELECT COUNT(*) FROM activity_events WHERE enquiry_id=${e} AND event_kind='enquiry_converted'`) === 1, 'events');
  });

  /* ================= edits & historical protection ================= */
  await test('D01', 'Edit an enquiry-generated task: saved, completed date set, change audited', async () => {
    const tid = +one(`SELECT id FROM tasks WHERE enquiry_id=${newEnquiry}`);
    await go(P, 'edit-task.php?id=' + tid);
    await P.selectOption('#status', 'completed'); await P.fill('#instructions', 'Test D01 updated instructions');
    await Promise.all([P.waitForNavigation(), P.click('button.save')]);
    const r = sql(`SELECT status,completed_at,instructions FROM tasks WHERE id=${tid}`)[0];
    assert(r[0] === 'completed' && r[1] && r[2] === 'Test D01 updated instructions', 'row ' + r);
    const payload = one(`SELECT payload FROM activity_events WHERE task_id=${tid} AND event_kind='task_updated' ORDER BY id DESC LIMIT 1`);
    assert(payload && payload.includes('"status"') && payload.includes('"previous"'), 'audit payload ' + payload);
  });
  await test('D03', 'Converted enquiries cannot be edited; unconverted ones save with an audit event', async () => {
    await go(P, 'edit-enquiry.php?id=' + newEnquiry);
    assert(await P.locator('form.form').count() === 0, 'converted enquiry editable');
    const open = +one(`SELECT e.id FROM enquiries e WHERE e.status='enquiry' AND NOT EXISTS (SELECT 1 FROM tasks t WHERE t.enquiry_id=e.id) ORDER BY e.id LIMIT 1`);
    if (!open) { note('no unconverted enquiry to edit'); return; }
    await go(P, 'edit-enquiry.php?id=' + open);
    await P.fill('#title', 'Test D03 edited title');
    await Promise.all([P.waitForNavigation(), P.click('button.save')]);
    assert(one(`SELECT title FROM enquiries WHERE id=${open}`) === 'Test D03 edited title', 'not saved');
    assert(num(`SELECT COUNT(*) FROM activity_events WHERE enquiry_id=${open} AND event_kind='enquiry_updated'`) >= 1, 'no audit');
  });

  /* ================= v0.10.0 editing ================= */
  const ver = async page => ({ csrf: await page.inputValue('input[name=csrf]'), ver: await page.inputValue('input[name=ver]') });
  const save = async page => { await Promise.all([page.waitForNavigation(), page.click('button.save')]); };
  const setSelect = async (page, sel, v) => page.evaluate(([s, v]) => { const e = document.querySelector(s); e.value = String(v); e.dispatchEvent(new Event('cm-sync')); e.dispatchEvent(new Event('change', { bubbles: true })); }, [sel, v]);
  const openDetails = async (page, text) => page.evaluate(t => { for (const d of document.querySelectorAll('details')) if (d.querySelector('summary').textContent.includes(t)) d.open = true; }, text);
  const audit = (where, kind) => one(`SELECT payload FROM activity_events WHERE ${where} AND event_kind='${kind}' ORDER BY id DESC LIMIT 1`);

  await test('X01', 'Edit an imported client: saved to the new columns, original record untouched, audited, finder finds the new phone at once', async () => {
    const cid = S.client.id;
    const origBefore = one(`SELECT CRC32(CAST(legacy_record AS CHAR)) FROM clients WHERE id=${cid}`);
    // warm the finder cache first, so the test proves the cache is refreshed after a save
    await go(P, 'index.php?page=today'); await P.fill('#finder-q', S.client.name.slice(0, 4)); await P.waitForSelector('[data-finder-results] .fr-item');
    await go(P, 'edit-client.php?id=' + cid);
    await P.fill('#phone', '03 555 0199');
    await openDetails(P, 'Notes'); await P.fill('#notes', 'Test X01 note\nsecond line');
    await save(P);
    assert(P.url().includes('page=client&id=' + cid) && /Saved/.test(await P.locator('.success').innerText()), 'no saved message: ' + P.url());
    const r = sql(`SELECT phone,notes LIKE 'Test X01 note%second line',updated_at IS NOT NULL FROM clients WHERE id=${cid}`)[0];
    assert(r[0] === '03 555 0199' && r[1] === '1' && r[2] === '1', 'row ' + r);
    assert(one(`SELECT CRC32(CAST(legacy_record AS CHAR)) FROM clients WHERE id=${cid}`) === origBefore, 'legacy_record changed');
    const p = JSON.parse(audit(`client_id=${cid}`, 'client_updated'));
    assert(p.phone && p.phone.current === '03 555 0199' && 'previous' in p.phone && p.notes, 'audit ' + JSON.stringify(p));
    assert((await P.locator('main').innerText()).includes('03 555 0199'), 'new phone not shown on client page');
    await go(P, 'index.php?page=today'); await P.fill('#finder-q', '5550199');
    await P.waitForSelector('[data-finder-results] .fr-item', { timeout: 3000 });
    const names = await P.$$eval('[data-finder-results] .fr-item strong', e => e.map(x => x.textContent));
    assert(names.some(n => n.includes(S.client.name)), 'finder did not find new phone: ' + names.join('|'));
  });
  await test('X02', 'Archive and restore a client: tag shown, hidden from Active list, still found under All', async () => {
    const cid = S.anyClient, name = one(`SELECT name FROM clients WHERE id=${cid}`);
    await go(P, 'edit-client.php?id=' + cid); await P.selectOption('#status', 'archive'); await save(P);
    assert(one(`SELECT status FROM clients WHERE id=${cid}`) === 'archive', 'not archived');
    assert(await P.locator('.client-head .tag').count() === 1, 'no Archived tag');
    await go(P, 'index.php?page=clients&show=active&q=' + encodeURIComponent(name));
    const active = await P.$$eval('.client-list a.record', a => a.map(x => x.getAttribute('href')));
    assert(!active.some(h => h.endsWith('id=' + cid)), 'archived client in Active list');
    await go(P, 'index.php?page=clients&show=all&q=' + encodeURIComponent(name));
    const all = await P.$$eval('.client-list a.record', a => a.map(x => x.getAttribute('href')));
    assert(all.some(h => h.endsWith('id=' + cid)), `not in All: ${P.url()} -> ${all.join(',')} / ${await P.locator('[data-testid=client-count]').innerText()}`);
    await go(P, 'edit-client.php?id=' + cid); await P.selectOption('#status', 'active'); await save(P);
    assert(one(`SELECT status FROM clients WHERE id=${cid}`) === 'active', 'not restored');
    assert(/Client restored/.test(one(`SELECT summary FROM activity_events WHERE client_id=${cid} AND event_kind='client_updated' ORDER BY id DESC LIMIT 1`)), 'restore audit');
  });
  let x03 = 0;
  await test('X03', 'New client: created as an app record; same name again is blocked until "Create anyway"', async () => {
    const before = num('SELECT COUNT(*) FROM clients');
    await go(P, 'edit-client.php'); await P.fill('#name', 'Test X03 Ltd'); await P.fill('#email', 'x03@example.nz'); await save(P);
    x03 = +one(`SELECT id FROM clients WHERE name='Test X03 Ltd'`);
    assert(x03 && P.url().includes('page=client&id=' + x03), 'not created/redirected');
    assert(one(`SELECT CONCAT(source_system,'|',status,'|',email) FROM clients WHERE id=${x03}`) === 'app|active|x03@example.nz', 'row');
    assert(audit(`client_id=${x03}`, 'client_created'), 'no audit');
    await go(P, 'edit-client.php'); await P.fill('#name', 'test x03 ltd'); await save(P);
    assert(/already exists/.test(await P.locator('.alert').innerText()) && num('SELECT COUNT(*) FROM clients') === before + 1, 'duplicate not blocked');
    await P.check('input[name=confirm_duplicate]'); await save(P);
    assert(num('SELECT COUNT(*) FROM clients') === before + 2, 'create anyway failed');
  });
  await test('X04', 'Add a contact (types as checkboxes), then move it to another client: both clients audited', async () => {
    await go(P, 'edit-contact.php?client_id=' + x03);
    await P.fill('#first_name', 'Xena'); await P.fill('#last_name', 'Tester'); await P.fill('#mobile', '021 555 0404');
    await P.check('input[value="accounts"]'); await P.check('input[value="domain owner"]'); await P.fill('#contact_type_other', 'billing');
    await save(P);
    const k = sql(`SELECT id,display_name,contact_type,client_id,source_system FROM contacts WHERE display_name='Xena Tester'`)[0];
    assert(k && k[2] === 'accounts, domain owner, billing' && +k[3] === x03 && k[4] === 'app', 'row ' + k);
    assert((await P.locator('.contact-list').innerText()).includes('Xena Tester'), 'not shown on client page');
    await go(P, 'edit-contact.php?id=' + k[0]); await setSelect(P, '[data-client-select]', S.client.id); await save(P);
    assert(+one(`SELECT client_id FROM contacts WHERE id=${k[0]}`) === S.client.id, 'not moved');
    assert(audit(`client_id=${S.client.id}`, 'contact_updated') && audit(`client_id=${x03}`, 'contact_moved'), 'move audits');
  });
  await test('X05', 'A contact with no client can be given one from the Clients page', async () => {
    const u = sql(`SELECT id,CRC32(CAST(legacy_record AS CHAR)) FROM contacts WHERE client_id IS NULL ORDER BY id LIMIT 1`)[0];
    if (!u) { note('no unlinked contacts'); return; }
    await go(P, 'index.php?page=clients'); await openDetails(P, 'Contacts without a client');
    await Promise.all([P.waitForNavigation(), P.click(`a[href="edit-contact.php?id=${u[0]}"]`)]);
    await setSelect(P, '[data-client-select]', S.client.id); await save(P);
    assert(+one(`SELECT client_id FROM contacts WHERE id=${u[0]}`) === S.client.id, 'not linked');
    assert(one(`SELECT CRC32(CAST(legacy_record AS CHAR)) FROM contacts WHERE id=${u[0]}`) === u[1], 'legacy_record changed');
  });
  await test('X06', 'Two people editing the same client: the second save is refused, nothing is overwritten', async () => {
    const cid = S.anyClient;
    const other = await newSession(375);
    await go(P, 'edit-client.php?id=' + cid); await go(other.page, 'edit-client.php?id=' + cid);
    await other.page.fill('#phone', '03 555 0606'); await save(other.page);
    await openDetails(P, 'Notes'); await P.fill('#notes', 'Test X06 should not save'); await save(P);
    assert(/changed by someone else/.test(await P.locator('.alert').innerText()), 'no stale warning');
    const r = sql(`SELECT phone,COALESCE(notes,'') FROM clients WHERE id=${cid}`)[0];
    assert(r[0] === '03 555 0606' && !r[1].includes('X06'), 'overwritten: ' + r);
    await other.ctx.close();
  });
  await test('X07', 'Imported task: status/completed/notes/client save; title, instructions and billing stay locked even to a forged POST', async () => {
    const tid = +one(`SELECT id FROM tasks WHERE source_system='fabrik' AND client_id IS NOT NULL AND NOT (${closed('status')}) ORDER BY id LIMIT 1`);
    const lockedBefore = one(`SELECT CRC32(CONCAT_WS('|',title,COALESCE(instructions,''),COALESCE(agreed_fee_ex_gst,''),COALESCE(invoice_number,''),COALESCE(pricing,''))) FROM tasks WHERE id=${tid}`);
    await go(P, 'edit-task.php?id=' + tid);
    assert(await P.locator('#title').count() === 0 && await P.locator('#agreed_fee_ex_gst').count() === 0, 'locked fields offered');
    await P.selectOption('#status', 'completed'); await openDetails(P, 'Notes'); await P.fill('#notes', 'Test X07 closed after migration');
    await save(P);
    const r = sql(`SELECT status,DATE(completed_at),notes FROM tasks WHERE id=${tid}`)[0];
    assert(r[0] === 'completed' && r[1] === todayNZ && r[2] === 'Test X07 closed after migration', 'row ' + r);
    await go(P, 'edit-task.php?id=' + tid); const t = await ver(P);
    const resp = await P.request.post(BASE + 'edit-task.php', { maxRedirects: 0, form: { id: String(tid), csrf: t.csrf, ver: t.ver, status: 'completed', priority: '', required_by: '', completed_on: todayNZ, client_id: '', notes: 'x', title: 'HACKED', instructions: 'HACKED', agreed_fee_ex_gst: '999', pricing: 'hourly' } });
    assert([303, 200].includes(resp.status()), 'status ' + resp.status());
    assert(one(`SELECT CRC32(CONCAT_WS('|',title,COALESCE(instructions,''),COALESCE(agreed_fee_ex_gst,''),COALESCE(invoice_number,''),COALESCE(pricing,''))) FROM tasks WHERE id=${tid}`) === lockedBefore, 'LOCKED FIELD CHANGED');
  });
  await test('X08', 'New task from a client page with pricing; invalid fee refused', async () => {
    await go(P, 'index.php?page=client&id=' + S.client.id);
    await Promise.all([P.waitForNavigation(), P.click('a[href^="edit-task.php?client_id="]')]);
    await P.fill('#title', 'Test X08 new task'); await openDetails(P, 'Pricing');
    await P.selectOption('#pricing', 'set'); await P.fill('#agreed_fee_ex_gst', '12.345'); await save(P);
    assert(/2 decimals/.test(await P.locator('.alert').innerText()) && !one(`SELECT id FROM tasks WHERE title='Test X08 new task'`), 'bad fee accepted');
    await P.fill('#agreed_fee_ex_gst', '450.00'); await P.fill('#est_hours', '2'); await P.fill('#est_minutes', '0'); await save(P);
    const r = sql(`SELECT id,client_id,source_system,pricing,agreed_fee_ex_gst,estimate_minutes,status FROM tasks WHERE title='Test X08 new task'`)[0];
    assert(r && +r[1] === S.client.id && r[2] === 'app' && r[3] === 'set' && r[4] === '450.00' && r[5] === '120' && r[6] === 'open', 'row ' + r);
    assert(P.url().includes('page=task&id=' + r[0]) && audit(`task_id=${r[0]}`, 'task_created'), 'redirect/audit');
  });
  await test('X09', 'Edit a new Work Log entry: duration, client, billing decision and billable time saved; billable > worked refused', async () => {
    await go(P, 'edit-work.php?id=4096');
    await setSelect(P, '[data-client-select]', S.client.id);
    await P.fill('#hours', '1'); await P.fill('#minutes', '0'); await P.fill('#seconds', '0');
    await P.selectOption('#billability', 'billable'); await P.fill('#bill_hours', '2'); await save(P);
    assert(/cannot exceed/.test(await P.locator('.alert').innerText()), 'over-billable accepted');
    await go(P, 'edit-work.php?id=4096'); await setSelect(P, '[data-client-select]', S.client.id);
    await P.fill('#hours', '1'); await P.fill('#minutes', '0'); await P.selectOption('#billability', 'billable'); await P.fill('#bill_hours', '0'); await P.fill('#bill_minutes', '45');
    await save(P);
    const r = sql(`SELECT duration_seconds,billable_seconds,billability,client_id FROM work_log WHERE id=4096`)[0];
    assert(+r[0] === 3600 && +r[1] === 2700 && r[2] === 'billable' && +r[3] === S.client.id, 'row ' + r);
    assert(JSON.parse(audit('work_log_id=4096', 'work_log_updated')).duration_seconds, 'audit');
  });
  await test('X10', 'Imported Work Log entry: task link and billing decision save; description and time stay locked even to a forged POST', async () => {
    const wid = +one(`SELECT id FROM work_log WHERE source_system='fabrik' AND task_id IS NULL ORDER BY id LIMIT 1`);
    const lockedBefore = one(`SELECT CRC32(CONCAT_WS('|',details,duration_seconds,billable_seconds,COALESCE(billed_amount_ex_gst,''),COALESCE(staff_key,''))) FROM work_log WHERE id=${wid}`);
    await go(P, 'edit-work.php?id=' + wid);
    assert(await P.locator('#details').count() === 0 && await P.locator('#hours').count() === 0, 'locked fields offered');
    const opt = await P.$$eval('#task_id option', o => o.map(x => x.value).filter(Boolean));
    await P.selectOption('#task_id', opt[0]); await P.selectOption('#billability', 'non_billable'); await save(P);
    const r = sql(`SELECT task_id,billability FROM work_log WHERE id=${wid}`)[0];
    assert(r[0] === opt[0] && r[1] === 'non_billable', 'row ' + r);
    await go(P, 'edit-work.php?id=' + wid); const t = await ver(P);
    await P.request.post(BASE + 'edit-work.php', { maxRedirects: 0, form: { id: String(wid), csrf: t.csrf, ver: t.ver, task_id: opt[0], client_id: '', billability: 'review', details: 'HACKED', hours: '9', minutes: '0', seconds: '0', work_date: '2001-01-01', staff: 'hacker' } });
    assert(one(`SELECT CRC32(CONCAT_WS('|',details,duration_seconds,billable_seconds,COALESCE(billed_amount_ex_gst,''),COALESCE(staff_key,''))) FROM work_log WHERE id=${wid}`) === lockedBefore, 'LOCKED FIELD CHANGED');
  });
  await test('X11', 'Unconverted enquiry: client can be changed, audited', async () => {
    let eid = +one(`SELECT e.id FROM enquiries e WHERE e.status='enquiry' AND NOT EXISTS (SELECT 1 FROM tasks t WHERE t.enquiry_id=e.id) ORDER BY e.id LIMIT 1`);
    if (!eid) { sql(`INSERT INTO enquiries (client_id,title,priority,status) VALUES (${S.anyClient},'Test X11 enquiry','normal','enquiry')`); eid = num('SELECT MAX(id) FROM enquiries'); }
    await go(P, 'edit-enquiry.php?id=' + eid); await setSelect(P, '[data-client-select]', x03); await save(P);
    assert(+one(`SELECT client_id FROM enquiries WHERE id=${eid}`) === x03, 'client not changed');
    assert((audit(`enquiry_id=${eid}`, 'enquiry_updated') || '').includes('client_id'), 'audit');
  });
  await test('X12', 'Giving a new entry a billing decision removes it from Today\'s "Work to review"', async () => {
    if (!num(`SELECT COUNT(*) FROM work_log WHERE billability='review' AND (source_system IS NULL OR source_system<>'fabrik')`))
      sql(`INSERT INTO work_log (source_system,source_id,details,work_date,duration_seconds,billable_seconds,billability) VALUES ('m5','x12-${Date.now()}','Test X12 entry','${todayNZ}',600,0,'review')`);
    const wid = +one(`SELECT id FROM work_log WHERE billability='review' AND (source_system IS NULL OR source_system<>'fabrik') ORDER BY id DESC LIMIT 1`);
    const before = num(`SELECT COUNT(*) FROM work_log WHERE billability='review' AND (source_system IS NULL OR source_system<>'fabrik')`);
    await go(P, 'index.php?page=work&filter=review');
    await P.click(`summary:has-text("${one(`SELECT details FROM work_log WHERE id=${wid}`).replace(/"/g, '')}")`);
    await Promise.all([P.waitForNavigation(), P.click(`a[href^="edit-work.php?id=${wid}&"]`)]);
    await P.selectOption('#billability', 'non_billable'); await save(P);
    assert(P.url().includes('page=work') && P.url().includes('filter=review'), 'did not return to the filtered list: ' + P.url());
    await go(P, 'index.php?page=today');
    const txt = await P.locator('.review-links').innerText().catch(() => '');
    assert(before - 1 === 0 ? !txt.includes('billing decision') : txt.includes((before - 1) + ' new'), `review count not updated: "${txt}" (expected ${before - 1})`);
  });

  /* ================= v0.10.0 build 2: Fabrik features ================= */
  const csv = async url => { const r = await P.request.get(BASE + url); const b = await r.body(); return { status: r.status(), type: r.headers()['content-type'] || '', bom: b[0] === 0xEF && b[1] === 0xBB && b[2] === 0xBF, rows: parseCsv(b.toString('utf8').replace(/^﻿/, '')) }; };
  function parseCsv(t) { const rows = []; let row = [], cell = '', q = false; for (let i = 0; i < t.length; i++) { const ch = t[i]; if (q) { if (ch === '"') { if (t[i + 1] === '"') { cell += '"'; i++; } else q = false; } else cell += ch; } else if (ch === '"') q = true; else if (ch === ',') { row.push(cell); cell = ''; } else if (ch === '\n') { row.push(cell); rows.push(row); row = []; cell = ''; } else if (ch !== '\r') cell += ch; } if (cell !== '' || row.length) { row.push(cell); rows.push(row); } return rows; }

  await test('Y01', 'New task with the Fabrik fields: contact list follows client, category → type, Action for, queue, domains, chargeable, h:mm estimate, invoice no, billing advised', async () => {
    await go(P, 'edit-task.php');
    await P.fill('#title', 'Test Y01 full task');
    await setSelect(P, '[data-client-select]', S.client.id);
    await P.waitForFunction(() => document.querySelectorAll('[data-contact-select] option').length > 1, null, { timeout: 4000 });
    const kid = await P.$eval('[data-contact-select] option:nth-child(2)', o => o.value);
    await P.selectOption('#contact_id', kid);
    const cats = await P.$$eval('#task_category option', o => o.map(x => x.value).filter(Boolean));
    assert(cats.length > 0, 'no categories');
    const cat = cats.find(c => c.startsWith('Graphic')) || cats[0];
    await P.selectOption('#task_category', cat);
    const types = await P.$$eval('#task_type option', o => o.map(x => x.value).filter(Boolean));
    const expected = sql(`SELECT TRIM(JSON_UNQUOTE(JSON_EXTRACT(source_record,'$.task_type'))) FROM legacy_classification WHERE TRIM(JSON_UNQUOTE(JSON_EXTRACT(source_record,'$.task_category')))='${esc(cat)}' AND TRIM(JSON_UNQUOTE(JSON_EXTRACT(source_record,'$.task_type')))<>''`).map(r => r[0]);
    assert(types.length === new Set(expected).size && types.every(t => expected.includes(t)), `types ${types} vs ${expected}`);
    await P.selectOption('#task_type', types[0]);
    await P.check('input[name="assigned_to[]"][value="geoff"]'); await P.fill('#assigned_other', 'Matt');
    await P.selectOption('#priority', 'immediate'); await P.selectOption('#status', 'planned');
    await P.fill('#queue', '5'); await P.fill('#domains', 'y01.example.nz'); await P.fill('#job_number', 'J-Y01');
    await openDetails(P, 'Pricing'); await P.selectOption('#chargeable', 'yes'); await P.fill('#est_hours', '1'); await P.fill('#est_minutes', '30');
    await P.fill('#invoice_number', 'INV-Y01'); await P.check('input[name=billing_advised]');
    await save(P);
    const r = sql(`SELECT contact_id,assigned_to,queue,task_category,task_type,domains,chargeable,estimate_minutes,invoice_number,billing_advised,priority,status,job_number FROM tasks WHERE title='Test Y01 full task'`)[0];
    assert(r && r[0] === kid && r[1] === 'geoff, matt' && r[2] === '5' && r[3] === cat && r[4] === types[0] && r[5] === 'y01.example.nz' && r[6] === 'yes' && r[7] === '90' && r[8] === 'INV-Y01' && r[9] === '1' && r[10] === 'immediate' && r[11] === 'planned' && r[12] === 'J-Y01', 'row ' + r);
    const page = await P.locator('main').innerText();
    assert(page.includes('Geoff, Matt') && page.includes(cat), 'task page missing Action for / category');
  });
  await test('Y02', 'Imported task: Action for, queue and contact editable; a contact from another client is refused', async () => {
    const tid = +one(`SELECT id FROM tasks WHERE source_system='fabrik' AND client_id IS NOT NULL AND NOT (${closed('status')}) ORDER BY id DESC LIMIT 1`);
    await go(P, 'edit-task.php?id=' + tid);
    for (const b of await P.$$('input[name="assigned_to[]"]')) if (await b.isChecked()) await b.uncheck();
    await P.check('input[name="assigned_to[]"][value="roselyn"]'); await P.fill('#queue', '7'); await save(P);
    const r = sql(`SELECT assigned_to,queue FROM tasks WHERE id=${tid}`)[0];
    assert(r[0] === 'roselyn' && r[1] === '7', 'row ' + r);
    const foreign = one(`SELECT k.id FROM contacts k JOIN tasks t ON t.id=${tid} WHERE k.client_id IS NOT NULL AND k.client_id<>t.client_id LIMIT 1`);
    await go(P, 'edit-task.php?id=' + tid);
    await P.evaluate(v => { const s = document.querySelector('#contact_id'); s.appendChild(new Option('forged', v)); s.value = v; }, foreign);
    await save(P);
    assert(/different client/.test(await P.locator('.alert').innerText()), 'foreign contact accepted');
    assert(one(`SELECT COALESCE(contact_id,'') FROM tasks WHERE id=${tid}`) !== foreign, 'contact changed');
  });
  await test('Y03', 'Tasks list: Action for, priority and status filters match the database; queue sort is ascending', async () => {
    const who = S.staffTop;
    await go(P, `index.php?page=tasks&status=all&assigned=${encodeURIComponent(who)}`);
    const shown = await P.locator('.compact-list a.record').count();
    const dbn = num(`SELECT COUNT(*) FROM tasks WHERE CONCAT(', ', LOWER(COALESCE(assigned_to,'')), ',') LIKE '%, ${esc(who)},%'`);
    assert(shown === Math.min(dbn, 100), `assigned ${who}: ${shown} vs ${dbn}`);
    await go(P, 'index.php?page=tasks&status=all&priority=immediate');
    assert(await P.locator('.compact-list a.record').count() === Math.min(num(`SELECT COUNT(*) FROM tasks WHERE LOWER(COALESCE(priority,''))='immediate'`), 100), 'priority filter');
    await go(P, 'index.php?page=tasks&status=completed');
    assert(await P.locator('.compact-list a.record').count() === Math.min(num(`SELECT COUNT(*) FROM tasks WHERE LOWER(REPLACE(COALESCE(status,''),'_',' '))='completed'`), 100), 'exact status filter');
    await go(P, 'index.php?page=tasks&status=all&sort=queue');
    const ids = await P.$$eval('.compact-list a.record', a => a.slice(0, 40).map(x => +new URL(x.href).searchParams.get('id')));
    const qs = ids.map(i => { const v = one(`SELECT queue FROM tasks WHERE id=${i}`); return v === null ? Infinity : +v; });
    assert(qs.every((v, i) => i === 0 || qs[i - 1] <= v), 'queue order ' + qs.join(','));
    await go(P, 'index.php?page=tasks');
    await P.selectOption('select[name=priority]', 'immediate'); await P.waitForURL(/priority=immediate/, { timeout: 4000 });
  });
  await test('Y04', 'Work Log: staff + date filters, totals equal the database for the same filter', async () => {
    const who = one(`SELECT LOWER(staff_key) FROM work_log WHERE staff_key IS NOT NULL GROUP BY LOWER(staff_key) ORDER BY COUNT(*) DESC LIMIT 1`);
    const from = one(`SELECT DATE_SUB(MAX(COALESCE(work_date,DATE(started_at))), INTERVAL 400 DAY) FROM work_log`), to = one(`SELECT MAX(COALESCE(work_date,DATE(started_at))) FROM work_log`);
    await go(P, `index.php?page=work&staff=${encodeURIComponent(who)}&from=${from}&to=${to}`);
    const db = sql(`SELECT COUNT(*), COALESCE(SUM(duration_seconds),0), COALESCE(SUM(billable_seconds),0) FROM work_log WHERE LOWER(COALESCE(staff_key,''))='${esc(who)}' AND COALESCE(work_date,DATE(started_at)) BETWEEN '${from}' AND '${to}'`)[0];
    const fmt = s => Math.floor(s / 3600) + 'h ' + String(Math.floor(s % 3600 / 60)).padStart(2, '0') + 'm';
    const t = await P.locator('[data-testid=work-totals]').innerText();
    assert(t.includes(Number(db[0]).toLocaleString('en-NZ')) && t.includes(fmt(+db[1]) + ' worked') && t.includes(fmt(+db[2]) + ' billable'), `totals "${t}" vs ${db}`);
    note(`${who}, ${from}..${to}: ${db[0]} entries, ${fmt(+db[1])}`);
  });
  await test('Y05', 'CSV export: tasks, work and clients match the filtered lists; UTF-8 BOM; formula-looking cells neutralised', async () => {
    const who = S.staffTop;
    const t = await csv(`export.php?type=tasks&status=all&assigned=${encodeURIComponent(who)}`);
    assert(t.status === 200 && /text\/csv/.test(t.type) && t.bom, 'tasks headers');
    const dbn = num(`SELECT COUNT(*) FROM tasks WHERE CONCAT(', ', LOWER(COALESCE(assigned_to,'')), ',') LIKE '%, ${esc(who)},%'`);
    assert(t.rows[0][2] === 'Task title' && t.rows.length - 1 === dbn, `tasks rows ${t.rows.length - 1} vs ${dbn}`);
    const w = await csv('export.php?type=work&filter=new');
    assert(w.rows.length - 1 === num(`SELECT COUNT(*) FROM work_log WHERE source_system IS NULL OR source_system<>'fabrik'`), 'work rows');
    sql(`INSERT INTO clients (source_system,source_id,name,status) VALUES ('app','y05-${Date.now()}','=HYPERLINK("http://evil")','active')`);
    const c = await csv('export.php?type=clients&show=all&q=hyperlink');
    const cell = (c.rows.find(r => r[2] && r[2].includes('HYPERLINK')) || [])[2];
    assert(cell && cell.startsWith("'="), 'formula not neutralised: ' + cell);
    const signedOut = await api.get('export.php?type=tasks'); assert(signedOut.status() === 302, 'export open when signed out');
  });
  await test('Y06', 'Clients: contact-role filter lists exactly the clients with an active contact in that role', async () => {
    await go(P, 'index.php?page=clients&show=all&role=' + encodeURIComponent('domain owner'));
    const ids = await P.$$eval('.client-list a.record', a => a.map(x => +new URL(x.href).searchParams.get('id')));
    const db = sql(`SELECT DISTINCT client_id FROM contacts WHERE client_id IS NOT NULL AND COALESCE(status,'')<>'archive' AND CONCAT(', ', LOWER(COALESCE(contact_type,'')), ',') LIKE '%, domain owner,%'`).map(r => +r[0]);
    assert(ids.length === Math.min(db.length, 400) && ids.every(i => db.includes(i)), `role: ${ids.length} vs ${db.length}`);
  });
  await test('Y07', 'Quick Enquiry accepts the Fabrik priority "Immediate"', async () => {
    await go(P, 'add-enquiry.php?client_id=' + S.client.id); await P.fill('#title', 'Test Y07 immediate'); await P.selectOption('#priority', 'immediate'); await save(P);
    assert(one(`SELECT priority FROM enquiries WHERE title='Test Y07 immediate'`) === 'immediate', 'not saved');
  });
  await test('Y08', 'Task page shows time-sheet totals equal to the database', async () => {
    const tid = +one(`SELECT task_id FROM work_log WHERE task_id IS NOT NULL GROUP BY task_id ORDER BY COUNT(*) DESC LIMIT 1`);
    await go(P, 'index.php?page=task&id=' + tid);
    const db = sql(`SELECT COALESCE(SUM(duration_seconds),0), COALESCE(SUM(billable_seconds),0) FROM work_log WHERE task_id=${tid}`)[0];
    const fmt = s => Math.floor(s / 3600) + 'h ' + String(Math.floor(s % 3600 / 60)).padStart(2, '0') + 'm';
    const t = await P.locator('[data-testid=task-totals]').innerText();
    assert(t.includes(fmt(+db[0]) + ' worked') && t.includes(fmt(+db[1]) + ' billable'), `"${t}" vs ${db}`);
  });

  /* ================= Today correctness ================= */
  await test('T01', 'Today: no record-count tiles; queue items are real open/due records; backlog count matches database', async () => {
    await go(P, 'index.php?page=today');
    assert(await P.locator('.stats, .stat, .tiles').count() === 0, 'count tiles present');
    const staleFrom = new Intl.DateTimeFormat('en-CA', { timeZone: 'Pacific/Auckland' }).format(new Date(Date.now() - 60 * 86400000));
    const dbStale = num(`SELECT COUNT(*) FROM tasks t WHERE t.required_by IS NOT NULL AND DATE(t.required_by)<'${staleFrom}' AND NOT (${closed('t.status')}) AND t.completed_at IS NULL`);
    const fold = await P.locator('.fold-link:has-text("backlog")').count();
    if (dbStale) { const t = await P.locator('.fold-link:has-text("backlog") small').innerText(); assert(t.startsWith(String(dbStale) + ' '), `backlog "${t}" vs db ${dbStale}`); }
    else assert(fold === 0, 'backlog link shown with no stale tasks');
    const dueDb = sql(`SELECT title FROM tasks t WHERE t.required_by IS NOT NULL AND DATE(t.required_by) BETWEEN '${staleFrom}' AND DATE_ADD('${todayNZ}',INTERVAL 7 DAY) AND NOT (${closed('t.status')}) AND t.completed_at IS NULL ORDER BY t.required_by LIMIT 10`).map(r => r[0]);
    const q = await P.$$eval('[data-testid=attention-queue] li strong', els => els.map(e => e.textContent));
    for (const t of dueDb.slice(0, 3)) assert(q.includes(t), `due task "${t}" missing from queue`);
    note(`queue items: ${q.length}; due/overdue in window: ${dueDb.length}; stale backlog: ${dbStale}`);
  });
  await test('T02', 'Today "Work to review" counts match new (non-imported) entries in the database', async () => {
    await go(P, 'index.php?page=today');
    const dbRev = num(`SELECT COUNT(*) FROM work_log WHERE billability='review' AND (source_system IS NULL OR source_system<>'fabrik')`);
    const txt = await P.locator('.review-links').innerText().catch(() => '');
    assert(dbRev === 0 || txt.includes(dbRev + ' new'), `review "${txt}" vs db ${dbRev}`);
    await P.click('.review-links a:first-child');
    const shown = await P.locator('.workitem').count();
    assert(shown === Math.min(dbRev, 100), `work filter shows ${shown} vs ${dbRev}`);
  });

  /* ================= misc ================= */
  await test('M01', 'Lookup API: client index, tasks for one client only, task search needs 2+ characters', async () => {
    const idx = await (await P.request.get(BASE + 'lookup.php?clients=1')).json();
    assert(idx.clients.length === num('SELECT COUNT(*) FROM clients'), 'index size');
    const tf = await (await P.request.get(BASE + 'lookup.php?tasks_for=' + S.client.id)).json();
    const ids = sql(`SELECT id FROM tasks WHERE client_id=${S.client.id}`).map(r => +r[0]);
    assert(tf.tasks.every(t => ids.includes(t.id)), 'foreign task in tasks_for');
    const tq = await (await P.request.get(BASE + 'lookup.php?task_q=a')).json(); assert(tq.tasks.length === 0, 'single-char search returned rows');
    const bad = await P.request.get(BASE + 'lookup.php?tasks_for=abc'); assert(bad.status() === 400, 'bad id status ' + bad.status());
  });
  await test('M02', 'Release check page: all schema checks pass and shows this build', async () => {
    await go(P, 'index.php?page=check');
    const head = await P.locator('.check-head').innerText();
    const failed = await P.$$eval('.checklist li.bad', ls => ls.map(l => l.textContent));
    assert(/All checks passed/.test(head), 'failed: ' + failed.join(' | '));
    assert((await P.locator('[data-testid=check-version]').innerText()).trim() === VERSION_LABEL, 'version');
  });
  await test('M03', 'Keyboard: first Tab reaches "Skip to content"; finder shows a visible focus ring', async () => {
    const { ctx, page } = await newSession(DESKTOP);
    await go(page, 'index.php?page=tasks');
    await page.keyboard.press('Tab');
    assert(await page.evaluate(() => document.activeElement.className) === 'skip', 'first tab: ' + await page.evaluate(() => document.activeElement.outerHTML.slice(0, 80)));
    await go(page, 'index.php?page=clients');
    const ring = await page.evaluate(() => { const i = document.querySelector('#finder-q'); i.focus(); const s = getComputedStyle(i); return { focused: document.activeElement === i, o: s.outlineStyle, b: s.borderColor }; });
    assert(ring.focused && (ring.o !== 'none' || ring.b !== ''), JSON.stringify(ring));
    await ctx.close();
  });
  await test('M04', 'Sign out clears device-side client traces and ends the session', async () => {
    await go(P, 'index.php?page=today');
    assert(await P.evaluate(() => Object.keys(localStorage).some(k => k.startsWith('cm_'))), 'precondition: recent list should exist');
    await Promise.all([P.waitForNavigation(), P.click('[data-signout] button')]);
    assert(!(await P.evaluate(() => Object.keys(localStorage).some(k => k.startsWith('cm_')))), 'localStorage not cleared');
    const r = await go(P, 'index.php?page=today'); assert(r.status() === 401, 'still signed in');
  });
  await shared.ctx.close();

  /* ================= historical data untouched ================= */
  await test('P01', 'Original imported records, locked task/work fields and all six legacy archives are byte-for-byte unchanged after every edit test', async () => {
    const after = protectedChecksums(); const diffs = Object.keys(before).filter(k => before[k] !== after[k]);
    note(Object.entries(after).map(([k, v]) => `${k}=${v.split(':')[0]}`).join(', '));
    assert(!diffs.length, 'changed: ' + diffs.map(k => `${k} ${before[k]} -> ${after[k]}`).join('; '));
  });

  await browser.close();
  /* ---------- report ---------- */
  const passed = results.filter(r => r.ok).length;
  const summary = { database: DB, run_at: new Date().toISOString(), today_nz: todayNZ, build: '0.9.0 / 20261030.3', passed, failed: results.length - passed, total: results.length, results };
  fs.writeFileSync(path.join(OUT, 'results.json'), JSON.stringify(summary, null, 2));
  const md = [`# Test run — ${DB}`, '', `Run ${summary.run_at} · build 0.9.0 / 20261030.3 · **${passed}/${results.length} passed**`, '', '| ID | Test | Result | Notes |', '|---|---|---|---|',
    ...results.map(r => `| ${r.id} | ${r.name} | ${r.ok ? 'PASS' : '**FAIL**'} (${r.ms} ms) | ${(r.ok ? r.notes.join('; ') : r.error).replace(/\|/g, '/').replace(/\n/g, '<br>')} |`)].join('\n');
  fs.writeFileSync(path.join(OUT, 'results.md'), md + '\n');
  console.log(`\n${passed}/${results.length} passed`);
  process.exit(passed === results.length ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
