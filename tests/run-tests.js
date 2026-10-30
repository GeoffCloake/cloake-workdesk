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
  const outp = execFileSync('mysql', ['-N', '-B', '--raw', DB], { input: query, encoding: 'utf8' });
  return outp.split('\n').filter(l => l.length).map(l => l.split('\t').map(v => v === 'NULL' ? null : v));
}
const one = q => (sql(q)[0] || [])[0];
const num = q => parseInt(one(q) || '0', 10);
const esc = s => String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
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
       AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(k.legacy_record,'$.status')),'')<>'archive'
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
  return s;
}
/* checksums of data that must never change */
function protectedChecksums() {
  const rowsum = (table, cols, where = '') => one(`SELECT CONCAT(COUNT(*),':',COALESCE(SUM(CRC32(CONCAT_WS('|',${cols}))),0)) FROM ${table} ${where}`);
  const out = {
    clients: rowsum('clients', 'id,source_system,source_id,name,COALESCE(status,""),COALESCE(xero_contact_id,""),COALESCE(CAST(legacy_record AS CHAR),"")'),
    contacts: rowsum('contacts', 'id,COALESCE(client_id,""),source_id,COALESCE(display_name,""),COALESCE(email,""),COALESCE(phone,""),COALESCE(CAST(legacy_record AS CHAR),"")'),
    fabrik_tasks: rowsum('tasks', 'id,COALESCE(client_id,""),title,COALESCE(status,""),COALESCE(required_by,""),COALESCE(completed_at,""),COALESCE(notes,""),COALESCE(instructions,""),COALESCE(CAST(legacy_record AS CHAR),"")', "WHERE source_system='fabrik'"),
    fabrik_work: rowsum('work_log', 'id,COALESCE(client_id,""),COALESCE(task_id,""),details,duration_seconds,billable_seconds,COALESCE(billability,""),COALESCE(work_date,""),COALESCE(CAST(legacy_record AS CHAR),"")', "WHERE source_system='fabrik'"),
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
    'add-work.php', 'add-work.php?client_id=' + S.client.id, 'add-enquiry.php', 'convert-enquiry.php?id=1', 'edit-task.php?id=2048', 'edit-enquiry.php?id=1'];
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
        if (!/v0\.9\.0 · build/.test(m.version)) issues.push(`${u}: version label missing`);
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
    const dbActive = num(`SELECT COUNT(*) FROM contacts WHERE client_id=${S.client.id} AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(legacy_record,'$.status')),'')<>'archive'`);
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
  await test('D02', 'Imported (Fabrik) tasks stay read-only, even to a forged POST', async () => {
    await go(P, 'edit-task.php?id=' + S.fabrikTask);
    assert(await P.locator('form.form').count() === 0 && /read-only/.test(await P.locator('main').innerText()), 'edit form offered');
    const before = one(`SELECT CRC32(CONCAT_WS('|',title,COALESCE(status,''),COALESCE(instructions,''))) FROM tasks WHERE id=${S.fabrikTask}`);
    await go(P, 'edit-task.php?id=2048'); const csrf = await P.inputValue('input[name=csrf]').catch(() => null);
    const tok = csrf || await P.evaluate(() => (document.querySelector('input[name=csrf]') || {}).value);
    const r = await P.request.post(BASE + 'edit-task.php', { form: { id: String(S.fabrikTask), csrf: tok, title: 'HACKED', status: 'completed', priority: 'low' } });
    const body = await r.text();
    assert(/read-only/.test(body), 'no read-only error in response');
    assert(one(`SELECT CRC32(CONCAT_WS('|',title,COALESCE(status,''),COALESCE(instructions,''))) FROM tasks WHERE id=${S.fabrikTask}`) === before, 'imported task changed!');
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
    assert(/v0\.9\.0 · build 20261030\.3/.test(await P.locator('[data-testid=check-version]').innerText()), 'version');
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
  await test('P01', 'Imported clients, contacts, tasks, work entries and all six legacy archives are byte-for-byte unchanged', async () => {
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
