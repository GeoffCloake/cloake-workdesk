#!/usr/bin/env python3
"""
Prepare the WHMCS import files for deploy/import-whmcs.php (v0.13). Runs on a PRIVATE machine, never the web server.

  python3 -I tools/prepare-whmcs-import.py --whmcs <dir with clients.csv services.csv domains.csv>
      --recon whmcs-reconciliation-2026-11-01-edited.xlsx --renewals whmcs-renewals-xero-2026-11-03.xlsx
      --xero SalesInvoices_....csv --out <new folder>

Writes to --out (all confidential; keep outside public_html):
  clients.csv services.csv domains.csv  WHMCS exports with the Password column REMOVED and note lines that look
                                         like logins/passwords replaced (counted in PREP_REPORT.txt)
  client-map.csv     whmcs_client_id,action(map|new|internal|skip),manager_client_id,new_client_name,contact_id
  items.csv          one row per LIVE WHMCS item: whmcs_table,whmcs_id,kind,billable,internal,next_period_start,
                     check_status,check_note,plan_name,xero_account
  xero-history.csv   Xero (WHMCS-series) invoice lines matched to items: whmcs_table,whmcs_id,invoice_number,invoice_date,
                     period_start,period_end,amount_ex_gst,status,description
  PREP_REPORT.txt    counts and anything that needs a look
Cathy's columns on the Renewals sheet are honoured: "Invoiced now? Yes" + invoice number records that period as invoiced
and moves the item on one cycle; "Correct period start" replaces the next period.
Needs: openpyxl.
"""
import argparse, csv, datetime as dt, os, re, sys, collections
import openpyxl

ap = argparse.ArgumentParser()
for a in ('whmcs', 'recon', 'renewals', 'xero', 'out'): ap.add_argument('--' + a, required=True)
A = ap.parse_args()
os.makedirs(A.out, exist_ok=False)
report = []
def say(s): report.append(s); print(s)

CRED = re.compile(r'pass|pwd|\bpw\b|log\s*in|user\s*(name)?\s*[:=]|ftp|cpanel|secret|\bpin\b', re.I)
def redact(text):
    if not text: return text, 0
    out, n = [], 0
    for line in str(text).splitlines():
        if CRED.search(line): out.append('[line removed by import: may contain a login]'); n += 1
        else: out.append(line)
    return '\n'.join(out), n

def read_whmcs(name):
    rows = list(csv.reader(open(os.path.join(A.whmcs, name), encoding='utf-8-sig')))
    if rows and sum(1 for c in rows[0] if c.strip()) == 1: rows = rows[1:]   # WHMCS title row
    return rows[0], rows[1:]

# ---- 1. cleaned WHMCS exports ----
dropped = collections.Counter(); redacted = collections.Counter(); W = {}
for f in ('clients.csv', 'services.csv', 'domains.csv'):
    h, rows = read_whmcs(f)
    keep = [i for i, c in enumerate(h) if c.strip().lower() not in ('password', 'password hash', 'card number', 'card type', 'card expiry', 'bank account', 'cvv')]
    for i, c in enumerate(h):
        if i not in keep: dropped[f + ':' + c] = len(rows)
    ni = h.index('Notes') if 'Notes' in h else None
    out = []
    for r in rows:
        r = r + [''] * (len(h) - len(r))
        if ni is not None:
            r[ni], n = redact(r[ni]); redacted[f] += n
        out.append([r[i] for i in keep])
    hh = [h[i] for i in keep]
    with open(os.path.join(A.out, f), 'w', newline='', encoding='utf-8') as fh:
        w = csv.writer(fh); w.writerow(hh); w.writerows(out)
    W[f] = [dict(zip(hh, r)) for r in out]
    say(f'{f}: {len(out)} rows; removed columns: {", ".join(c for c in h if h.index(c) not in keep) or "none"}; note lines removed: {redacted[f]}')

