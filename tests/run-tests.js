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
    'index.php?page=task&id=' + S.fabrikTask, 'index.php?page=work', 'index.php?page=enquiries', 'index.php?page=enquiries&status=all', 'index.php?page=invoicing', 'index.php?page=items', 'index.php?page=more', 'index.php?page=check',
    'add-work.php', 'add-work.php?client_id=' + S.client.id, 'add-enquiry.php', 'convert-enquiry.php?id=1', 'edit-task.php?id=2048', 'edit-enquiry.php?id=1',
    'edit-client.php?id=' + S.client.id, 'edit-client.php', 'edit-contact.php?client_id=' + S.client.id, 'edit-contact.php?id=' + S.contactId,
    'edit-task.php?id=' + S.fabrikTask, 'edit-task.php?client_id=' + S.client.id, 'edit-work.php?id=4096', 'edit-work.php?id=' + S.fabrikWork,
    'index.php?page=tasks&status=all&sort=queue&priority=immediate', 'index.php?page=work&staff=geoff&from=2020-01-01', 'index.php?page=clients&role=accounts',
    'index.php?page=templates', 'edit-template.php', 'index.php?page=workload', 'staff.php', 'schedule.php?id=' + S.openTask.id, 'index.php?page=services', 'index.php?page=domains', 'service-edit.php', 'claims.php', 'billing-settings.php', 'xero.php', ...(() => { const t = sql(`SELECT b.template_id,b.id FROM breakdown_items b WHERE b.template_id IS NOT NULL ORDER BY b.id LIMIT 1`)[0]; return t ? ['edit-template.php?id=' + t[0], `pricing.php?owner_type=template&owner_id=${t[0]}&line=${t[1]}`] : []; })()];
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
    await go(P, 'add-work.php'); await P.fill('#details', 'Test W04b'); await P.fill('#hours', '0'); await P.fill('#minutes', '0'); assert(await P.locator('#seconds').count() === 0, 'seconds box still shown');
    await Promise.all([P.waitForNavigation(), P.click('button.save')]); assert(/greater than zero/.test(await P.locator('.alert').innerText()), 'zero msg');
    await go(P, 'add-work.php'); await P.fill('#details', 'Test W04c'); await P.click('details summary:has-text("Billing")');
    await P.selectOption('#billability', 'billable'); await P.fill('#bill_hours', '1');
    await Promise.all([P.waitForNavigation(), P.click('button.save')]); assert(/cannot exceed/.test(await P.locator('.alert').innerText()), 'billable msg');
    assert(workMax() === before, 'a row was created');
  });
  await test('W05', 'Timer: start, survives reload, stop fills duration in whole minutes (at least 1), saved', async () => {
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
    const hrs = +(await P.inputValue('#hours')), mins = +(await P.inputValue('#minutes'));
    assert(hrs === 0 && mins === 1, `filled ${hrs}h ${mins}m`);
    const before = workMax();
    await P.fill('#details', 'Test W05 timer');
    await Promise.all([P.waitForNavigation(), P.click('button.save')]);
    const d = num(`SELECT duration_seconds FROM work_log WHERE id>${before}`);
    assert(d === 60, 'saved ' + d);
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
    await P.click('summary:has-text("price estimate")'); await P.fill('#line_description', 'Gallery page build'); await P.fill('#line_unit_price', '450.00');
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
  await test('D03', 'Converted app-made enquiries stay editable (no delete offered); unconverted ones save with an audit event', async () => {
    await go(P, 'edit-enquiry.php?id=' + newEnquiry);
    assert(await P.locator('input[name=action][value=save]').count() === 1, 'converted enquiry not editable');
    assert(await P.locator('input[name=action][value=delete]').count() === 0, 'delete offered although it has a task');
    assert(/Linked task/.test(await P.locator('.notice').first().innerText()), 'linked task not shown');
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
  // v0.15: also opens the "More options" / "More details" around it, as a person would click them first.
  const openDetails = async (page, text) => page.evaluate(t => { for (const d of document.querySelectorAll('details')) if (d.querySelector('summary').textContent.includes(t)) for (let x = d; x; x = x.parentElement) if (x.tagName === 'DETAILS') x.open = true; }, text);
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
    assert(P.url().includes('page=client&id=' + cid) && /Saved/.test(await P.locator('.success, [data-testid=undo-bar]').first().innerText()), 'no saved message: ' + P.url());
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
    await P.fill('#hours', '1'); await P.fill('#minutes', '0');
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
    await P.click('summary:has-text("category, type, domains")'); await P.click('summary:has-text("queue, job and order")');
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
    await P.check('input[name="assigned_to[]"][value="roselyn"]'); await openDetails(P, 'queue, job and order'); await P.fill('#queue', '7'); await save(P);
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

  /* ================= v0.11: enquiries, sign-off, undo, cancel, minutes ================= */
  const nzDate = offsetDays => new Intl.DateTimeFormat('en-CA', { timeZone: 'Pacific/Auckland' }).format(new Date(Date.now() + offsetDays * 86400000));
  const undoClick = async page => { await Promise.all([page.waitForNavigation(), page.click('[data-testid=undo-bar] button')]); };
  let n01 = 0, n07task = 0;
  await test('N01', 'Quick Enquiry: received date, category › type fills the title, new contact created and linked, audited', async () => {
    await go(P, 'add-enquiry.php?client_id=' + S.client.id);
    const cats = await P.$$eval('#task_category option', o => o.map(x => x.value).filter(Boolean));
    assert(cats.length, 'no categories');
    let cat = '', types = [];
    for (const c of cats) { await P.selectOption('#task_category', c); types = await P.$$eval('#task_type option', o => o.map(x => x.value).filter(Boolean)); if (types.length) { cat = c; break; } }
    assert(types.length, 'no category has types');
    await P.selectOption('#task_type', types[0]);
    assert(await P.inputValue('#title') === types[0], 'title not filled from type: ' + await P.inputValue('#title'));
    await P.fill('#title', 'Test N01 ' + types[0]);
    const rec = nzDate(-3); await P.fill('#received_on', rec);
    await openDetails(P, 'New contact'); await P.fill('#new_contact_name', 'Nora Testwell'); await P.fill('#new_contact_email', 'nora.n01@example.nz');
    const before = enqMax();
    await save(P);
    const r = sql(`SELECT id,DATE(received_at),task_category,task_type,contact_id,status FROM enquiries WHERE id>${before}`);
    assert(r.length === 1 && r[0][1] === rec && r[0][2] === cat && r[0][3] === types[0] && r[0][5] === 'enquiry', 'row ' + JSON.stringify(r));
    const k = sql(`SELECT client_id,email,source_system FROM contacts WHERE id=${r[0][4]}`)[0];
    assert(k && +k[0] === S.client.id && k[1] === 'nora.n01@example.nz' && k[2] === 'app', 'contact ' + k);
    assert(num(`SELECT COUNT(*) FROM activity_events WHERE enquiry_id=${r[0][0]} AND event_kind='enquiry_created'`) === 1, 'enquiry audit');
    assert(num(`SELECT COUNT(*) FROM activity_events WHERE event_kind='contact_created' AND JSON_EXTRACT(payload,'$.contact_id')=${r[0][4]}`) === 1, 'contact audit');
    n01 = +r[0][0];
  });
  await test('N02', 'Received date in the future, or a contact of another client, is refused; nothing saved', async () => {
    await go(P, 'add-enquiry.php?client_id=' + S.client.id); const before = enqMax();
    await P.fill('#title', 'Test N02 future'); await P.evaluate(() => document.querySelector('#received_on').removeAttribute('max'));
    await P.fill('#received_on', nzDate(5)); await save(P);
    assert(/future/.test(await P.locator('.alert').innerText()) && enqMax() === before, 'future date accepted');
    const foreign = +one(`SELECT id FROM contacts WHERE client_id IS NOT NULL AND client_id<>${S.client.id} ORDER BY id LIMIT 1`);
    await go(P, 'add-enquiry.php?client_id=' + S.client.id);
    await P.fill('#title', 'Test N02 foreign contact');
    await P.evaluate(id => { const s = document.querySelector('#contact_id'); const o = document.createElement('option'); o.value = String(id); s.appendChild(o); s.value = String(id); }, foreign);
    await save(P);
    assert(/different client/.test(await P.locator('.alert').innerText()) && enqMax() === before, 'foreign contact accepted');
  });
  await test('N03', 'Enquiries list: open by default (withdrawn hidden), status filter, search by contact, received-date filter match the database', async () => {
    sql(`INSERT INTO enquiries (client_id,title,priority,status,received_at,created_at) VALUES (${S.anyClient},'Test N03 withdrawn one','normal','withdrawn',NOW(),NOW()),(${S.anyClient},'Test N03 quoted one','normal','quoted',NOW(),NOW())`);
    const titles = async u => { await go(P, u); return P.$$eval('.compact-list a.record strong', s => s.map(x => x.textContent)); };
    let t = await titles('index.php?page=enquiries');
    assert(t.includes('Test N03 quoted one') && !t.includes('Test N03 withdrawn one'), 'default list ' + t.slice(0, 8).join(' | '));
    t = await titles('index.php?page=enquiries&status=withdrawn');
    assert(t.includes('Test N03 withdrawn one') && t.length === num(`SELECT COUNT(*) FROM enquiries WHERE status='withdrawn'`), 'withdrawn filter');
    t = await titles('index.php?page=enquiries&status=all&q=' + encodeURIComponent('nora testwell'));
    assert(t.length === 1 && t[0].startsWith('Test N01'), 'contact search ' + t.join(' | '));
    const d = nzDate(-3);
    t = await titles(`index.php?page=enquiries&status=all&from=${d}&to=${d}`);
    assert(t.length === num(`SELECT COUNT(*) FROM enquiries WHERE DATE(COALESCE(received_at,created_at))='${d}'`) && t.some(x => x.startsWith('Test N01')), 'date filter ' + t.length);
  });
  await test('N04', 'Withdraw an enquiry and cancel its open task; Undo restores the enquiry, Redo withdraws it again', async () => {
    sql(`INSERT INTO enquiries (client_id,title,priority,status,received_at,created_at) VALUES (${S.client.id},'Test N04 enquiry','normal','task_created',NOW(),NOW())`);
    const e = num('SELECT MAX(id) FROM enquiries');
    sql(`INSERT INTO tasks (client_id,enquiry_id,source_system,source_id,title,status) VALUES (${S.client.id},${e},'enquiry','${e}','Test N04 task','open')`);
    const t = num('SELECT MAX(id) FROM tasks');
    await go(P, 'edit-enquiry.php?id=' + e);
    await P.check('input[name=close_kind][value=withdrawn]'); await P.fill('#closed_reason', 'Client changed their mind'); await P.check('input[name=cancel_tasks]');
    await Promise.all([P.waitForNavigation(), P.click('[data-close-save]')]);
    let r = sql(`SELECT status,closed_reason FROM enquiries WHERE id=${e}`)[0];
    assert(r[0] === 'withdrawn' && r[1] === 'Client changed their mind' && one(`SELECT status FROM tasks WHERE id=${t}`) === 'cancelled', 'state ' + r);
    assert(audit(`task_id=${t}`, 'task_updated') && audit(`enquiry_id=${e}`, 'enquiry_updated'), 'audit');
    assert(await P.locator('[data-testid=undo-bar]').count() === 1, 'no undo bar');
    await undoClick(P);
    r = sql(`SELECT status,closed_reason FROM enquiries WHERE id=${e}`)[0];
    assert(r[0] === 'task_created' && r[1] === null, 'undo state ' + r);
    assert(/Redo/.test(await P.locator('[data-testid=undo-bar] button').innerText()), 'no redo offered');
    await undoClick(P);
    assert(one(`SELECT status FROM enquiries WHERE id=${e}`) === 'withdrawn', 'redo');
    assert(num(`SELECT COUNT(*) FROM activity_events WHERE enquiry_id=${e} AND event_kind='enquiry_updated' AND summary LIKE 'Undo:%'`) === 1, 'undo audited');
  });
  await test('N05', 'Mark duplicate: unknown number refused; valid number saved and shown in the list', async () => {
    sql(`INSERT INTO enquiries (client_id,title,priority,status,received_at,created_at) VALUES (${S.client.id},'Test N05 duplicate','normal','enquiry',NOW(),NOW())`);
    const e = num('SELECT MAX(id) FROM enquiries');
    await go(P, 'edit-enquiry.php?id=' + e);
    await P.check('input[name=close_kind][value=duplicate]'); await P.fill('#duplicate_of', '9999999');
    await Promise.all([P.waitForNavigation(), P.click('[data-close-save]')]);
    assert(/does not exist/.test(await P.locator('.alert').innerText()) && one(`SELECT status FROM enquiries WHERE id=${e}`) === 'enquiry', 'unknown accepted');
    await P.check('input[name=close_kind][value=duplicate]'); await P.fill('#duplicate_of', String(n01));
    await Promise.all([P.waitForNavigation(), P.click('[data-close-save]')]);
    const r = sql(`SELECT status,duplicate_of FROM enquiries WHERE id=${e}`)[0];
    assert(r[0] === 'duplicate' && +r[1] === n01, 'row ' + r);
    await go(P, 'index.php?page=enquiries&status=duplicate');
    assert((await P.locator('.compact-list').innerText()).includes('Duplicate of #' + n01), 'list text');
  });
  await test('N06', 'Delete: an enquiry with no task (and its price lines) is deleted with a snapshot audit; one with a task is refused even to a forged POST', async () => {
    sql(`INSERT INTO enquiries (client_id,title,priority,status,received_at,created_at) VALUES (${S.client.id},'Test N06 mistake','normal','enquiry',NOW(),NOW())`);
    const e = num('SELECT MAX(id) FROM enquiries');
    sql(`INSERT INTO breakdown_items (enquiry_id,description,quantity,unit_price_ex_gst,pricing_mode,status) VALUES (${e},'Test N06 line',1,10,'set','proposed')`);
    await go(P, 'edit-enquiry.php?id=' + e);
    await P.check('input[name=confirm_delete]');
    await Promise.all([P.waitForNavigation(), P.click('button.danger')]);
    assert(P.url().includes('page=enquiries') && /deleted/.test(await P.locator('.success').innerText()), 'landed ' + P.url());
    assert(!one(`SELECT id FROM enquiries WHERE id=${e}`) && !num(`SELECT COUNT(*) FROM breakdown_items WHERE enquiry_id=${e}`), 'not deleted');
    assert((audit(`event_kind='enquiry_deleted' AND JSON_EXTRACT(payload,'$.enquiry.id')=${e}`, 'enquiry_deleted') || '').includes('Test N06 mistake'), 'snapshot audit');
    const withTask = +one(`SELECT e.id FROM enquiries e JOIN tasks t ON t.enquiry_id=e.id WHERE e.legacy_source_id IS NULL ORDER BY e.id LIMIT 1`);
    await go(P, 'edit-enquiry.php?id=' + withTask);
    assert(await P.locator('input[name=action][value=delete]').count() === 0, 'delete offered');
    const v = await ver(P);
    const res = await P.request.post(BASE + 'edit-enquiry.php', { form: { id: String(withTask), csrf: v.csrf, ver: v.ver, action: 'delete', confirm_delete: 'yes' } });
    assert(res.status() === 200 && /cannot be deleted/.test(await res.text()) && one(`SELECT id FROM enquiries WHERE id=${withTask}`), 'forged delete');
  });
  await test('N07', 'Create task copies contact, category, type and received date from the enquiry', async () => {
    await go(P, 'convert-enquiry.php?id=' + n01);
    assert(/Received/.test(await P.locator('dl.facts').innerText()) && /Nora Testwell/.test(await P.locator('dl.facts').innerText()), 'facts');
    await Promise.all([P.waitForNavigation(), P.click('form[data-once] button')]);
    const e = sql(`SELECT contact_id,task_category,task_type,received_at FROM enquiries WHERE id=${n01}`)[0];
    const t = sql(`SELECT id,contact_id,task_category,task_type,received_at FROM tasks WHERE enquiry_id=${n01}`)[0];
    assert(t && t[1] === e[0] && t[2] === e[1] && t[3] === e[2] && t[4] === e[3], 'task ' + t + ' vs ' + e);
    n07task = +t[0];
  });
  await test('N08', 'Sign off a task (who, note) and reopen it with a reason; a reopen without a reason is refused', async () => {
    await go(P, 'index.php?page=task&id=' + n07task);
    await openDetails(P, 'Sign off'); await P.fill('#signed_off_by', 'Geoff'); await P.fill('#sign_off_note', 'Approved by client');
    await Promise.all([P.waitForNavigation(), P.click('[data-testid=signoff] button[type=submit]')]);
    let r = sql(`SELECT status,completed_at,signed_off_at,signed_off_by,sign_off_note FROM tasks WHERE id=${n07task}`)[0];
    assert(r[0] === 'completed' && r[1] && r[2] && r[3] === 'Geoff' && r[4] === 'Approved by client', 'signed ' + r);
    assert(/Signed off/.test(await P.locator('[data-testid=signoff]').innerText()) && /Geoff/.test(await P.locator('[data-testid=signoff]').innerText()), 'panel');
    const csrf = await P.inputValue('[data-testid=signoff] input[name=csrf]');
    await P.request.post(BASE + 'task-signoff.php', { form: { id: String(n07task), csrf, action: 'reopen', reopen_reason: '' }, maxRedirects: 0 });
    assert(one(`SELECT status FROM tasks WHERE id=${n07task}`) === 'completed', 'reopened without reason');
    await go(P, 'index.php?page=task&id=' + n07task);
    assert(/reason/i.test(await P.locator('.alert').innerText()), 'no message for missing reason');
    await openDetails(P, 'Reopen'); await P.fill('#reopen_reason', 'Client found a typo');
    await Promise.all([P.waitForNavigation(), P.click('[data-testid=signoff] button[type=submit]')]);
    r = sql(`SELECT status,completed_at,signed_off_at,signed_off_by FROM tasks WHERE id=${n07task}`)[0];
    assert(r[0] === 'in progress' && r[1] === null && r[2] === null && r[3] === null, 'reopened ' + r);
    assert(num(`SELECT COUNT(*) FROM activity_events WHERE task_id=${n07task} AND summary LIKE 'Task reopened: Client found a typo%'`) === 1, 'audit');
  });
  await test('N09', 'Imported task can be signed off (workload field); Undo puts it back exactly', async () => {
    const t = +one(`SELECT id FROM tasks WHERE source_system='fabrik' AND NOT (${closed('status')}) AND completed_at IS NULL ORDER BY id LIMIT 1`);
    const before = sql(`SELECT status,COALESCE(completed_at,'') FROM tasks WHERE id=${t}`)[0];
    await go(P, 'index.php?page=task&id=' + t);
    await openDetails(P, 'Sign off'); await P.fill('#signed_off_by', 'Roselyn');
    await Promise.all([P.waitForNavigation(), P.click('[data-testid=signoff] button[type=submit]')]);
    assert(one(`SELECT status FROM tasks WHERE id=${t}`) === 'completed', 'not signed off');
    await undoClick(P);
    const after = sql(`SELECT status,COALESCE(completed_at,''),signed_off_at FROM tasks WHERE id=${t}`)[0];
    assert(after[0] === before[0] && after[1] === before[1] && after[2] === null, 'undo ' + after + ' vs ' + before);
  });
  await test('N10', 'Undo a client edit restores the old value; if it changed again since, Undo refuses and changes nothing', async () => {
    const old = one(`SELECT COALESCE(phone,'') FROM clients WHERE id=${x03}`);
    await go(P, 'edit-client.php?id=' + x03); await P.fill('#phone', '03 555 0909'); await save(P);
    assert(await P.locator('[data-testid=undo-bar]').count() === 1, 'no undo bar');
    await undoClick(P);
    assert(one(`SELECT COALESCE(phone,'') FROM clients WHERE id=${x03}`) === old, 'not restored');
    await go(P, 'edit-client.php?id=' + x03); await P.fill('#phone', '03 555 1111'); await save(P);
    sql(`UPDATE clients SET phone='03 555 2222' WHERE id=${x03}`);
    await undoClick(P);
    assert(/changed again/.test(await P.locator('.alert').innerText()) && one(`SELECT phone FROM clients WHERE id=${x03}`) === '03 555 2222', 'undo overwrote a newer change');
    const r = await P.request.post(BASE + 'undo.php', { form: { csrf: 'x', event: '1' }, maxRedirects: 0 });
    assert(r.status() === 403, 'bad token status ' + r.status());
  });
  await test('N11', 'Work Log edit: hours + minutes only; stored seconds kept unless the time is changed', async () => {
    sql(`INSERT INTO work_log (source_system,source_id,details,work_date,duration_seconds,billable_seconds,billability) VALUES ('m5','n11-${Date.now()}','Test N11 entry','${todayNZ}',3725,0,'review')`);
    const w = num('SELECT MAX(id) FROM work_log');
    await go(P, 'edit-work.php?id=' + w);
    assert(await P.locator('#seconds').count() === 0 && await P.inputValue('#hours') === '1' && await P.inputValue('#minutes') === '2', 'fields');
    await P.fill('#details', 'Test N11 entry edited'); await save(P);
    assert(num(`SELECT duration_seconds FROM work_log WHERE id=${w}`) === 3725, 'seconds lost on unrelated edit');
    await go(P, 'edit-work.php?id=' + w); await P.fill('#minutes', '3'); await save(P);
    assert(num(`SELECT duration_seconds FROM work_log WHERE id=${w}`) === 3780, 'new time not whole minutes');
  });
  await test('N12', 'Every form has a Cancel link back to where it came from', async () => {
    const wid = num(`SELECT MAX(id) FROM work_log WHERE source_system='m5'`);
    const cases = [['add-work.php?task_id=' + S.openTask.id, 'page=task&id=' + S.openTask.id], ['add-enquiry.php?client_id=' + S.client.id, 'page=client&id=' + S.client.id],
      ['edit-client.php?id=' + S.client.id, 'page=client&id=' + S.client.id], ['edit-contact.php?id=' + S.contactId, 'page=client&id='], ['edit-task.php?id=' + S.openTask.id, 'page=task&id=' + S.openTask.id],
      ['edit-work.php?id=' + wid, 'page=work'], ['edit-enquiry.php?id=' + n01, 'convert-enquiry.php?id=' + n01], ['edit-client.php', 'page=clients']];
    const bad = [];
    for (const [u, want] of cases) { await go(P, u); const href = await P.getAttribute('a.cancel', 'href').catch(() => null); if (!href || !href.includes(want)) bad.push(`${u} → ${href}`); }
    assert(!bad.length, bad.join('; '));
  });

  await test('N13', 'Today: a withdrawn enquiry with a follow-up due is not listed; an accepted enquiry without a task is', async () => {
    sql(`INSERT INTO enquiries (client_id,title,priority,status,next_action_due,received_at,created_at) VALUES (${S.anyClient},'Test N13 withdrawn follow-up','normal','withdrawn','${todayNZ}',NOW(),NOW()),(${S.anyClient},'Test N13 accepted','normal','accepted',NULL,NOW(),NOW())`);
    await go(P, 'index.php?page=today');
    const txt = (await P.$$eval('[data-testid=attention-queue] li', ls => ls.map(l => l.textContent))).join(' | ');
    assert(!txt.includes('Test N13 withdrawn follow-up'), 'withdrawn enquiry listed');
    assert(txt.includes('Test N13 accepted') || /more in/.test(await P.locator('main').innerText()), 'accepted enquiry missing: ' + txt.slice(0, 300));
  });
  /* ================= v0.12: pricing module, templates, planned tasks, stages, milestones ================= */
  const csrfOf = async page => page.locator('input[name=csrf]').first().inputValue();
  const postAndGo = async (page, url, form) => { const r = await page.request.post(BASE + url, { form, maxRedirects: 0 }); const loc = r.headers()['location']; if (loc) await page.goto(new URL(loc, BASE + url).href); return r; };
  const clickIn = async (page, sel) => { await Promise.all([page.waitForNavigation(), page.click(sel)]); };
  const pf = one(`SELECT id FROM clients WHERE LOWER(TRIM(name)) IN ('pro forma','pro-forma','proforma') ORDER BY id LIMIT 1`);
  let q03enq = 0, q07tasks = [], tplSet = 0;
  await test('Q01', 'Migration 004: every open pro-forma task is a template (title, instructions, set fee as a price line); templates page lists them', async () => {
    assert(pf, 'no Pro forma client in this database');
    const open = sql(`SELECT id,title,pricing,agreed_fee_ex_gst FROM tasks WHERE client_id=${pf} AND NOT (${closed('status')}) AND completed_at IS NULL`);
    const tpl = sql(`SELECT id,from_task_id,title FROM task_templates`);
    assert(open.length > 0 && open.every(t => tpl.some(x => +x[1] === +t[0] && x[2] === t[1])), `templates ${tpl.length} vs open pro-forma ${open.length}`);
    const closedPf = num(`SELECT COUNT(*) FROM tasks t JOIN task_templates x ON x.from_task_id=t.id WHERE t.client_id=${pf} AND (${closed('t.status')} OR t.completed_at IS NOT NULL)`);
    assert(closedPf === 0, 'a closed pro-forma task was copied');
    for (const t of open.filter(t => t[2] === 'set' && +t[3] > 0)) assert(num(`SELECT COUNT(*) FROM breakdown_items b JOIN task_templates x ON x.id=b.template_id WHERE x.from_task_id=${t[0]} AND b.unit_price_ex_gst=${t[3]}`) === 1, 'set fee missing for task ' + t[0]);
    assert(!sql(`SELECT COALESCE(instructions,'') FROM task_templates`).some(r => /rn[A-Z]/.test(r[0] || '')), 'broken "rn" line breaks left in template instructions');
    await go(P, 'index.php?page=templates');
    const titles = await P.$$eval('main a.record strong', a => a.map(x => x.textContent));
    assert(open.every(t => titles.includes(t[1])), 'templates page missing some');
    assert(await P.locator('[data-testid=retire-proforma]').count() === 1, 'retire panel missing');
    tplSet = +one(`SELECT x.id FROM task_templates x JOIN breakdown_items b ON b.template_id=x.id WHERE b.pricing_mode='set' ORDER BY b.unit_price_ex_gst DESC LIMIT 1`);
    note(`${open.length} templates; template with set fee #${tplSet}`);
  });
  await test('Q02', 'Template edit is audited and undoable; an archived template is not offered on Quick Enquiry and can be restored', async () => {
    const tid = +one(`SELECT id FROM task_templates WHERE id<>${tplSet} ORDER BY id LIMIT 1`); const oldTitle = one(`SELECT title FROM task_templates WHERE id=${tid}`);
    await go(P, 'edit-template.php?id=' + tid); await P.fill('#title', 'Test Q02 renamed'); await save(P);
    assert(one(`SELECT title FROM task_templates WHERE id=${tid}`) === 'Test Q02 renamed', 'not saved');
    assert(num(`SELECT COUNT(*) FROM activity_events WHERE event_kind='template_updated' AND JSON_EXTRACT(payload,'$.row_id')=${tid}`) >= 1, 'not audited');
    await undoClick(P); assert(one(`SELECT title FROM task_templates WHERE id=${tid}`) === oldTitle, 'undo did not restore title');
    await go(P, 'edit-template.php?id=' + tid); await clickIn(P, 'form:has(input[name=action][value=archive]) button');
    await go(P, 'add-enquiry.php'); const opts = await P.$$eval('#template_id option', o => o.map(x => x.value));
    assert(!opts.includes(String(tid)), 'archived template offered');
    await go(P, 'edit-template.php?id=' + tid); assert(/archived/.test(await P.locator('.notice').first().innerText()), 'no archived notice');
    await clickIn(P, 'form:has(input[name=action][value=restore]) button');
    assert(+one(`SELECT active FROM task_templates WHERE id=${tid}`) === 1, 'not restored');
  });
  await test('Q03', 'Quick Enquiry from a template: title filled, template added as a planned task with its price lines', async () => {
    await go(P, 'add-enquiry.php?client_id=' + S.client.id);
    await P.selectOption('#template_id', String(tplSet));
    const tTitle = one(`SELECT title FROM task_templates WHERE id=${tplSet}`);
    assert(await P.inputValue('#title') === tTitle, 'title not filled: ' + await P.inputValue('#title'));
    await P.fill('#title', 'Test Q03 website job'); const before = enqMax(); await save(P);
    q03enq = enqMax(); assert(q03enq > before, 'not saved');
    const plan = sql(`SELECT id,title,template_id FROM enquiry_task_plans WHERE enquiry_id=${q03enq}`);
    assert(plan.length === 1 && plan[0][1] === tTitle && +plan[0][2] === tplSet, 'plan ' + JSON.stringify(plan));
    const want = one(`SELECT ROUND(SUM(quantity*unit_price_ex_gst),2) FROM breakdown_items WHERE template_id=${tplSet}`);
    assert(one(`SELECT ROUND(SUM(quantity*unit_price_ex_gst),2) FROM breakdown_items WHERE enquiry_id=${q03enq} AND plan_id=${plan[0][0]}`) === want, 'plan lines total differ from template');
  });
  await test('Q04', 'Pricing panel on an enquiry: add lines (whole job / for a planned task), totals ex GST + 15% GST match the database; edit, reorder, remove', async () => {
    await go(P, 'convert-enquiry.php?id=' + q03enq + '#pricing');
    await openDetails(P, 'Add price line'); await P.fill('#nenquiry-desc', 'Test Q04 hosting setup'); await P.fill('#nenquiry-qty', '2'); await P.fill('#nenquiry-price', '45.50'); await P.selectOption('#nenquiry-mode', 'web_service'); await P.fill('#nenquiry-acct', 'Web Hosting');
    await clickIn(P, 'form:has(#nenquiry-desc) button[type=submit]');
    await openDetails(P, 'Add price line'); await P.fill('#nenquiry-desc', 'Test Q04 extra page'); await P.fill('#nenquiry-price', '120');
    const planOpt = await P.$$eval('#nenquiry-for option', o => o.map(x => x.value)); assert(planOpt.some(v => v.startsWith('plan:')), 'no planned task in For');
    await P.selectOption('#nenquiry-for', planOpt.find(v => v.startsWith('plan:'))); await clickIn(P, 'form:has(#nenquiry-desc) button[type=submit]');
    const db = +one(`SELECT ROUND(SUM(quantity*unit_price_ex_gst),2) FROM breakdown_items WHERE enquiry_id=${q03enq}`);
    const tot = await P.locator('[data-testid=price-totals]').innerText();
    const money = n => '$' + n.toLocaleString('en-NZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    assert(tot.includes(money(db)) && tot.includes(money(Math.round(db * 15) / 100)) && tot.includes(money(Math.round(db * 115) / 100)), `totals "${tot}" vs db ${db}`);
    const lid = +one(`SELECT id FROM breakdown_items WHERE enquiry_id=${q03enq} AND description='Test Q04 hosting setup'`);
    assert(one(`SELECT CONCAT(quantity,'|',unit_price_ex_gst,'|',pricing_mode,'|',account_name) FROM breakdown_items WHERE id=${lid}`) === '2.000|45.50|web_service|Web Hosting', 'line fields');
    await go(P, `pricing.php?owner_type=enquiry&owner_id=${q03enq}&line=${lid}`); await P.fill('#e-price', '50'); await save(P);
    assert(+one(`SELECT unit_price_ex_gst FROM breakdown_items WHERE id=${lid}`) === 50, 'edit not saved');
    await undoClick(P); assert(+one(`SELECT unit_price_ex_gst FROM breakdown_items WHERE id=${lid}`) === 45.5, 'undo of line edit');
    const order = () => sql(`SELECT id FROM breakdown_items WHERE enquiry_id=${q03enq} ORDER BY line_order,id`).map(r => +r[0]);
    const o1 = order(); await go(P, 'convert-enquiry.php?id=' + q03enq); await clickIn(P, `form:has(input[name=line_id][value="${o1[o1.length - 1]}"]) button`);
    const o2 = order(); assert(o2[o2.length - 2] === o1[o1.length - 1], 'move up did not reorder ' + o1 + ' -> ' + o2);
    await go(P, `pricing.php?owner_type=enquiry&owner_id=${q03enq}&line=${lid}`); await P.check('input[name=confirm]'); await clickIn(P, 'button.danger');
    assert(num(`SELECT COUNT(*) FROM breakdown_items WHERE id=${lid}`) === 0 && num(`SELECT COUNT(*) FROM activity_events WHERE event_kind='price_line_deleted' AND enquiry_id=${q03enq}`) === 1, 'remove');
  });
  await test('Q05', 'Price line validation refuses bad values; an imported enquiry\'s pricing is read-only', async () => {
    await go(P, 'convert-enquiry.php?id=' + q03enq); const csrf = await csrfOf(P); const n0 = num('SELECT COUNT(*) FROM breakdown_items');
    for (const bad of [{ quantity: '0', unit_price: '10' }, { quantity: '1', unit_price: 'abc' }, { quantity: '-2', unit_price: '10' }, { quantity: '1', unit_price: '' }]) {
      await postAndGo(P, 'pricing.php', { owner_type: 'enquiry', owner_id: String(q03enq), csrf, action: 'line_add', description: 'Test Q05 bad', pricing_mode: 'set', ...bad });
      assert(await P.locator('#pricing .alert').count() === 1, 'no error for ' + JSON.stringify(bad));
    }
    assert(num('SELECT COUNT(*) FROM breakdown_items') === n0, 'a bad line was saved');
    sql(`INSERT INTO enquiries (client_id,title,priority,status,legacy_source_id,created_at) VALUES (${S.anyClient},'Test Q05 imported','normal','enquiry','fabrik-q05',NOW())`);
    const imp = enqMax(); await go(P, 'convert-enquiry.php?id=' + imp);
    assert(await P.locator('#pricing details.inline-add').count() === 0, 'add forms shown on imported enquiry');
    await postAndGo(P, 'pricing.php', { owner_type: 'enquiry', owner_id: String(imp), csrf, action: 'line_add', description: 'Test Q05 imported', quantity: '1', unit_price: '5', pricing_mode: 'set' });
    assert(num(`SELECT COUNT(*) FROM breakdown_items WHERE enquiry_id=${imp}`) === 0, 'line added to imported enquiry');
  });
  await test('Q06', 'Milestones: % of the price, ready when the enquiry is accepted (and not when set back); listed on Pending Invoicing', async () => {
    await go(P, 'convert-enquiry.php?id=' + q03enq);
    await openDetails(P, 'Add milestone'); await P.fill('#nmenquiry-label', 'Test Q06 deposit'); await P.fill('#nmenquiry-pct', '30'); await P.selectOption('#nmenquiry-trig', 'accepted');
    await clickIn(P, 'form:has(#nmenquiry-label) button[type=submit]');
    const ms = sql(`SELECT id,percent,amount_ex_gst,trigger_kind FROM billing_milestones WHERE enquiry_id=${q03enq} AND label='Test Q06 deposit'`)[0];
    assert(ms && +ms[1] === 30 && ms[2] === null && ms[3] === 'accepted', 'milestone ' + ms);
    const total = +one(`SELECT ROUND(SUM(quantity*unit_price_ex_gst),2) FROM breakdown_items WHERE enquiry_id=${q03enq}`);
    const want = '$' + (Math.round(total * 30) / 100).toLocaleString('en-NZ', { minimumFractionDigits: 2 });
    const row = () => P.locator('[data-testid=milestone]:has-text("Test Q06 deposit")').innerText();
    assert((await row()).includes(want) && /Planned/.test(await row()), `row "${await row()}" want ${want}`);
    await go(P, 'edit-enquiry.php?id=' + q03enq); await P.selectOption('#status', 'accepted'); await save(P);
    await go(P, 'convert-enquiry.php?id=' + q03enq); assert(/Ready to invoice/.test(await row()), 'not ready after accepted: ' + await row());
    await go(P, 'index.php?page=invoicing'); assert((await P.locator('main').innerText()).includes('Test Q06 deposit'), 'not on Pending Invoicing');
    await go(P, 'edit-enquiry.php?id=' + q03enq); await P.selectOption('#status', 'waiting'); await save(P);
    await go(P, 'convert-enquiry.php?id=' + q03enq); assert(/Planned/.test(await row()), 'still ready after set back');
    await go(P, 'edit-enquiry.php?id=' + q03enq); await P.selectOption('#status', 'accepted'); await save(P);
  });
  await test('Q07', 'Create tasks from ticked planned tasks: one task each, with its price lines and milestones; re-posting never duplicates; the rest can follow later', async () => {
    await go(P, 'convert-enquiry.php?id=' + q03enq); const csrfP = await csrfOf(P);
    await postAndGo(P, 'plan.php', { csrf: csrfP, action: 'add_blank', enquiry_id: String(q03enq), title: 'Test Q07 second task' });
    const plans = sql(`SELECT id,title FROM enquiry_task_plans WHERE enquiry_id=${q03enq} ORDER BY id`); assert(plans.length === 2, 'plans ' + plans.length);
    sql(`INSERT INTO billing_milestones (enquiry_id,plan_id,label,amount_ex_gst,trigger_kind,status,created_at) VALUES (${q03enq},${plans[0][0]},'Test Q07 stage paid',250.00,'signoff','planned',NOW())`);
    await go(P, 'convert-enquiry.php?id=' + q03enq);
    assert(await P.locator('[data-testid=convert-plans] input[name="plans[]"]').count() === 2, 'two plans not offered');
    await P.uncheck(`[data-testid=convert-plans] input[value="${plans[1][0]}"]`);
    const csrf = await P.locator('[data-testid=convert-plans] input[name=csrf]').inputValue();
    await clickIn(P, '[data-testid=convert-plans] button.save');
    const t1 = +one(`SELECT task_id FROM enquiry_task_plans WHERE id=${plans[0][0]}`); assert(t1 && P.url().includes('id=' + t1), 'landed ' + P.url());
    const tk = sql(`SELECT title,source_system,source_id,client_id,enquiry_id,status FROM tasks WHERE id=${t1}`)[0];
    assert(tk[0] === plans[0][1] && tk[1] === 'enquiry' && tk[2] === `${q03enq}-p${plans[0][0]}` && +tk[3] === S.client.id && +tk[4] === q03enq && tk[5] === 'open', 'task ' + tk);
    assert(num(`SELECT COUNT(*) FROM breakdown_items WHERE plan_id=${plans[0][0]} AND (task_id IS NULL OR task_id<>${t1})`) === 0, 'plan lines not assigned');
    assert(one(`SELECT task_id FROM billing_milestones WHERE label='Test Q07 stage paid'`) === String(t1), 'milestone not assigned');
    assert(num(`SELECT COUNT(*) FROM breakdown_items WHERE enquiry_id=${q03enq} AND plan_id IS NULL AND task_id IS NOT NULL`) === 0, 'whole-job lines moved to a plan task');
    assert(one(`SELECT status FROM enquiries WHERE id=${q03enq}`) === 'task_created', 'enquiry status');
    // re-post the same plan (stale page) → nothing new
    await P.request.post(BASE + 'convert-enquiry.php', { form: { id: String(q03enq), csrf, 'plans[]': String(plans[0][0]) }, maxRedirects: 0 });
    const csrf2 = await (async () => { await go(P, 'convert-enquiry.php?id=' + q03enq); return P.locator('[data-testid=convert-plans] input[name=csrf]').inputValue(); })();
    await P.request.post(BASE + 'convert-enquiry.php', { form: { id: String(q03enq), csrf: csrf2, 'plans[]': String(plans[0][0]) }, maxRedirects: 0 });
    assert(tasksFor(q03enq) === 1, 'duplicate task: ' + tasksFor(q03enq));
    await go(P, 'convert-enquiry.php?id=' + q03enq); await clickIn(P, '[data-testid=convert-plans] button.save');
    assert(tasksFor(q03enq) === 2, 'second plan not created later');
    q07tasks = sql(`SELECT id FROM tasks WHERE enquiry_id=${q03enq} ORDER BY id`).map(r => +r[0]);
  });
  await test('Q08', 'Concurrency: 6 sessions create the same planned task at once → exactly one task', async () => {
    sql(`INSERT INTO enquiries (client_id,title,priority,status,created_at) VALUES (${S.otherClient},'Test Q08 race','normal','accepted',NOW())`); const e = enqMax();
    sql(`INSERT INTO enquiry_task_plans (enquiry_id,title,created_at) VALUES (${e},'Test Q08 plan',NOW())`); const pid = +one('SELECT MAX(id) FROM enquiry_task_plans');
    const sessions = [];
    for (let i = 0; i < 6; i++) { const s = await newSession(375); await go(s.page, 'convert-enquiry.php?id=' + e); sessions.push({ ...s, csrf: await s.page.locator('[data-testid=convert-plans] input[name=csrf]').inputValue() }); }
    const res = await Promise.all(sessions.map(s => s.page.request.post(BASE + 'convert-enquiry.php', { form: { id: String(e), csrf: s.csrf, 'plans[]': String(pid) }, maxRedirects: 0 })));
    for (const s of sessions) await s.ctx.close();
    note('responses: ' + res.map(r => r.status()).join(','));
    assert(tasksFor(e) === 1, 'tasks created: ' + tasksFor(e));
  });
  await test('Q09', 'Task pricing panel shows the lines from its planned task; a task line and the billing choice save, audit and undo', async () => {
    const t1 = q07tasks[0]; await go(P, 'index.php?page=task&id=' + t1);
    const db = +one(`SELECT ROUND(SUM(quantity*unit_price_ex_gst),2) FROM breakdown_items WHERE task_id=${t1}`);
    assert(db > 0 && (await P.locator('[data-testid=price-totals]').innerText()).includes('$' + db.toLocaleString('en-NZ', { minimumFractionDigits: 2 })), 'task total');
    await openDetails(P, 'Add price line'); await P.fill('#ntask-desc', 'Test Q09 task line'); await P.fill('#ntask-price', '80'); await clickIn(P, 'form:has(#ntask-desc) button[type=submit]');
    assert(num(`SELECT COUNT(*) FROM breakdown_items WHERE task_id=${t1} AND enquiry_id IS NULL AND description='Test Q09 task line'`) === 1, 'task line');
    await P.selectOption('#bm-task', 'monthly'); await clickIn(P, 'form.billing-mode button');
    assert(one(`SELECT billing_mode FROM tasks WHERE id=${t1}`) === 'monthly', 'billing mode');
    await undoClick(P); assert(one(`SELECT billing_mode FROM tasks WHERE id=${t1}`) === null, 'undo billing mode');
  });
  let q10parent = 0;
  await test('Q10', 'Stages: break a task out into stages (copies client/enquiry); parent cannot be signed off or completed while a stage is open; a stage cannot have stages', async () => {
    q10parent = q07tasks[0]; await go(P, 'index.php?page=task&id=' + q10parent);
    await openDetails(P, 'Break out into stages'); await P.fill('#stage0', 'Test Q10 design'); await P.fill('#stage1', 'Test Q10 build');
    await clickIn(P, '[data-testid=stages] button[type=submit]');
    const st = sql(`SELECT id,stage_no,client_id,enquiry_id,status,source_system FROM tasks WHERE parent_task_id=${q10parent} ORDER BY stage_no`);
    assert(st.length === 2 && +st[0][1] === 1 && +st[1][1] === 2 && st.every(s => +s[2] === S.client.id && +s[3] === q03enq && s[4] === 'open' && s[5] === 'app'), 'stages ' + JSON.stringify(st));
    assert((await P.locator('[data-testid=stages]').innerText()).includes('0 of 2 done'), 'stage summary');
    await openDetails(P, 'Extend'); await P.fill('#stage0', 'Test Q10 launch'); await clickIn(P, '[data-testid=stages] button[type=submit]');
    assert(num(`SELECT MAX(stage_no) FROM tasks WHERE parent_task_id=${q10parent}`) === 3, 'extend');
    const csrf = await csrfOf(P);
    await postAndGo(P, 'task-signoff.php', { id: String(q10parent), csrf, action: 'signoff', signed_off_by: 'Geoff' });
    assert(/stages first/.test(await P.locator('.alert').first().innerText()) && one(`SELECT signed_off_at FROM tasks WHERE id=${q10parent}`) === null, 'parent signed off with open stages');
    await go(P, 'edit-task.php?id=' + q10parent); await P.selectOption('#status', 'completed'); await save(P);
    assert(/stages first/.test(await P.locator('.alert').innerText()) && one(`SELECT status FROM tasks WHERE id=${q10parent}`) === 'open', 'parent completed with open stages');
    await postAndGo(P, 'task-stages.php', { id: String(st[0][0]), csrf, 'stages[]': 'Test Q10 nested' });
    assert(num(`SELECT COUNT(*) FROM tasks WHERE parent_task_id=${st[0][0]}`) === 0, 'stage of a stage created');
    await go(P, 'index.php?page=task&id=' + st[0][0]); assert((await P.locator('[data-testid=stage-of]').innerText()).includes('#' + q10parent), 'stage-of link');
    assert(await P.locator('[data-testid=stages]').count() === 0, 'stage offers stages');
  });
  await test('Q11', 'Sign off every stage, then the parent; a "signed off" milestone becomes ready, and goes back to planned when the task is reopened', async () => {
    const csrf = await csrfOf(P);
    for (const s of sql(`SELECT id FROM tasks WHERE parent_task_id=${q10parent}`)) await postAndGo(P, 'task-signoff.php', { id: s[0], csrf, action: 'signoff', signed_off_by: 'Roselyn' });
    await go(P, 'index.php?page=task&id=' + q10parent);
    const ms = () => P.locator('[data-testid=milestone]:has-text("Test Q07 stage paid")').innerText();
    assert(/Planned/.test(await ms()), 'milestone ready before sign-off');
    await postAndGo(P, 'task-signoff.php', { id: String(q10parent), csrf, action: 'signoff', signed_off_by: 'Geoff' });
    assert(one(`SELECT status FROM tasks WHERE id=${q10parent}`) === 'completed', 'parent not signed off');
    await go(P, 'index.php?page=task&id=' + q10parent); assert(/Ready to invoice/.test(await ms()), 'milestone not ready: ' + await ms());
    await postAndGo(P, 'task-signoff.php', { id: String(q10parent), csrf, action: 'reopen', reopen_reason: 'Test Q11' });
    await go(P, 'index.php?page=task&id=' + q10parent); assert(/Planned/.test(await ms()), 'still ready after reopen');
  });
  await test('Q12', 'Manual milestone: mark ready (who), back to planned; a ready milestone can only be voided, with a reason; void can be restored', async () => {
    const t2 = q07tasks[1]; await go(P, 'index.php?page=task&id=' + t2);
    await openDetails(P, 'Add milestone'); await P.fill('#nmtask-label', 'Test Q12 progress claim'); await P.fill('#nmtask-amt', '300'); await P.selectOption('#nmtask-trig', 'manual');
    await clickIn(P, 'form:has(#nmtask-label) button[type=submit]');
    const mid = +one(`SELECT id FROM billing_milestones WHERE label='Test Q12 progress claim' AND task_id=${t2}`); assert(mid, 'not added');
    await go(P, `pricing.php?owner_type=task&owner_id=${t2}&ms=${mid}`); await P.fill('#mrb', 'Geoff'); await clickIn(P, 'form:has(#mrb) button');
    assert(one(`SELECT marked_ready_by FROM billing_milestones WHERE id=${mid}`) === 'Geoff', 'mark ready');
    const csrf = await csrfOf(P);
    const del = await postAndGo(P, 'pricing.php', { owner_type: 'task', owner_id: String(t2), csrf, action: 'ms_delete', ms_id: String(mid), confirm: '1' });
    assert(num(`SELECT COUNT(*) FROM billing_milestones WHERE id=${mid}`) === 1 && /can’t be removed/.test(await del.text()), 'ready milestone removed');
    await postAndGo(P, 'pricing.php', { owner_type: 'task', owner_id: String(t2), csrf, action: 'ms_void', ms_id: String(mid), void_reason: '' });
    assert(one(`SELECT status FROM billing_milestones WHERE id=${mid}`) === 'planned', 'voided without reason');
    await postAndGo(P, 'pricing.php', { owner_type: 'task', owner_id: String(t2), csrf, action: 'ms_void', ms_id: String(mid), void_reason: 'Test Q12 not billing' });
    assert(one(`SELECT status FROM billing_milestones WHERE id=${mid}`) === 'void', 'void');
    await postAndGo(P, 'pricing.php', { owner_type: 'task', owner_id: String(t2), csrf, action: 'ms_restore', ms_id: String(mid) });
    await postAndGo(P, 'pricing.php', { owner_type: 'task', owner_id: String(t2), csrf, action: 'ms_unready', ms_id: String(mid) });
    assert(one(`SELECT CONCAT(status,'|',COALESCE(marked_ready_at,'-'))  FROM billing_milestones WHERE id=${mid}`) === 'planned|-', 'restore/unready');
    await postAndGo(P, 'pricing.php', { owner_type: 'task', owner_id: String(t2), csrf, action: 'ms_add', label: 'Test Q12 bad', amount: '10', percent: '10', trigger_kind: 'manual' });
    assert(num(`SELECT COUNT(*) FROM billing_milestones WHERE label='Test Q12 bad'`) === 0, 'both amount and % accepted');
    const noEnq = +one(`SELECT id FROM tasks WHERE enquiry_id IS NULL AND source_system='fabrik' AND NOT (${closed('status')}) ORDER BY id LIMIT 1`);
    await postAndGo(P, 'pricing.php', { owner_type: 'task', owner_id: String(noEnq), csrf, action: 'ms_add', label: 'Test Q12 accepted', amount: '10', trigger_kind: 'accepted' });
    assert(num(`SELECT COUNT(*) FROM billing_milestones WHERE label='Test Q12 accepted'`) === 0, '"accepted" allowed on a task without an enquiry');
  });
  await test('Q13', 'Make a template from a task: copies fields, price lines and milestones; asking again opens the same template; it can be used on an enquiry', async () => {
    const t1 = q07tasks[0]; await go(P, 'index.php?page=task&id=' + t1); await clickIn(P, '[data-testid=make-template] button');
    const tid = +one(`SELECT id FROM task_templates WHERE from_task_id=${t1}`); assert(tid && P.url().includes('edit-template.php?id=' + tid), 'not made');
    assert(one(`SELECT ROUND(SUM(quantity*unit_price_ex_gst),2) FROM breakdown_items WHERE template_id=${tid}`) === one(`SELECT ROUND(SUM(quantity*unit_price_ex_gst),2) FROM breakdown_items WHERE task_id=${t1}`), 'lines not copied');
    assert(num(`SELECT COUNT(*) FROM billing_milestones WHERE template_id=${tid}`) === num(`SELECT COUNT(*) FROM billing_milestones WHERE task_id=${t1} AND status<>'void'`), 'milestones not copied');
    await go(P, 'index.php?page=task&id=' + t1); assert(/template/.test(await P.locator('[data-testid=make-template]').innerText()) && await P.locator('[data-testid=make-template] button').count() === 0, 'offered twice');
    sql(`INSERT INTO enquiries (client_id,title,priority,status,created_at) VALUES (${S.anyClient},'Test Q13 use template','normal','enquiry',NOW())`); const e = enqMax();
    await go(P, 'convert-enquiry.php?id=' + e); await P.selectOption('#plan-template', String(tid)); await clickIn(P, 'form.add-plan button');
    assert(num(`SELECT COUNT(*) FROM enquiry_task_plans WHERE enquiry_id=${e} AND template_id=${tid}`) === 1 && num(`SELECT COUNT(*) FROM billing_milestones WHERE enquiry_id=${e}`) === num(`SELECT COUNT(*) FROM billing_milestones WHERE template_id=${tid}`), 'template not used');
  });
  await test('Q14', 'Deleting an app-made enquiry (no task) removes its planned tasks, price lines and milestones, with a snapshot in the audit', async () => {
    const e = enqMax(); await go(P, 'edit-enquiry.php?id=' + e); await P.check('input[name=confirm_delete]'); await clickIn(P, '.delete-form button');
    assert(num(`SELECT COUNT(*) FROM enquiries WHERE id=${e}`) === 0 && num(`SELECT COUNT(*) FROM enquiry_task_plans WHERE enquiry_id=${e}`) === 0 && num(`SELECT COUNT(*) FROM billing_milestones WHERE enquiry_id=${e}`) === 0, 'leftovers');
    assert(num(`SELECT COUNT(*) FROM activity_events WHERE event_kind='enquiry_deleted' AND JSON_LENGTH(JSON_EXTRACT(payload,'$.planned_tasks'))=1`) >= 1, 'snapshot');
  });
  await test('Q15', 'Retire pro-forma tasks: all cancelled and the client archived in one step; Undo restores every one; Redo retires again', async () => {
    const open = sql(`SELECT id,status FROM tasks WHERE client_id=${pf} AND NOT (${closed('status')}) AND completed_at IS NULL`);
    const clientStatus = one(`SELECT status FROM clients WHERE id=${pf}`);
    await go(P, 'index.php?page=templates'); await P.check('[data-testid=retire-proforma] input[name=confirm]'); await clickIn(P, '[data-testid=retire-proforma] button');
    assert(open.every(t => one(`SELECT status FROM tasks WHERE id=${t[0]}`) === 'cancelled') && one(`SELECT status FROM clients WHERE id=${pf}`) === 'archive', 'not retired');
    assert(await P.locator('[data-testid=retire-proforma]').count() === 0, 'panel still shown');
    await undoClick(P);
    assert(open.every(t => one(`SELECT status FROM tasks WHERE id=${t[0]}`) === t[1]) && one(`SELECT status FROM clients WHERE id=${pf}`) === clientStatus, 'undo did not restore');
    await undoClick(P);
    assert(open.every(t => one(`SELECT status FROM tasks WHERE id=${t[0]}`) === 'cancelled') && one(`SELECT status FROM clients WHERE id=${pf}`) === 'archive', 'redo');
    await go(P, 'index.php?page=tasks'); const shown = await P.$$eval('main a.record', a => a.map(x => +new URL(x.href).searchParams.get('id')));
    assert(!open.some(t => shown.includes(+t[0])), 'retired task in open list');
    note(`${open.length} pro-forma tasks retired`);
  });
  await test('Q16', 'Imported task: price lines, billing choice and stages can be added; its imported record and locked fields stay byte-for-byte (P01)', async () => {
    const t = S.fabrikTaskWithClient; await go(P, 'index.php?page=task&id=' + t);
    await openDetails(P, 'Add price line'); await P.fill('#ntask-desc', 'Test Q16 line on imported task'); await P.fill('#ntask-price', '10'); await clickIn(P, 'form:has(#ntask-desc) button[type=submit]');
    await openDetails(P, 'Break out into stages'); await P.fill('#stage0', 'Test Q16 stage'); await clickIn(P, '[data-testid=stages] button[type=submit]');
    assert(num(`SELECT COUNT(*) FROM breakdown_items WHERE task_id=${t}`) >= 1 && num(`SELECT COUNT(*) FROM tasks WHERE parent_task_id=${t}`) === 1, 'not added');
  });
  /* ================= v0.13: services, WHMCS import, Pending Invoicing, claims ================= */
  const WH = path.join(OUT, 'whmcs-fixture');
  const php = (...a) => { try { return { ok: true, out: execFileSync('php', [path.join(__dirname, '../deploy/import-whmcs.php'), ...a], { encoding: 'utf8', env: { ...process.env } }) }; } catch (e) { return { ok: false, out: String(e.stdout || '') + String(e.stderr || '') }; } };
  const addMonths = (iso, n) => { const [y, m, d] = iso.split('-').map(Number); const t = new Date(Date.UTC(y, m - 1 + n, 1)); const last = new Date(Date.UTC(t.getUTCFullYear(), t.getUTCMonth() + 1, 0)).getUTCDate(); return `${t.getUTCFullYear()}-${String(t.getUTCMonth() + 1).padStart(2, '0')}-${String(Math.min(d, last)).padStart(2, '0')}`; };
  let batch = 0;
  await test('R01', 'WHMCS import: dry run changes nothing; apply archives every row (no Password column, login-like note lines removed), creates live services and Xero history; same files again do nothing', async () => {
    execFileSync('php', [path.join(__dirname, 'fixture/make_whmcs.php'), WH]);
    const n0 = sql('SELECT (SELECT COUNT(*) FROM services),(SELECT COUNT(*) FROM archive_whmcs),(SELECT COUNT(*) FROM charges),(SELECT COUNT(*) FROM clients)')[0].join();
    const dr = php('dry-run', '--dir', WH); assert(dr.ok && /Dry run complete/.test(dr.out), dr.out.slice(0, 300));
    assert(sql('SELECT (SELECT COUNT(*) FROM services),(SELECT COUNT(*) FROM archive_whmcs),(SELECT COUNT(*) FROM charges),(SELECT COUNT(*) FROM clients)')[0].join() === n0, 'dry run changed data');
    const r = php('apply', '--dir', WH, '--by', 'Test R01'); assert(r.ok, r.out.slice(0, 400));
    batch = +(/batch (\d+)/.exec(r.out) || [])[1];
    assert(num('SELECT COUNT(*) FROM archive_whmcs') === 14, 'archive rows ' + num('SELECT COUNT(*) FROM archive_whmcs'));
    assert(num(`SELECT COUNT(*) FROM archive_whmcs WHERE CAST(source_record AS CHAR) LIKE '%hunter2%' OR CAST(source_record AS CHAR) LIKE '%p4ss%' OR CAST(source_record AS CHAR) LIKE '%letmein%' OR JSON_CONTAINS_PATH(source_record,'one','$.Password')`) === 0, 'a password reached the archive');
    assert(one(`SELECT REPLACE(JSON_UNQUOTE(JSON_EXTRACT(source_record,'$.Notes')),'\n',' ') FROM archive_whmcs WHERE source_table='services' AND source_id='1'`).includes('Renewal by email'), 'ordinary note lines lost');
    assert(num(`SELECT COUNT(*) FROM services WHERE source_system='whmcs'`) === 6 && num(`SELECT COUNT(*) FROM services WHERE source_id='S6'`) === 0, 'services (skipped client imported?)');
    assert(one(`SELECT CONCAT(client_id,'|',kind,'|',price_ex_gst,'|',cycle_months,'|',cpanel_username) FROM services WHERE source_id='S1'`) === '2|hosting|201.00|6|harbourv', 'S1 fields');
    assert(one(`SELECT CONCAT(is_internal,'|',billable) FROM services WHERE source_id='S5'`) === '1|0', 'internal');
    assert(one(`SELECT name FROM clients c JOIN services s ON s.client_id=c.id WHERE s.source_id='S7'`) === 'Fictional New Client Ltd', 'new client');
    assert(num(`SELECT COUNT(*) FROM charges WHERE origin='xero_import' AND status='invoiced'`) === 2, 'Xero history');
    assert(/already imported/.test(php('apply', '--dir', WH, '--by', 'Test R01').out), 'second import not refused');
    await go(P, 'index.php?page=check'); assert(!(await P.$$eval('.checklist li.bad', l => l.length)), 'release check failed');
  });
  await test('R02', 'Services list and client page: live services with next period; ended and archived-only WHMCS items under "Former services"; filters match the database', async () => {
    await go(P, 'index.php?page=services');
    const shown = await P.locator('[data-testid=services-list] a.record').count();
    assert(shown === num(`SELECT COUNT(*) FROM services WHERE status IN ('active','pending','suspended')`), 'live count ' + shown);
    await go(P, 'index.php?page=services&check=look'); assert(await P.locator('[data-testid=services-list] a.record').count() === num(`SELECT COUNT(*) FROM services WHERE check_status IS NOT NULL AND check_status NOT IN ('','agrees') AND status IN ('active','pending','suspended')`), 'check filter');
    await go(P, 'index.php?page=client&id=3'); const t = await P.locator('[data-testid=client-services]').innerText();
    assert(t.includes('kowhaiphysio.example.nz'), 'live service missing'); await openDetails(P, 'Former services');
    assert((await P.locator('[data-testid=former-services]').innerText()).includes('oldkowhai.example.nz'), 'former WHMCS items missing');
    const sid = one(`SELECT id FROM services WHERE source_id='S1'`); await go(P, 'index.php?page=service&id=' + sid);
    assert((await P.locator('[data-testid=service-charges]').innerText()).includes('CC-9001'), 'Xero history not on service');
    assert(!(await P.locator('main').innerText()).includes('hunter2'), 'password shown');
  });
  const money = n => '$' + Number(n).toLocaleString('en-NZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  await test('R03', 'Pending Invoicing: renewals due within 8 weeks (and overdue) grouped by client, Xero description pattern, account, ex and incl GST', async () => {
    await go(P, 'index.php?page=invoicing');
    const lead = new Date(Date.now() + 56 * 86400000); const hor = new Intl.DateTimeFormat('en-CA', { timeZone: 'Pacific/Auckland' }).format(lead);
    const due = num(`SELECT COUNT(*) FROM services WHERE status IN ('active','pending','suspended') AND billable=1 AND is_internal=0 AND price_ex_gst>0 AND cycle_months>0 AND next_period_start<='${hor}'`);
    assert(await P.locator('input[name="keys[]"][value^="svc:"]').count() === due, 'due renewals ' + due);
    const card = P.locator('[data-testid=invoice-client]', { hasText: 'harbourviewmotel.example.nz' });
    const txt = await card.innerText();
    const s1 = one(`SELECT next_period_start FROM services WHERE source_id='S1'`);
    const dmy = iso => iso.split('-').reverse().join('/');
    assert(txt.includes(`WEBSITE HOSTING - Standard - CMS with PHP 7 - harbourviewmotel.example.nz (${dmy(s1)} -`) && txt.includes('Billing Period: 6 Months'), 'hosting description: ' + txt.slice(0, 300));
    assert(txt.includes('Domain Renewal - harbourviewmotel.example.nz - 1 Year/s') && txt.includes('Email Only - harbourviewmotel.example.nz'), 'domain/email descriptions');
    assert(/overdue/i.test(txt) && txt.includes('Account 43504') && txt.includes('Account 43601') && txt.includes(money(201 * 1.15)), 'overdue / accounts / incl GST');
    assert(!(await P.locator('main').innerText()).includes('cloake.example.nz'), 'internal service listed');
  });
  await test('R04', 'Record one Xero invoice for two ticked lines: charges with the number and who; each service moves on one cycle; Undo puts everything back', async () => {
    const s1 = sql(`SELECT id,next_period_start FROM services WHERE source_id='S1'`)[0], s2 = sql(`SELECT id,next_period_start FROM services WHERE source_id='S2'`)[0];
    await go(P, 'index.php?page=invoicing');
    const card = P.locator('[data-testid=invoice-client]', { hasText: 'harbourviewmotel.example.nz' });
    for (const cb of await card.locator('input[name="keys[]"]').all()) { const v = await cb.getAttribute('value'); if (v !== `svc:${s1[0]}:${s1[1]}` && v !== `svc:${s2[0]}:${s2[1]}`) await cb.uncheck(); }
    await card.locator('input[name=invoice_number]').fill('INV-T0401'); await card.locator('input[name=recorded_by]').fill('Cathy');
    await Promise.all([P.waitForNavigation(), card.locator('button[type=submit]').click()]);
    const g = sql(`SELECT source_id,period_start,amount_ex_gst,recorded_by,xero_invoice_number FROM charges WHERE xero_invoice_number='INV-T0401' ORDER BY source_id`);
    assert(g.length === 2 && g.every(r => r[3] === 'Cathy') && g.some(r => r[1] === s1[1] && r[2] === '201.00'), 'charges ' + JSON.stringify(g));
    assert(one(`SELECT next_period_start FROM services WHERE id=${s1[0]}`) === addMonths(s1[1], 6) && one(`SELECT next_period_start FROM services WHERE id=${s2[0]}`) === addMonths(s2[1], 12), 'not moved on');
    assert(num(`SELECT COUNT(*) FROM activity_events WHERE event_kind='invoice_recorded'`) === 1, 'audit');
    await undoClick(P);
    assert(num(`SELECT COUNT(*) FROM charges WHERE xero_invoice_number='INV-T0401'`) === 0 && one(`SELECT next_period_start FROM services WHERE id=${s1[0]}`) === s1[1], 'undo');
  });
  await test('R05', 'Recording is refused (nothing saved) for lines of two clients, an already-recorded period, or no invoice number / name', async () => {
    await go(P, 'index.php?page=invoicing'); const csrf = await P.locator('input[name=csrf]').first().inputValue();
    const a = sql(`SELECT id,next_period_start FROM services WHERE source_id='S1'`)[0], b = sql(`SELECT id,next_period_start FROM services WHERE source_id='S7'`)[0];
    const n0 = num('SELECT COUNT(*) FROM charges');
    const post = async form => { const u = new URLSearchParams(); u.append('csrf', csrf); for (const [k, v] of Object.entries(form)) for (const x of [].concat(v)) u.append(k, x);
      await P.request.post(BASE + 'invoice-record.php', { data: u.toString(), headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, maxRedirects: 0 }); await go(P, 'index.php?page=invoicing'); return (await P.locator('.alert').count()) ? await P.locator('.alert').first().innerText() : ''; };
    assert(/different clients/.test(await post({ 'keys[]': [`svc:${a[0]}:${a[1]}`, `svc:${b[0]}:${b[1]}`], invoice_number: 'INV-T05', recorded_by: 'Cathy' })), 'two clients accepted');
    assert(/invoice number/.test(await post({ 'keys[]': `svc:${a[0]}:${a[1]}`, invoice_number: '', recorded_by: 'Cathy' })), 'no number accepted');
    assert(/already been recorded/.test(await post({ 'keys[]': `svc:${a[0]}:2000-01-01`, invoice_number: 'INV-T05', recorded_by: 'Cathy' })), 'stale period accepted');
    assert(num('SELECT COUNT(*) FROM charges') === n0, 'something was saved');
  });
  await test('R06', 'Correct the next period date: needs a reason; saved and audited with the reason; Undo restores it', async () => {
    const s = sql(`SELECT id,next_period_start FROM services WHERE source_id='S3'`)[0];
    await go(P, 'index.php?page=service&id=' + s[0]); const csrf = await P.locator('input[name=csrf]').first().inputValue();
    await P.request.post(BASE + 'service-edit.php', { form: { csrf, id: s[0], action: 'correct_date', next_period_start: '2030-01-01', reason: '' }, maxRedirects: 0 });
    assert(one(`SELECT next_period_start FROM services WHERE id=${s[0]}`) === s[1], 'changed without a reason');
    await go(P, 'index.php?page=service&id=' + s[0]); await openDetails(P, 'Correct the next period'); await P.fill('#np', '2030-01-01'); await P.fill('#why', 'Test R06 Xero shows a later period');
    await Promise.all([P.waitForNavigation(), P.click('form:has(#why) button')]);
    assert(one(`SELECT next_period_start FROM services WHERE id=${s[0]}`) === '2030-01-01' && num(`SELECT COUNT(*) FROM activity_events WHERE event_kind='service_updated' AND summary LIKE '%Test R06%'`) === 1, 'not saved/audited');
    await undoClick(P); assert(one(`SELECT next_period_start FROM services WHERE id=${s[0]}`) === s[1], 'undo');
  });
  await test('R07', 'New service: a billed service needs a price and cycle; once added it shows on the client and (when due) on Pending Invoicing', async () => {
    await go(P, 'service-edit.php?client_id=' + S.client.id); await P.fill('#domain', 'test-r07.example.nz'); await P.selectOption('#cycle_months', '12'); await P.fill('#next_period_start', todayNZ); await save(P);
    assert(/needs a price/.test(await P.locator('.alert').innerText()) && num(`SELECT COUNT(*) FROM services WHERE domain='test-r07.example.nz'`) === 0, 'saved without a price');
    await P.fill('#price_ex_gst', '120'); await save(P);
    assert(num(`SELECT COUNT(*) FROM services WHERE domain='test-r07.example.nz' AND source_system='app' AND price_ex_gst=120`) === 1, 'not saved');
    await go(P, 'index.php?page=invoicing'); assert((await P.locator('main').innerText()).includes('test-r07.example.nz'), 'not on Pending Invoicing');
  });
  await test('R08', 'A ready milestone and a completed on-completion task (with an amount override) appear on Pending Invoicing; recording them charges each once and ties the task\'s hours', async () => {
    const cid = S.otherClient;
    sql(`INSERT INTO tasks (client_id,source_system,source_id,title,status,received_at,completed_at,signed_off_at,signed_off_by) VALUES (${cid},'app','test-r08','Test R08 finished job','completed',NOW(),NOW(),NOW(),'Geoff')`); const tid = num('SELECT MAX(id) FROM tasks');
    sql(`INSERT INTO breakdown_items (task_id,description,quantity,unit_price_ex_gst,pricing_mode,status,line_order,created_at) VALUES (${tid},'Test R08 work',1,250,'set','proposed',1,NOW())`);
    sql(`INSERT INTO work_log (client_id,task_id,source_system,source_id,details,work_date,duration_seconds,billable_seconds,billability) VALUES (${cid},${tid},'app','test-r08w','Test R08 hours','${todayNZ}',3600,3600,'billable')`);
    sql(`INSERT INTO tasks (client_id,source_system,source_id,title,status,received_at,billing_mode) VALUES (${cid},'app','test-r08m','Test R08 staged job','open',NOW(),'milestones')`); const mt = num('SELECT MAX(id) FROM tasks');
    sql(`INSERT INTO billing_milestones (task_id,label,amount_ex_gst,trigger_kind,status,marked_ready_at,marked_ready_by,created_at) VALUES (${mt},'Test R08 stage 1',400,'manual','planned',NOW(),'Geoff',NOW())`); const msid = num('SELECT MAX(id) FROM billing_milestones');
    await go(P, 'index.php?page=invoicing');
    const card = P.locator('[data-testid=invoice-client]', { hasText: 'Test R08 finished job' });
    assert((await card.innerText()).includes('Test R08 stage 1'), 'milestone missing');
    await card.locator(`input[name="amount[fee:${tid}]"]`).fill('275.50');
    for (const cb of await card.locator('input[name="keys[]"]').all()) { const v = await cb.getAttribute('value'); if (v !== `fee:${tid}` && v !== `ms:${msid}`) await cb.uncheck(); }
    await card.locator('input[name=invoice_number]').fill('INV-T0801'); await card.locator('input[name=recorded_by]').fill('Cathy');
    await Promise.all([P.waitForNavigation(), card.locator('button[type=submit]').click()]);
    assert(one(`SELECT amount_ex_gst FROM charges WHERE source_kind='task_fee' AND source_id=${tid}`) === '275.50' && one(`SELECT amount_ex_gst FROM charges WHERE source_kind='milestone' AND source_id=${msid}`) === '400.00', 'charges');
    assert(num(`SELECT COUNT(*) FROM work_log WHERE task_id=${tid} AND charge_id IS NOT NULL`) === 1, 'hours not tied');
    await go(P, 'index.php?page=invoicing'); const t = await P.locator('main').innerText();
    assert(!t.includes('Test R08 finished job') && !t.includes('Test R08 stage 1'), 'still listed after invoicing');
  });
  await test('R09', 'Month-end claims: hours in the month × rate become a pending claim (hours tied), shown on Pending Invoicing; a progress claim over the remaining fee is refused; removing a claim frees its hours', async () => {
    await go(P, 'billing-settings.php'); await P.fill('#hourly_rate_ex_gst', '80'); await P.fill('#updated_by', 'Geoff'); await save(P);
    assert(one(`SELECT v FROM settings WHERE k='hourly_rate_ex_gst'`) === '80' && num(`SELECT COUNT(*) FROM activity_events WHERE event_kind='settings_updated'`) >= 1, 'setting');
    const month = todayNZ.slice(0, 7); const cid = S.otherClient;
    sql(`INSERT INTO tasks (client_id,source_system,source_id,title,status,received_at,billing_mode) VALUES (${cid},'app','test-r09','Test R09 monthly retainer','in progress',NOW(),'monthly')`); const tid = num('SELECT MAX(id) FROM tasks');
    sql(`INSERT INTO breakdown_items (task_id,description,quantity,unit_price_ex_gst,pricing_mode,status,line_order,created_at) VALUES (${tid},'Test R09 build',1,1000,'set','proposed',1,NOW())`);
    sql(`INSERT INTO work_log (client_id,task_id,source_system,source_id,details,work_date,duration_seconds,billable_seconds,billability) VALUES (${cid},${tid},'app','test-r09a','Test R09 a','${todayNZ}',5400,5400,'billable'),(${cid},${tid},'app','test-r09b','Test R09 b','${todayNZ}',1800,1800,'non_billable')`);
    await go(P, 'claims.php?month=' + month);
    assert(await P.inputValue(`#h${tid}`) === '120.00', 'hours amount ' + await P.inputValue(`#h${tid}`));
    await P.fill(`#p${tid}`, '1200'); await P.fill('#created_by', 'Geoff'); await save(P);
    assert(/more than what is left/.test(await P.locator('.alert').innerText()) && num(`SELECT COUNT(*) FROM charges WHERE source_id=${tid} AND source_kind IN ('claim','progress')`) === 0, 'over-claim accepted');
    await P.fill(`#p${tid}`, '300'); await save(P);
    assert(num(`SELECT COUNT(*) FROM charges WHERE source_id=${tid} AND source_kind='claim' AND status='pending' AND amount_ex_gst=120`) === 1 && num(`SELECT COUNT(*) FROM charges WHERE source_id=${tid} AND source_kind='progress' AND amount_ex_gst=300`) === 1, 'claims not created');
    assert(num(`SELECT COUNT(*) FROM work_log WHERE task_id=${tid} AND charge_id IS NOT NULL`) === 1, 'billable hours not tied (or non-billable tied)');
    await go(P, 'index.php?page=invoicing'); assert((await P.locator('main').innerText()).includes('Test R09 monthly retainer'), 'claim not on Pending Invoicing');
    const gid = num(`SELECT id FROM charges WHERE source_id=${tid} AND source_kind='claim'`);
    await go(P, 'claims.php?month=' + month); await Promise.all([P.waitForNavigation(), P.click(`form:has(input[name=charge_id][value="${gid}"]) button`)]);
    assert(num(`SELECT COUNT(*) FROM charges WHERE id=${gid}`) === 0 && num(`SELECT COUNT(*) FROM work_log WHERE task_id=${tid} AND charge_id IS NOT NULL`) === 0, 'remove did not free hours');
  });
  await test('R10', 'Lead time setting: widening it to 20 weeks adds the later renewal to Pending Invoicing', async () => {
    await go(P, 'index.php?page=invoicing'); const before = await P.locator('input[name="keys[]"][value^="svc:"]').count();
    await go(P, 'billing-settings.php'); await P.fill('#lead_weeks', '20'); await P.fill('#updated_by', 'Geoff'); await save(P);
    await go(P, 'index.php?page=invoicing'); const after = await P.locator('input[name="keys[]"][value^="svc:"]').count();
    assert(after > before && (await P.locator('main').innerText()).includes('kowhaiphysio.example.nz'), `before ${before} after ${after}`);
    await go(P, 'billing-settings.php'); await P.fill('#lead_weeks', '8'); await P.fill('#updated_by', 'Geoff'); await save(P);
  });
  await test('R11', 'Import undo: refused while an invoice recorded in the manager uses its services; allowed after that invoice is undone; removes services, archive, history and the new client', async () => {
    const s7 = sql(`SELECT id,next_period_start FROM services WHERE source_id='S7'`)[0];
    await go(P, 'index.php?page=invoicing'); const card = P.locator('[data-testid=invoice-client]', { hasText: 'newclient.example.nz' });
    await card.locator('input[name=invoice_number]').fill('INV-T1101'); await card.locator('input[name=recorded_by]').fill('Cathy');
    await Promise.all([P.waitForNavigation(), card.locator('button[type=submit]').click()]);
    const r1 = php('undo', '--batch', String(batch), '--confirm'); assert(!r1.ok && /invoices have been recorded/.test(r1.out), 'undo not refused: ' + r1.out.slice(0, 200));
    await undoClick(P);
    const r2 = php('undo', '--batch', String(batch), '--confirm'); assert(r2.ok, r2.out.slice(0, 300));
    assert(num(`SELECT COUNT(*) FROM services WHERE source_system='whmcs'`) === 0 && num('SELECT COUNT(*) FROM archive_whmcs') === 0 && num(`SELECT COUNT(*) FROM charges WHERE origin='xero_import'`) === 0 && num(`SELECT COUNT(*) FROM clients WHERE source_system='whmcs'`) === 0, 'leftovers');
    const r3 = php('apply', '--dir', WH, '--by', 'Test R11'); assert(r3.ok, 'reimport failed');
  });
  /* ================= v0.14: scheduling, staff, materials, sidebar ================= */
  const openFor = name => +one(`SELECT id FROM tasks t WHERE CONCAT(',',REPLACE(LOWER(COALESCE(t.assigned_to,'')),' ',''),',') LIKE '%,${esc(name)},%' AND NOT (${closed('t.status')}) AND t.completed_at IS NULL AND t.scheduled_on IS NULL AND (t.source_system<>'fabrik' OR t.required_by IS NULL OR t.required_by >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)) ORDER BY t.id DESC LIMIT 1`);
  const staffOn = sql(`SELECT name FROM staff WHERE active=1 ORDER BY name`).map(r => r[0]);
  const who1 = staffOn.find(n => openFor(n)) || staffOn[0], who2 = staffOn.find(n => n !== who1);   // someone with open work to schedule
  const dPlus = n => nzDate(n);
  let kTask = 0;
  await test('K01', 'Staff & hours: people seeded from "Action for"; hours per weekday saved (7.5 h = 450 min), audited, Undo restores; a new person can be added; switched-off people leave the Workload', async () => {
    const names = sql(`SELECT name FROM staff`).map(r => r[0]);
    // Names in the imported records' original Action for (never changed by the app, so the same as at migration time).
    const fromTasks = new Set(sql(`SELECT JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$.action_for')) FROM tasks WHERE legacy_record IS NOT NULL`).flatMap(r => { const v = r[0] || ''; let a; try { a = JSON.parse(v); } catch (e) { a = v.split(','); } return (Array.isArray(a) ? a : [String(a)]).map(x => String(x).trim().toLowerCase()).filter(x => x && x !== 'null'); }));
    assert(names.length && [...fromTasks].every(n => n.length > 40 || names.includes(n)), 'staff not seeded from Action for: ' + names.join(','));
    assert(who1 && who2, 'need two active staff');
    const sid = +one(`SELECT id FROM staff WHERE name='${esc(who1)}'`);
    await go(P, 'staff.php'); await openDetails(P, who1.charAt(0).toUpperCase() + who1.slice(1));
    await P.fill(`#hmon${sid}`, '7.5'); await clickIn(P, `form:has(input[name=id][value="${sid}"]) button.save`);
    assert(+one(`SELECT min_mon FROM staff WHERE id=${sid}`) === 450, 'hours not saved');
    await undoClick(P); assert(+one(`SELECT min_mon FROM staff WHERE id=${sid}`) === 360, 'undo of hours');
    await go(P, 'staff.php'); await openDetails(P, 'Add a person'); await P.fill('#new-name', 'kaytest'); await clickIn(P, 'form:has(#new-name) button[type=submit]');
    assert(num(`SELECT COUNT(*) FROM staff WHERE name='kaytest' AND active=1 AND min_fri=360 AND min_sat=0`) === 1, 'person not added');
    await go(P, 'index.php?page=workload'); assert(await P.locator('[data-testid=wl-person][data-person=kaytest]').count() === 1, 'new person not on Workload');
    const kid = +one(`SELECT id FROM staff WHERE name='kaytest'`);
    await go(P, 'staff.php'); await openDetails(P, 'Kaytest'); await P.uncheck(`form:has(input[name=id][value="${kid}"]) input[name=active]`); await clickIn(P, `form:has(input[name=id][value="${kid}"]) button.save`);
    await go(P, 'index.php?page=workload'); assert(await P.locator('[data-testid=wl-person][data-person=kaytest]').count() === 0, 'switched-off person still on Workload');
  });
  await test('K02', 'Workload: a task scheduled today shows under its person with booked time against the day\'s hours; unscheduled and overdue-on-schedule tasks are listed separately', async () => {
    kTask = openFor(who1); assert(kTask, 'no open task for ' + who1);
    const late = +one(`SELECT id FROM tasks t WHERE CONCAT(',',REPLACE(LOWER(COALESCE(t.assigned_to,'')),' ',''),',') LIKE '%,${esc(who1)},%' AND NOT (${closed('t.status')}) AND t.completed_at IS NULL AND t.id<>${kTask} AND t.scheduled_on IS NULL ORDER BY t.id DESC LIMIT 1`);
    sql(`UPDATE tasks SET scheduled_on='${todayNZ}', scheduled_minutes=90 WHERE id=${kTask}`);
    if (late) sql(`UPDATE tasks SET scheduled_on='${dPlus(-3)}' WHERE id=${late}`);
    await go(P, 'index.php?page=workload&who=' + who1);
    const day = P.locator(`[data-testid=wl-day][data-who="${who1}"][data-date="${todayNZ}"]`);
    if (await day.count()) {   // today may be a day with no hours (weekend) and no tasks — then it isn't shown
      const t = await day.innerText(); assert(t.includes('1h 30m') && (await day.locator(`[data-task="${kTask}"]`).count()) === 1, 'today: ' + t.slice(0, 200));
    } else assert(false, 'today not shown although a task is scheduled');
    if (late) assert((await P.locator('[data-testid=wl-person]').innerText()).includes('Overdue on the schedule'), 'overdue section missing');
    const uns = +one(`SELECT COUNT(*) FROM tasks t WHERE CONCAT(',',REPLACE(LOWER(COALESCE(t.assigned_to,'')),' ',''),',') LIKE '%,${esc(who1)},%' AND NOT (${closed('t.status')}) AND t.completed_at IS NULL AND t.scheduled_on IS NULL AND (t.source_system IS NULL OR t.source_system<>'fabrik' OR t.required_by IS NULL OR DATE(t.required_by) >= DATE_SUB('${todayNZ}', INTERVAL 60 DAY))`);
    assert((await P.locator('.wl-unsched summary').innerText()).includes(`(${uns})`), 'not-scheduled count vs db ' + uns);
    if (late) sql(`UPDATE tasks SET scheduled_on=NULL WHERE id=${late}`);
  });
  await test('K03', 'Move (phone form): another day, 2 h booked and another person — Action for has the new person instead, audited; Undo puts all three back', async () => {
    const before = sql(`SELECT assigned_to, scheduled_on, scheduled_minutes FROM tasks WHERE id=${kTask}`)[0];
    await go(P, `schedule.php?id=${kTask}&who=${who1}`);
    await P.fill('#scheduled_on', dPlus(2)); await P.fill('#hours', '2'); await P.fill('#minutes', '0'); await P.selectOption('#to', who2); await save(P);
    const r = sql(`SELECT assigned_to, scheduled_on, scheduled_minutes FROM tasks WHERE id=${kTask}`)[0];
    const list = r[0].split(',').map(x => x.trim());
    assert(r[1] === dPlus(2) && r[2] === '120' && list.includes(who2) && !list.includes(who1), 'move ' + JSON.stringify(r));
    assert(num(`SELECT COUNT(*) FROM activity_events WHERE task_id=${kTask} AND event_kind='task_updated' AND summary LIKE '%moved to%'`) >= 1, 'audit');
    await undoClick(P);
    const u = sql(`SELECT assigned_to, scheduled_on, scheduled_minutes FROM tasks WHERE id=${kTask}`)[0];
    assert(JSON.stringify(u) === JSON.stringify(before), 'undo ' + JSON.stringify(u) + ' vs ' + JSON.stringify(before));
  });
  await test('K04', 'Drag and drop (desktop): a card dragged to another day is rescheduled; dragged to "Not scheduled" it comes off the schedule; no JS or CSP errors', async () => {
    const { ctx, page } = await newSession(DESKTOP);
    await page.setViewportSize({ width: DESKTOP, height: 3000 });   // no scrolling during the simulated drag
    page._problems.length = 0;   // the sign-in page's 401 is expected
    await go(page, `index.php?page=workload&who=${who1}&days=14`);
    const targets = await page.$$eval(`[data-testid=wl-day][data-who="${who1}"]`, ds => ds.map(d => d.getAttribute('data-date')));
    const target = targets.find(d => d > todayNZ); assert(target, 'no later day shown');
    // A real mouse drag (Playwright's dragAndDrop helper does not start HTML5 drags here).
    const mouseDrag = async (src, dst) => { await page.locator(src).hover(); await page.mouse.down(); await page.locator(dst).hover({ force: true }); await page.mouse.move(5, 5, { steps: 2 }); await page.locator(dst).hover({ force: true }); await Promise.all([page.waitForNavigation(), page.mouse.up()]); };
    await mouseDrag(`[data-testid=wl-card][data-task="${kTask}"] strong`, `[data-testid=wl-day][data-who="${who1}"][data-date="${target}"] .wl-date`);
    assert(one(`SELECT scheduled_on FROM tasks WHERE id=${kTask}`) === target, 'drag to day: ' + one(`SELECT scheduled_on FROM tasks WHERE id=${kTask}`));
    await page.evaluate(() => document.querySelectorAll('details.wl-unsched').forEach(d => d.open = true));
    await mouseDrag(`[data-testid=wl-card][data-task="${kTask}"] strong`, `details.wl-unsched[data-who="${who1}"] summary`);
    assert(one(`SELECT scheduled_on FROM tasks WHERE id=${kTask}`) === null, 'drag to Not scheduled');
    assert(!page._problems.length, page._problems.join(' | '));
    await ctx.close();
  });
  await test('K05', 'Scheduling is refused for a closed task and for someone not on Staff & hours (nothing changed)', async () => {
    await go(P, 'index.php?page=workload'); const csrf = await P.locator('[data-workload]').getAttribute('data-csrf');
    const done = +one(`SELECT id FROM tasks WHERE LOWER(status)='completed' ORDER BY id LIMIT 1`);
    let r = await P.request.post(BASE + 'schedule.php', { form: { id: String(done), csrf, ajax: '1', scheduled_on: dPlus(1), from: who1, to: who1 } });
    assert(r.status() === 422 && one(`SELECT scheduled_on FROM tasks WHERE id=${done}`) === null, 'closed task scheduled');
    r = await P.request.post(BASE + 'schedule.php', { form: { id: String(kTask), csrf, ajax: '1', scheduled_on: dPlus(1), from: who1, to: 'nobody-here' } });
    assert(r.status() === 422 && !/nobody-here/.test(one(`SELECT COALESCE(assigned_to,'') FROM tasks WHERE id=${kTask}`)), 'unknown person accepted');
  });
  let kItem = 0;
  await test('K06', 'Materials: sell price defaults to cost + markup (20% → $120 on $100), or is typed; bad cost refused; edit audited and undoable; an ordered item can be deleted', async () => {
    sql(`UPDATE settings SET v='20' WHERE k='material_markup_pct'`);
    await go(P, 'index.php?page=task&id=' + kTask + '#materials');
    await openDetails(P, 'Add an item'); await P.fill('#ni-desc', 'Test K06 SSL certificate'); await P.fill('#ni-sup', 'Test Supplier'); await P.fill('#ni-cost', '100');
    await clickIn(P, 'form:has(#ni-desc) button[type=submit]');
    kItem = +one(`SELECT id FROM job_items WHERE description='Test K06 SSL certificate'`);
    assert(one(`SELECT CONCAT(unit_cost_ex_gst,'|',unit_sell_ex_gst,'|',status) FROM job_items WHERE id=${kItem}`) === '100.00|120.00|ordered', 'markup default');
    assert((await P.locator('[data-testid=materials]').innerText()).includes('margin $20.00'), 'margin shown');
    await openDetails(P, 'Add an item'); await P.fill('#ni-desc', 'Test K06 bad'); await P.fill('#ni-cost', 'abc'); await clickIn(P, 'form:has(#ni-desc) button[type=submit]');
    assert(num(`SELECT COUNT(*) FROM job_items WHERE description='Test K06 bad'`) === 0 && await P.locator('#materials .alert').count() === 1, 'bad cost accepted');
    await go(P, 'materials.php?id=' + kItem); await P.fill('#e-sell', '150'); await save(P);
    assert(one(`SELECT unit_sell_ex_gst FROM job_items WHERE id=${kItem}`) === '150.00', 'edit');
    await undoClick(P); assert(one(`SELECT unit_sell_ex_gst FROM job_items WHERE id=${kItem}`) === '120.00', 'undo of edit');
    await go(P, 'index.php?page=task&id=' + kTask); await openDetails(P, 'Add an item'); await P.fill('#ni-desc', 'Test K06 mistake'); await P.fill('#ni-cost', '5'); await clickIn(P, 'form:has(#ni-desc) button[type=submit]');
    const mid = +one(`SELECT id FROM job_items WHERE description='Test K06 mistake'`);
    await go(P, 'materials.php?id=' + mid); await P.check('.delete-form input[name=confirm]'); await clickIn(P, '.delete-form button');
    assert(num(`SELECT COUNT(*) FROM job_items WHERE id=${mid}`) === 0, 'delete');
  });
  await test('K07', 'A received item is a "Material" line on Pending Invoicing; recording it charges it once and locks the item; Undo frees it', async () => {
    await go(P, 'index.php?page=task&id=' + kTask); await clickIn(P, `form:has(input[name=id][value="${kItem}"]) button`);
    assert(one(`SELECT status FROM job_items WHERE id=${kItem}`) === 'received', 'not received');
    await go(P, 'index.php?page=invoicing');
    const line = P.locator(`input[name="keys[]"][value="mat:${kItem}"]`); assert(await line.count() === 1, 'not on Pending Invoicing');
    const card = P.locator('[data-testid=invoice-client]', { has: line });
    for (const cb of await card.locator('input[name="keys[]"]').all()) if (await cb.getAttribute('value') !== `mat:${kItem}`) await cb.uncheck();
    await card.locator('input[name=invoice_number]').fill('INV-T0701'); await card.locator('input[name=recorded_by]').fill('Cathy');
    await Promise.all([P.waitForNavigation(), card.locator('button[type=submit]').click()]);
    assert(one(`SELECT CONCAT(source_kind,'|',amount_ex_gst,'|',status) FROM charges WHERE xero_invoice_number='INV-T0701'`) === 'material|120.00|invoiced', 'charge');
    await go(P, 'index.php?page=invoicing'); assert(await P.locator(`input[value="mat:${kItem}"]`).count() === 0, 'still on Pending Invoicing');
    await go(P, 'index.php?page=task&id=' + kTask); const csrf = await csrfOf(P);
    const r = await P.request.post(BASE + 'materials.php', { form: { id: String(kItem), csrf, action: 'save', description: 'Test K07 changed', quantity: '1', unit_cost: '1', unit_sell: '1' }, maxRedirects: 0 });
    assert(one(`SELECT description FROM job_items WHERE id=${kItem}`) === 'Test K06 SSL certificate' && /invoiced/.test(await r.text()), 'invoiced item changed');
    await go(P, 'index.php?page=invoicing'); // the undo bar was shown after recording; record again to test undo directly
    sql(`DELETE FROM charges WHERE xero_invoice_number='INV-T0701'`);
    await go(P, 'index.php?page=invoicing'); const card2 = P.locator('[data-testid=invoice-client]', { has: P.locator(`input[value="mat:${kItem}"]`) });
    for (const cb of await card2.locator('input[name="keys[]"]').all()) if (await cb.getAttribute('value') !== `mat:${kItem}`) await cb.uncheck();
    await card2.locator('input[name=invoice_number]').fill('INV-T0702'); await card2.locator('input[name=recorded_by]').fill('Cathy');
    await Promise.all([P.waitForNavigation(), card2.locator('button[type=submit]').click()]);
    await undoClick(P);
    assert(num(`SELECT COUNT(*) FROM charges WHERE xero_invoice_number='INV-T0702'`) === 0, 'undo did not remove the charge');
    await go(P, 'index.php?page=invoicing'); assert(await P.locator(`input[value="mat:${kItem}"]`).count() === 1, 'not back on Pending Invoicing after Undo');
  });
  await test('K08', 'Cancelling an item needs a reason and keeps it off Pending Invoicing; a received item cannot be deleted', async () => {
    await go(P, 'index.php?page=task&id=' + kTask); const csrf = await csrfOf(P);
    let r = await P.request.post(BASE + 'materials.php', { form: { id: String(kItem), csrf, action: 'delete', confirm: '1', from_edit: '1' }, maxRedirects: 0 });
    assert(num(`SELECT COUNT(*) FROM job_items WHERE id=${kItem}`) === 1 && /can’t be deleted/.test(await r.text()), 'received item deleted');
    r = await P.request.post(BASE + 'materials.php', { form: { id: String(kItem), csrf, action: 'cancel', cancel_reason: '', from_edit: '1' }, maxRedirects: 0 });
    assert(one(`SELECT status FROM job_items WHERE id=${kItem}`) === 'received', 'cancelled without reason');
    await postAndGo(P, 'materials.php', { id: String(kItem), csrf, action: 'cancel', cancel_reason: 'Test K08 not needed', from_edit: '1' });
    assert(one(`SELECT status FROM job_items WHERE id=${kItem}`) === 'cancelled', 'cancel');
    await go(P, 'index.php?page=invoicing'); assert(await P.locator(`input[value="mat:${kItem}"]`).count() === 0, 'cancelled item on Pending Invoicing');
    sql(`UPDATE settings SET v='' WHERE k='material_markup_pct'`);
  });
  /* ================= v0.15: simpler screens, Cloake Workdesk ================= */
  await test('W01', 'Desktop sidebar shows only Today, Tasks, Work Log, Clients, Enquiries and More…; More… lists every other screen (all load) and is highlighted on them', async () => {
    const { ctx, page } = await newSession(DESKTOP);
    await go(page, 'index.php?page=today');
    const links = await page.$$eval('aside.side a.nav', a => a.map(x => [x.textContent.trim(), x.getAttribute('href')]));
    assert(JSON.stringify(links.map(x => x[0])) === JSON.stringify(['Today', 'Tasks', 'Work Log', 'Clients', 'Enquiries', 'More…']), 'sidebar ' + links.map(x => x[0]));
    for (const [label, href] of links) { const r = await go(page, href); assert(r.status() === 200, label + ' → ' + r.status()); }
    await go(page, 'index.php?page=more');
    const more = await page.$$eval('main .more-list a', a => a.map(x => [x.querySelector('strong').textContent.trim(), x.getAttribute('href')]));
    for (const want of ['Workload', 'Task templates', 'Staff & hours', 'Pending Invoicing', 'Services', 'Domains', 'Xero connection', 'Month-end claims', 'Billing settings', 'Account Items', 'Release check', 'Enquiries']) assert(more.find(x => x[0] === want), 'missing from More: ' + want);
    for (const [label, href] of more) {
      const r = await go(page, href); assert(r.status() === 200, label + ' → ' + r.status());
      if (!['Enquiries', '＋ Quick Enquiry'].includes(label)) assert(await page.locator('aside.side a.nav.current').innerText() === 'More…', label + ': More… not highlighted');
    }
    await ctx.close();
  });
  await test('W02', 'Task page: a plain task keeps pricing, materials, stages and template behind a closed "More options"; one that uses them opens it; a #materials link opens it', async () => {
    const plain = +one(`SELECT t.id FROM tasks t WHERE t.parent_task_id IS NULL AND t.billing_mode IS NULL AND NOT EXISTS (SELECT 1 FROM breakdown_items b WHERE b.task_id=t.id) AND NOT EXISTS (SELECT 1 FROM billing_milestones m WHERE m.task_id=t.id) AND NOT EXISTS (SELECT 1 FROM job_items i WHERE i.task_id=t.id) AND NOT EXISTS (SELECT 1 FROM tasks s WHERE s.parent_task_id=t.id) AND NOT EXISTS (SELECT 1 FROM task_templates x WHERE x.from_task_id=t.id) AND NOT (${closed('t.status')}) ORDER BY t.id DESC LIMIT 1`);
    assert(plain, 'no plain open task');
    await go(P, 'index.php?page=task&id=' + plain);
    assert(await P.locator('[data-testid=more-options]').evaluate(d => !d.open), 'More options open on a plain task');
    assert(!(await P.locator('#pricing').isVisible()) && !(await P.locator('#materials').isVisible()), 'advanced panels visible');
    assert(await P.locator('a.ca.primary:has-text("Log work")').isVisible() && await P.locator('[data-testid=signoff]').isVisible(), 'everyday actions hidden');
    await P.click('[data-testid=more-options] > summary');
    assert(await P.locator('#pricing').isVisible() && await P.locator('[data-testid=make-template]').isVisible(), 'More options does not reveal the panels');
    await go(P, 'index.php?page=task&id=' + plain + '#materials');
    assert(await P.locator('[data-testid=more-options]').evaluate(d => d.open), '#materials link did not open More options');
    const used = +one(`SELECT task_id FROM breakdown_items WHERE task_id IS NOT NULL ORDER BY id DESC LIMIT 1`);
    if (used) { await go(P, 'index.php?page=task&id=' + used); const sm = P.locator('[data-testid=more-options]'); assert(await sm.evaluate(d => d.open) && /price line/.test(await sm.locator('> summary').innerText()), 'task with price lines: More options closed or no hint'); }
  });
  await test('W03', 'The app is called Cloake Workdesk: page titles, header, More page and sign-in page', async () => {
    await go(P, 'index.php?page=today');
    assert(/· Cloake Workdesk$/.test(await P.title()) && /Cloake Workdesk/.test(await P.locator('header.top .brand').innerText()), 'title/header');
    await go(P, 'index.php?page=more'); assert(/Cloake Workdesk v/.test(await P.locator('.more-version').innerText()), 'more page');
    const { ctx, page } = await newSession(DESKTOP, { login: false });
    await go(page, 'index.php'); assert(/Cloake Workdesk/.test(await page.title()) && /Cloake Workdesk/.test(await page.locator('main.login').innerText()), 'sign-in page');
    await ctx.close();
  });
  /* ================= v0.16: domain register and provisioning ================= */
  {
    const addDays = n => new Intl.DateTimeFormat('en-CA', { timeZone: 'Pacific/Auckland' }).format(new Date(Date.now() + n * 86400000));
    const cid = S.client.id, other = +one(`SELECT id FROM clients WHERE id<>${S.client.id} ORDER BY id LIMIT 1`);
    const mk = (domain, kind, opts = {}) => { const o = { client: cid, billable: 1, price: 30, cycle: 12, next: addDays(400), expiry: null, registrar: null, ...opts };
      sql(`INSERT INTO services (client_id,kind,domain,status,billable,is_internal,price_ex_gst,cycle_months,next_period_start,expiry_date,registrar,source_system,created_at) VALUES (${o.client},'${kind}','${domain}','active',${o.billable},0,${o.price === null ? 'NULL' : o.price},${o.cycle === null ? 'NULL' : o.cycle},${o.next ? `'${o.next}'` : 'NULL'},${o.expiry ? `'${o.expiry}'` : 'NULL'},${o.registrar ? `'${o.registrar}'` : 'NULL'},'app',NOW())`);
      return num(`SELECT id FROM services WHERE domain='${domain}' ORDER BY id DESC LIMIT 1`); };
    let host = 0, dSoon = 0, dFree = 0, dBilled = 0;
    await test('D01', 'Migration 007: six domain columns on services; the "Set up hosting + domain" checklist template; Release check shows 007 applied', async () => {
      for (const c of ['nameservers', 'dns_host', 'auto_renew', 'included_in_service_id', 'provider_ref', 'provision_task_id']) assert(num(`SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='services' AND COLUMN_NAME='${c}'`) === 1, 'missing ' + c);
      assert(num(`SELECT COUNT(*) FROM task_templates WHERE title='Set up hosting + domain' AND active=1 AND LOCATE('Register or transfer', instructions)>0 AND LOCATE('SSL', instructions)>0`) === 1, 'template');
      await go(P, 'index.php?page=check'); assert(/Migration 007[^\n]*\n?[^\n]*applied/.test(await P.locator('main').innerText()), 'release check');
    });
    await test('D02', 'Domains page: only domains; expiring-within-60-days, expired and registrar filters match the database; expiry shown amber/red', async () => {
      host = mk('test-d-host.example.nz', 'hosting', { price: 300, cycle: 12 });
      dSoon = mk('test-d-soon.example.nz', 'domain', { expiry: addDays(20), registrar: 'TestReg D02' });
      mk('test-d-gone.example.nz', 'domain', { expiry: addDays(-5), registrar: 'TestReg D02' });
      dFree = mk('test-d-free.example.nz', 'domain', { billable: 0, price: null, cycle: null, next: null, expiry: addDays(200) });
      dBilled = mk('test-d-billed.example.nz', 'domain', { price: 35, cycle: 12, next: todayNZ, expiry: addDays(300) });
      await go(P, 'index.php?page=domains');
      const all = await P.$$eval('[data-testid=domains-list] a[data-domain]', a => a.map(x => x.dataset.domain));
      assert(all.length === num(`SELECT COUNT(*) FROM services WHERE kind='domain' AND status IN ('active','pending','suspended')`) || all.length === 500, 'count ' + all.length);
      assert(!all.includes('test-d-host.example.nz') && all.includes('test-d-soon.example.nz'), 'hosting listed / domain missing');
      await go(P, 'index.php?page=domains&show=soon'); const soon = await P.$$eval('[data-testid=domains-list] a[data-domain]', a => a.map(x => x.dataset.domain));
      assert(soon.length === num(`SELECT COUNT(*) FROM services WHERE kind='domain' AND status IN ('active','pending','suspended') AND expiry_date>='${todayNZ}' AND expiry_date<='${addDays(60)}'`) && soon.includes('test-d-soon.example.nz') && !soon.includes('test-d-gone.example.nz'), 'soon filter');
      assert(await P.locator('a[data-domain="test-d-soon.example.nz"] .end.soon').count() === 1, 'not amber');
      await go(P, 'index.php?page=domains&show=expired'); assert(await P.locator('a[data-domain="test-d-gone.example.nz"] .end.late').count() === 1, 'expired not red');
      await go(P, 'index.php?page=domains&show=never'); const nev = await P.$$eval('[data-testid=domains-list] a[data-domain]', a => a.map(x => x.dataset.domain));
      assert(nev.length === num(`SELECT COUNT(*) FROM services s WHERE kind='domain' AND status IN ('active','pending','suspended') AND billable=1 AND is_internal=0 AND included_in_service_id IS NULL AND NOT EXISTS (SELECT 1 FROM charges g WHERE g.source_kind='service' AND g.source_id=s.id AND g.status<>'void')`) && nev.includes('test-d-billed.example.nz'), 'never-invoiced filter');
      await go(P, 'index.php?page=domains&reg=' + encodeURIComponent('TestReg D02')); assert((await P.$$('[data-testid=domains-list] a[data-domain]')).length === 2, 'registrar filter');
    });
    await test('D03', 'Include a domain in hosting: leaves "No charge, not linked", audited, Undo restores; the hosting\'s Xero description lists it as an Additional Domain; another client\'s hosting is refused', async () => {
      await go(P, 'index.php?page=domains&show=unlinked'); assert(await P.locator('a[data-domain="test-d-free.example.nz"]').count() === 1, 'not under unlinked');
      await go(P, 'index.php?page=service&id=' + dFree);
      assert(await P.locator('[data-testid=include-form]').evaluate(d => d.open), 'include form not offered open');
      await P.selectOption('#inc-host', String(host)); await clickIn(P, '[data-testid=include-form] button[type=submit]');
      assert(num(`SELECT COALESCE(included_in_service_id,0) FROM services WHERE id=${dFree}`) === host, 'not included');
      assert(/included in hosting test-d-host/.test(one(`SELECT summary FROM activity_events WHERE event_kind='service_updated' ORDER BY id DESC LIMIT 1`)), 'not audited');
      assert(/test-d-host/.test(await P.locator('[data-testid=included-in]').innerText()), 'service page');
      await go(P, 'index.php?page=domains&show=unlinked'); assert(await P.locator('a[data-domain="test-d-free.example.nz"]').count() === 0, 'still unlinked');
      await go(P, 'index.php?page=service&id=' + host);
      assert(/test-d-free\.example\.nz/.test(await P.locator('[data-testid=included-domains]').innerText()) && /Additional Domains: test-d-free\.example\.nz/.test(await P.locator('main').innerText()), 'hosting description');
      await go(P, 'index.php?page=service&id=' + dFree); await P.locator('[data-testid=include-form] summary').click(); await P.selectOption('#inc-host', ''); await clickIn(P, '[data-testid=include-form] button[type=submit]');
      await undoClick(P); assert(num(`SELECT COALESCE(included_in_service_id,0) FROM services WHERE id=${dFree}`) === host, 'undo of removal');
      const foreign = mk('test-d-other-host.example.nz', 'hosting', { client: other });
      const csrf = await csrfOf(P); await postAndGo(P, 'service-edit.php', { csrf, id: String(dFree), action: 'include', included_in_service_id: String(foreign) });
      assert(/same client/.test(await P.locator('.alert').innerText()) && num(`SELECT included_in_service_id FROM services WHERE id=${dFree}`) === host, 'foreign hosting accepted');
    });
    await test('D04', 'A billed domain included in hosting stops being invoiced on its own: off Pending Invoicing; recording it by a forged key is refused', async () => {
      await go(P, 'index.php?page=invoicing'); assert((await P.locator('main').innerText()).includes('test-d-billed.example.nz'), 'billed domain not due before');
      const key = `svc:${dBilled}:${todayNZ}`;
      await go(P, 'service-edit.php?id=' + dBilled); await P.selectOption('#included_in_service_id', String(host)); await save(P);
      assert(one(`SELECT CONCAT(billable,'/',included_in_service_id) FROM services WHERE id=${dBilled}`) === '0/' + host, 'not forced to no charge');
      await go(P, 'index.php?page=invoicing'); const txt = await P.locator('main').innerText();
      assert(!txt.includes('test-d-billed.example.nz') || /Additional Domains:[^\n]*test-d-billed/.test(txt), 'still due on its own');
      sql(`UPDATE services SET billable=1 WHERE id=${dBilled}`);   // even if someone ticks "billed" again, an included domain is never invoiced alone
      const csrf = await csrfOf(P); await postAndGo(P, 'invoice-record.php', { csrf, 'keys[]': key, invoice_number: 'INV-D04', recorded_by: 'Test' });
      assert(num(`SELECT COUNT(*) FROM charges WHERE xero_invoice_number='INV-D04'`) === 0, 'included domain invoiced');
      sql(`UPDATE services SET billable=0 WHERE id=${dBilled}`);
    });
    await test('D05', 'Domain fields: DNS host, name servers, auto-renew and provider reference save, show on the service page, audited and undoable; a forged auto-renew value is refused', async () => {
      await go(P, 'service-edit.php?id=' + dSoon);
      await P.fill('#dns_host', 'Cloudflare'); await P.fill('#nameservers', 'NS1.EXAMPLE.NET, ns2.example.net'); await P.selectOption('#auto_renew', '1'); await P.fill('#provider_ref', 'VP-12345'); await save(P);
      const r = sql(`SELECT dns_host,nameservers,auto_renew,provider_ref FROM services WHERE id=${dSoon}`)[0];
      assert(r.join('|') === 'Cloudflare|ns1.example.net, ns2.example.net|1|VP-12345', 'row ' + r);
      const t = await P.locator('main').innerText(); assert(/Auto-renew\s*Yes/.test(t) && t.includes('Cloudflare') && t.includes('VP-12345'), 'service page');
      await undoClick(P); assert(num(`SELECT dns_host IS NULL AND provider_ref IS NULL FROM services WHERE id=${dSoon}`) === 1, 'undo');
      await go(P, 'service-edit.php?id=' + dSoon); await P.evaluate(() => { const s = document.querySelector('#auto_renew'); s.appendChild(new Option('x', '7')); s.value = '7'; }); await save(P);
      assert(/Auto-renew/.test(await P.locator('.alert').innerText()), 'forged auto-renew accepted');
    });
    await test('D06', 'Set-up task: "Start set-up task" makes a task for the client from the checklist template, titled "Set up: <domain>", linked both ways; a second one is refused', async () => {
      await go(P, 'index.php?page=service&id=' + host);
      await clickIn(P, '[data-testid=provisioning] button[type=submit]');
      const tid = num(`SELECT COALESCE(provision_task_id,0) FROM services WHERE id=${host}`); assert(tid, 'not linked');
      const t = sql(`SELECT client_id,title,domains,status,LEFT(instructions,30) FROM tasks WHERE id=${tid}`)[0];
      assert(+t[0] === cid && t[1] === 'Set up: test-d-host.example.nz' && t[2] === 'test-d-host.example.nz' && t[3] === 'planned' && /Checklist/.test(t[4]), 'task ' + t);
      assert(/test-d-host/.test(await P.locator('[data-testid=sets-up]').innerText()), 'task page link');
      await go(P, 'index.php?page=service&id=' + host); assert(/Set up: test-d-host/.test(await P.locator('[data-testid=provisioning]').innerText()), 'service page link');
      const csrf = await csrfOf(P); await postAndGo(P, 'service-edit.php', { csrf, id: String(host), action: 'provision', template_id: one(`SELECT id FROM task_templates WHERE title='Set up hosting + domain'`) });
      assert(num(`SELECT COUNT(*) FROM tasks WHERE title='Set up: test-d-host.example.nz'`) === 1, 'second set-up task made');
    });
  }
  /* ================= v0.17: Xero draft invoices (against a local stand-in for Xero) ================= */
  {
    const { spawn } = require('child_process');
    const CFG = '/home/cloakecrea/cloake-manager/private/config.php', XJ = '/home/cloakecrea/cloake-manager/private/xero.json';
    const MLOG = path.join(OUT, 'xero-mock.log'); try { fs.unlinkSync(MLOG); fs.unlinkSync(MLOG + '.state.json'); } catch (e) { }
    try { fs.unlinkSync(XJ); } catch (e) { }
    const M = 'http://localhost:8099';
    const setCfg = extra => execFileSync('php', ['-r', '$f=$argv[1]; $c=require $f; foreach (["xero_endpoints","xero_redirect_uri"] as $k) unset($c[$k]); $c=array_merge($c, json_decode($argv[2], true)); file_put_contents($f, "<?php\nreturn ".var_export($c,true).";\n");', CFG, JSON.stringify(extra)]);
    setCfg({ xero_endpoints: { authorize: M + '/authorize', token: M + '/token', revoke: M + '/revoke', connections: M + '/connections', api: M + '/api' }, xero_redirect_uri: BASE + 'xero-callback.php' });
    const mock = spawn('php', ['-S', 'localhost:8099', path.join(__dirname, 'xero-mock.php')], { env: { ...process.env, XERO_MOCK_LOG: MLOG }, stdio: 'ignore' });
    for (let i = 0; i < 50; i++) { try { const r = await fetch(M + '/ping'); if (r.status === 404) break; } catch (e) { } await new Promise(r => setTimeout(r, 200)); }   // wait until the stand-in answers
    const mlog = () => { try { return fs.readFileSync(MLOG, 'utf8').trim().split('\n').filter(Boolean).map(l => JSON.parse(l)); } catch (e) { return []; } };
    const addDays = n => new Intl.DateTimeFormat('en-CA', { timeZone: 'Pacific/Auckland' }).format(new Date(Date.now() + n * 86400000));
    const xc = +one(`SELECT id FROM clients WHERE LOWER(COALESCE(status,''))<>'archive' AND (xero_contact_id IS NULL OR xero_contact_id='') ORDER BY id DESC LIMIT 1`);
    const svc = (domain, client, price) => { sql(`INSERT INTO services (client_id,kind,domain,status,billable,is_internal,price_ex_gst,cycle_months,next_period_start,source_system,created_at) VALUES (${client},'hosting','${domain}','active',1,0,${price},12,'${todayNZ}','app',NOW())`); return num(`SELECT id FROM services WHERE domain='${domain}'`); };
    const draftFor = async (domain, by = 'Test XD') => {
      await go(P, 'index.php?page=invoicing');
      const sec = P.locator('[data-testid=invoice-client]', { hasText: domain });
      for (const b of await sec.locator('input[name="keys[]"]').all()) await b.uncheck();
      await sec.locator('li.invoice-line', { hasText: domain }).locator('input[name="keys[]"]').check();
      await sec.locator('input[name=recorded_by]').fill(by);
      await Promise.all([P.waitForNavigation(), sec.locator('[data-testid=xero-draft]').click()]);
    };
    await test('XD01', 'Migration 008 (charges.xero_invoice_id); More lists Xero connection; not set up → no "Create Xero draft" button on Pending Invoicing', async () => {
      assert(num(`SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='charges' AND COLUMN_NAME='xero_invoice_id'`) === 1, 'column');
      await go(P, 'index.php?page=more'); assert(await P.locator('.more-list a[href="xero.php"]').count() === 1, 'not on More');
      // the PHP server may keep the previous config.php in its opcode cache for a moment: wait until it shows the test redirect address
      for (let i = 0; i < 40; i++) { await go(P, 'xero.php'); if ((await P.locator('[data-testid=redirect-uri]').innerText()) === BASE + 'xero-callback.php') break; await new Promise(r => setTimeout(r, 500)); }
      assert((await P.locator('[data-testid=redirect-uri]').innerText()) === BASE + 'xero-callback.php', 'test config not picked up');
      assert(/Not set up/.test(await P.locator('[data-testid=xero-status]').innerText()), 'status');
      await go(P, 'index.php?page=invoicing'); assert(await P.locator('[data-testid=xero-draft]').count() === 0, 'draft button without a connection');
    });
    await test('XD02', 'Xero app details: a bad client id is refused; saved to the private folder (mode 600, outside the website); the secret is never shown back or audited', async () => {
      await go(P, 'xero.php'); await P.fill('#client_id', 'bad id!'); await P.fill('#client_secret', 'mock-secret-0123456789abcdef'); await save(P);
      assert(/Client id/.test(await P.locator('.alert').innerText()) && !fs.existsSync(XJ), 'bad id accepted');
      await P.fill('#client_id', 'MOCKCLIENTID0000000000000000ABCD'); await P.fill('#client_secret', 'mock-secret-0123456789abcdef'); await save(P);
      assert(fs.existsSync(XJ) && (fs.statSync(XJ).mode & 0o777) === 0o600 && !XJ.includes('public_html'), 'file/mode');
      const html = await P.content(); assert(!html.includes('mock-secret-0123456789abcdef') && html.includes('(saved'), 'secret shown back');
      assert(!JSON.stringify(sql(`SELECT payload,summary FROM activity_events WHERE event_kind='xero_app_saved'`)).includes('mock-secret'), 'secret in audit');
    });
    await test('XD03', 'Connect: Xero sign-in (granular scopes) returns to Workdesk, which stores the connection to Demo Company; the sign-in link cannot be replayed', async () => {
      await go(P, 'xero.php');
      await Promise.all([P.waitForURL(/xero-callback\.php/), P.click('[data-testid=xero-connect]')]);
      assert(/Connected to Demo Company \(NZ\)/.test(await P.locator('[data-testid=xero-callback]').innerText()), 'callback');
      const auth = mlog().find(r => r.p === '/authorize'); assert(/offline_access/.test(auth.q.scope) && /accounting\.invoices/.test(auth.q.scope) && !/accounting\.transactions/.test(auth.q.scope), 'scopes ' + auth.q.scope);
      const replay = await P.request.get(P.url()); assert(replay.status() === 400, 'replay accepted ' + replay.status());
      await Promise.all([P.waitForNavigation(), P.click('a:has-text("Continue")')]);
      assert(/Connected to Demo Company \(NZ\)/.test(await P.locator('[data-testid=xero-status]').innerText()) && /Demo Company/.test(await P.locator('[data-testid=xero-status] .tag').innerText()), 'status');
      const j = JSON.parse(fs.readFileSync(XJ, 'utf8')); assert(j.refresh_token && j.tenant_id === '11111111-2222-3333-4444-555555555555' && !j.pending_state, 'stored');
    });
    let s1 = 0;
    await test('XD04', 'Create Xero draft: ticked line sent as ONE DRAFT (ex GST, account, contact by name in Demo Company, organisation header); Xero\'s number recorded; service moves on; Undo removes it here and says the draft stays in Xero', async () => {
      s1 = svc('test-xd04.example.nz', xc, 240);
      await draftFor('test-xd04.example.nz');
      assert(/Draft INV-9001 created in Xero/.test(await P.locator('[data-testid=draft-made]').innerText()), 'message');
      const post = mlog().filter(r => r.p === '/api/Invoices'); assert(post.length === 1, 'posts ' + post.length);
      const inv = JSON.parse(post[0].b).Invoices[0];
      assert(inv.Status === 'DRAFT' && inv.Type === 'ACCREC' && inv.LineAmountTypes === 'Exclusive' && inv.LineItems.length === 1 && inv.LineItems[0].UnitAmount === 240 && inv.LineItems[0].AccountCode === '43504' && /WEBSITE HOSTING - test-xd04\.example\.nz/.test(inv.LineItems[0].Description), 'payload ' + JSON.stringify(inv));
      assert(inv.Contact.Name === one(`SELECT name FROM clients WHERE id=${xc}`) && post[0].tenant === '11111111-2222-3333-4444-555555555555' && inv.DueDate === todayNZ, 'contact/tenant/due');
      const c = sql(`SELECT xero_invoice_number, LENGTH(xero_invoice_id), recorded_by, amount_ex_gst FROM charges WHERE source_kind='service' AND source_id=${s1}`);
      assert(c.length === 1 && c[0][0] === 'INV-9001' && c[0][1] === '36' && c[0][2] === 'Test XD' && +c[0][3] === 240, 'charge ' + c);
      assert(one(`SELECT next_period_start FROM services WHERE id=${s1}`) > todayNZ, 'service did not move on');
      await undoClick(P);
      assert(num(`SELECT COUNT(*) FROM charges WHERE source_kind='service' AND source_id=${s1}`) === 0 && one(`SELECT next_period_start FROM services WHERE id=${s1}`) === todayNZ, 'undo');
      assert(/still in Xero/.test(await P.locator('.alert').innerText()), 'no Xero reminder');
    });
    await test('XD05', 'If Xero refuses the draft, nothing is recorded (service stays due) and the reason is shown', async () => {
      sql(`INSERT INTO clients (source_system,source_id,name,status,created_at) VALUES ('app','test-xd05','Test Xero Fail','active',NOW())`); const fc = num(`SELECT MAX(id) FROM clients`);
      const s2 = svc('test-xd05.example.nz', fc, 50);
      const before = num(`SELECT COUNT(*) FROM charges`);
      await draftFor('test-xd05.example.nz');
      assert(/did not create the draft: Account code/.test(await P.locator('.alert').innerText()), 'reason not shown');
      assert(num(`SELECT COUNT(*) FROM charges`) === before && one(`SELECT next_period_start FROM services WHERE id=${s2}`) === todayNZ, 'something recorded');
    });
    await test('XD06', 'An expired access token is renewed (rotated refresh token saved); a dead connection asks to reconnect and records nothing; Disconnect removes access and the button', async () => {
      const j = JSON.parse(fs.readFileSync(XJ, 'utf8')); j.expires_at = 0; fs.writeFileSync(XJ, JSON.stringify(j)); const oldRefresh = j.refresh_token;
      await draftFor('test-xd04.example.nz');
      assert(/Draft INV-9002/.test(await P.locator('[data-testid=draft-made]').innerText()), 'not made after refresh');
      const j2 = JSON.parse(fs.readFileSync(XJ, 'utf8')); assert(j2.refresh_token !== oldRefresh && j2.expires_at > Date.now() / 1000, 'rotated token not saved');
      await undoClick(P);
      j2.expires_at = 0; j2.refresh_token = 'ref-dead'; fs.writeFileSync(XJ, JSON.stringify(j2));
      const before = num(`SELECT COUNT(*) FROM charges`);
      await draftFor('test-xd04.example.nz');
      assert(/connection has expired/.test(await P.locator('.alert').innerText()) && num(`SELECT COUNT(*) FROM charges`) === before, 'dead connection');
      await go(P, 'index.php?page=invoicing'); assert(await P.locator('[data-testid=xero-draft]').count() === 0, 'button while disconnected');
      // reconnect, then disconnect from the page
      await go(P, 'xero.php'); await Promise.all([P.waitForURL(/xero-callback/), P.click('[data-testid=xero-connect]')]); await Promise.all([P.waitForNavigation(), P.click('a:has-text("Continue")')]);
      await P.locator('details.danger-zone summary').click(); await Promise.all([P.waitForNavigation(), P.click('button.danger')]);
      assert(/Disconnected/.test(await P.locator('.success').innerText()) && !JSON.parse(fs.readFileSync(XJ, 'utf8')).refresh_token && mlog().some(r => r.p === '/revoke'), 'disconnect');
      await go(P, 'index.php?page=invoicing'); assert(await P.locator('[data-testid=xero-draft]').count() === 0, 'button after disconnect');
    });
    mock.kill(); try { fs.unlinkSync(XJ); } catch (e) { } setCfg({});
  }
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
  const summary = { database: DB, run_at: new Date().toISOString(), today_nz: todayNZ, build: VERSION_LABEL, passed, failed: results.length - passed, total: results.length, results };
  fs.writeFileSync(path.join(OUT, 'results.json'), JSON.stringify(summary, null, 2));
  const md = [`# Test run — ${DB}`, '', `Run ${summary.run_at} · ${VERSION_LABEL} · **${passed}/${results.length} passed**`, '', '| ID | Test | Result | Notes |', '|---|---|---|---|',
    ...results.map(r => `| ${r.id} | ${r.name} | ${r.ok ? 'PASS' : '**FAIL**'} (${r.ms} ms) | ${(r.ok ? r.notes.join('; ') : r.error).replace(/\|/g, '/').replace(/\n/g, '<br>')} |`)].join('\n');
  fs.writeFileSync(path.join(OUT, 'results.md'), md + '\n');
  console.log(`\n${passed}/${results.length} passed`);
  process.exit(passed === results.length ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