# ---- 2. client decisions ----
wb = openpyxl.load_workbook(A.recon, data_only=True)
ws = wb['Client matches']
cmap = []
for r in ws.iter_rows(min_row=6, values_only=True):
    if r[0] is None: continue
    wid, match, sugg, dec, note = str(r[0]), r[4], r[5], (str(r[14]).strip() if r[14] is not None else ''), (str(r[15]).strip() if r[15] is not None else '')
    ids = re.findall(r'#(\d+)', dec)
    if dec.lower() == 'accept' or (dec == '' and match == 'exact'): row = (wid, 'map', str(sugg), '', '')
    elif ids: row = (wid, 'map', ids[0], '', ids[1] if len(ids) > 1 else '')
    elif dec == '?' or dec == '': row = (wid, 'skip', '', '', '')
    elif dec.lower() == 'cloake creative': row = (wid, 'internal', '1', '', '')
    elif 'add to clients' in note.lower(): row = (wid, 'new', '', dec, '')
    else: say(f'!! client {wid}: decision "{dec}" not understood — skipped'); row = (wid, 'skip', '', '', '')
    cmap.append(row)
with open(os.path.join(A.out, 'client-map.csv'), 'w', newline='', encoding='utf-8') as fh:
    w = csv.writer(fh); w.writerow(['whmcs_client_id', 'action', 'manager_client_id', 'new_client_name', 'contact_id']); w.writerows(cmap)
say('client-map: ' + ', '.join(f'{k} {v}' for k, v in collections.Counter(r[1] for r in cmap).items()))
CM = {r[0]: r for r in cmap}

# ---- 3. Xero lines (WHMCS series: CC-, plain-numbered and unnumbered) ----
X = list(csv.DictReader(open(A.xero, encoding='utf-8-sig')))
P = lambda s: dt.datetime.strptime(s.strip(), '%d/%m/%Y').date()
PER = re.compile(r'\((\d{1,2}/\d{1,2}/\d{4})\s*-\s*(\d{1,2}/\d{1,2}/\d{4})\)')
inv_tot = collections.defaultdict(lambda: [0.0, 0.0, 0.0])
for x in X:
    t = inv_tot[(x['InvoiceNumber'], x['InvoiceDate'], x['ContactName'])]; t[0] += float(x['LineAmount'] or 0); t[1] = float(x['TaxTotal'] or 0); t[2] = float(x['Total'] or 0)
def ex_amount(x):
    t = inv_tot[(x['InvoiceNumber'], x['InvoiceDate'], x['ContactName'])]
    la = float(x['LineAmount'] or 0)
    return round(la if abs(t[0] + t[1] - t[2]) < 0.05 else la - float(x['TaxAmount'] or 0), 2)   # lines ex GST, else inclusive
xw = [x for x in X if not x['InvoiceNumber'].upper().startswith('INV')]

def xero_lines_for(table, domain):
    d = domain.lower().strip(); out = []
    for x in xw:
        desc = x['Description']; low = desc.lower(); head = low.split('(')[0]
        if table == 'domains': ok = re.match(r'domain (renewal|registration) - ' + re.escape(d) + r'\b', low) is not None
        else: ok = low.startswith(('website hosting', 'website host', 'email only', 'dns and forwarding')) and re.search(r'(^|[\s-])' + re.escape(d) + r'(\s|$)', head) is not None
        m = PER.search(desc)
        if ok and m: out.append((P(m.group(1)), P(m.group(2)), x))
    return sorted(out, key=lambda t: (t[0], t[2]['InvoiceDate']))

# ---- 4. items ----
ws = wb['Service allocation']
alloc = {}
for r in ws.iter_rows(min_row=5, values_only=True):
    if r[1] is None: continue
    alloc[('services' if r[0] == 'service' else 'domains', str(r[1]))] = {'live': r[4] == 'yes', 'owner': str(r[12]) if r[12] is not None else '', 'decision': r[17], 'domain': r[2]}
wr = openpyxl.load_workbook(A.renewals, data_only=True)
ren = {}
hdr = [c.value for c in wr['Renewals'][4]]
col = {n: i for i, n in enumerate(hdr) if n}
def cidx(prefix): return next(i for n, i in col.items() if str(n).startswith(prefix))
for r in wr['Renewals'].iter_rows(min_row=5, values_only=True):
    if not r[7]: continue
    key = ('services' if str(r[7])[0] == 'S' else 'domains', str(r[7])[1:])
    ren[key] = r
nochg = {}
for r in wr['No charge & check'].iter_rows(min_row=5, values_only=True):
    if not r[4]: continue
    nochg[('services' if str(r[4])[0] == 'S' else 'domains', str(r[4])[1:])] = r

def d(v):
    if v is None or v == '': return ''
    if isinstance(v, (dt.datetime, dt.date)): return (v.date() if isinstance(v, dt.datetime) else v).isoformat()
    s = str(v).strip()
    for f in ('%Y-%m-%d %H:%M:%S', '%Y-%m-%d', '%d/%m/%Y'):
        try: return dt.datetime.strptime(s, f).date().isoformat()
        except ValueError: pass
    return ''
def add_months(iso, n):
    y, m, dd = map(int, iso.split('-')); m += n; y += (m - 1) // 12; m = (m - 1) % 12 + 1
    import calendar; return dt.date(y, m, min(dd, calendar.monthrange(y, m)[1])).isoformat()
CYC = {'Monthly': 1, 'Quarterly': 3, 'Semi-Annually': 6, 'Annually': 12, 'Biennially': 24}
def check_of(text):
    t = str(text or '')
    if t.startswith('WHMCS and Xero agree'): return ('price_differs' if 'amount' in t.lower() else 'agrees'), t
    if t.startswith('No Xero invoice'): return 'no_invoice', t
    if t.startswith('Xero ahead'): return 'xero_ahead', t
    if t.startswith('WHMCS shows'): return 'whmcs_ahead', t
    return ('', t)

items, hist = [], []
mism, cathy, nohist = [], 0, 0
wid_rows = {('services', r['ID']): r for r in W['services.csv']} | {('domains', r['ID']): r for r in W['domains.csv']}
for key, a in sorted(alloc.items(), key=lambda kv: (kv[0][0], int(kv[0][1]))):
    if not a['live']: continue
    wr_ = wid_rows.get(key)
    if not wr_: say(f'!! live item {key} not in the WHMCS export'); continue
    owner = wr_.get('User ID', '')
    cm = CM.get(owner)
    if not cm or cm[1] == 'skip': continue           # skipped clients: archive only
    table, wid = key
    domain = (wr_.get('Domain Name') or a['domain'] or '').strip()
    internal = 1 if (a['decision'] == 'internal' or cm[1] == 'internal') else 0
    kind = 'domain' if table == 'domains' else 'hosting'
    plan = ''; account = '43601' if table == 'domains' else '43504'
    lines = xero_lines_for(table, domain) if domain else []
    if lines:
        last = lines[-1][2]['Description']
        low = last.lower()
        if low.startswith('email only'): kind = 'email'
        elif low.startswith('dns and forwarding'): kind = 'dns'
        m = re.match(r'WEBSITE HOSTING - (.+?) - ' + re.escape(domain), last, re.I)
        if m: plan = m.group(1).strip()
    if table == 'services':
        cyc = CYC.get(wr_.get('Billing Cycle', ''), 0); whmcs_next = d(wr_.get('Next Due Date'))
    else:
        cyc = 12 * max(1, int(wr_.get('Registration Period') or 1)); whmcs_next = d(wr_.get('Expiry Date'))
    billable, nxt, chk, note = 0, whmcs_next, '', ''
    r = ren.get(key)
    if r and not internal:
        billable = 1; nxt = d(r[1]); chk, note = check_of(r[19])
        last_inv = str(r[15] or '')
        if last_inv and lines and lines[-1][2]['InvoiceNumber'] != last_inv and not any(l[2]['InvoiceNumber'] == last_inv for l in lines):
            mism.append(f'{table[0].upper()}{wid} {domain}: sheet says last invoice {last_inv}, matched {lines[-1][2]["InvoiceNumber"] or "(no number)"}')
        # Cathy's columns
        inv_now = str(r[cidx('Invoiced now')] or '').strip().lower(); inv_no = str(r[cidx('Xero invoice no')] or '').strip()
        corr = d(r[col['Correct period start']]) if 'Correct period start' in col else ''
        if corr: nxt = corr
        if inv_now.startswith('y') and inv_no and nxt and cyc:
            end = (dt.date.fromisoformat(add_months(nxt, cyc)) - dt.timedelta(days=1)).isoformat()
            hist.append([table, wid, inv_no, dt.date.today().isoformat(), nxt, end, f'{float(r[10] or 0):.2f}', 'Recorded by Cathy (workbook)', str(r[20] or '')[:500]])
            nxt = add_months(nxt, cyc); cathy += 1
    elif key in nochg and not internal:
        n = nochg[key]; dec = str(n[11] or '').strip().lower()
        price = float(n[7] or 0)
        if price > 0 and not cyc: chk, note = 'decide', 'Priced in WHMCS but no billing cycle — bill or not? ' + str(n[12] or '')
        if dec.startswith('bill'): billable = 1
    for (ps, pe, x) in lines:
        hist.append([table, wid, x['InvoiceNumber'], P(x['InvoiceDate']).isoformat(), ps.isoformat(), pe.isoformat(), f'{ex_amount(x):.2f}', x['Status'], x['Description'][:500]])
    if billable and not lines: nohist += 1
    items.append([table, wid, kind, billable, internal, nxt, chk, note[:500], plan[:120], account])

with open(os.path.join(A.out, 'items.csv'), 'w', newline='', encoding='utf-8') as fh:
    w = csv.writer(fh); w.writerow(['whmcs_table', 'whmcs_id', 'kind', 'billable', 'internal', 'next_period_start', 'check_status', 'check_note', 'plan_name', 'xero_account']); w.writerows(items)
# one row per item + period (a duplicate Xero line for the same period, e.g. "PAID TWICE", is reported, first kept)
seen, dup, H = set(), [], []
for h in hist:
    k = (h[0], h[1], h[4])
    if k in seen: dup.append(f'{h[0][0].upper()}{h[1]} period {h[4]} invoice {h[2] or "(no number)"}'); continue
    seen.add(k); H.append(h)
with open(os.path.join(A.out, 'xero-history.csv'), 'w', newline='', encoding='utf-8') as fh:
    w = csv.writer(fh); w.writerow(['whmcs_table', 'whmcs_id', 'invoice_number', 'invoice_date', 'period_start', 'period_end', 'amount_ex_gst', 'status', 'description']); w.writerows(H)

c = collections.Counter((i[0], i[3], i[4]) for i in items)
say(f'items (live, not skipped): {len(items)} — billable {sum(1 for i in items if i[3])}, internal {sum(1 for i in items if i[4])}, no charge {sum(1 for i in items if not i[3] and not i[4])}')
say('kinds: ' + ', '.join(f'{k} {v}' for k, v in collections.Counter(i[2] for i in items).items()))
say('check: ' + ', '.join(f'{k or "-"} {v}' for k, v in collections.Counter(i[6] for i in items).items()))
say(f'xero-history: {len(H)} invoiced periods matched to {len({(h[0], h[1]) for h in H})} items; billable items with no Xero line: {nohist}; recorded by Cathy in the workbook: {cathy}')
if dup: say('duplicate Xero periods (first kept): ' + '; '.join(dup))
if mism: say('last-invoice differences vs the workbook (check): ' + '; '.join(mism))
open(os.path.join(A.out, 'PREP_REPORT.txt'), 'w').write('\n'.join(report) + '\n')
