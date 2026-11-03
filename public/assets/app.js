/* Cloake Client & Services Manager — v0.9.0 front-end.
   Progressive enhancement only: every page works without this file.
   No inline JS anywhere (CSP script-src 'self'). */
(function () {
  'use strict';
  document.documentElement.classList.add('js');
  var $ = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };
  var store = {
    get: function (k, d) { try { var v = localStorage.getItem(k); return v ? JSON.parse(v) : d; } catch (e) { return d; } },
    set: function (k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* private mode */ } },
    del: function (k) { try { localStorage.removeItem(k); } catch (e) { } }
  };
  function el(tag, attrs, children) {
    var n = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) {
      if (k === 'text') n.textContent = attrs[k]; else if (k === 'cls') n.className = attrs[k]; else n.setAttribute(k, attrs[k]);
    });
    (children || []).forEach(function (c) { if (c != null) n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return n;
  }
  var BUILD = (document.querySelector('meta[name="cloake-build"]') || {}).content || '';
  // After any successful save, drop the cached client index so edits show up in the finder at once.
  if (document.querySelector('.success[role="status"]') || /[?&]saved=1\b/.test(location.search)) { try { sessionStorage.removeItem('cm_client_index'); } catch (e) { } }

  /* ---------------- client index (shared by finder and pickers) ---------------- */
  var indexPromise = null;
  function norm(s) { return (s || '').toString().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }
  function digits(s) { return (s || '').toString().replace(/\D+/g, ''); }
  function loadIndex() {
    if (indexPromise) return indexPromise;
    var cacheKey = 'cm_client_index';
    try {
      var cached = JSON.parse(sessionStorage.getItem(cacheKey) || 'null');
      if (cached && cached.build === BUILD && Date.now() - cached.at < 10 * 60 * 1000) { indexPromise = Promise.resolve(prepare(cached.clients)); return indexPromise; }
    } catch (e) { }
    indexPromise = fetch('lookup.php?clients=1', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('lookup ' + r.status); return r.json(); })
      .then(function (data) {
        try { sessionStorage.setItem(cacheKey, JSON.stringify({ build: BUILD, at: Date.now(), clients: data.clients })); } catch (e) { }
        return prepare(data.clients);
      })
      .catch(function (err) { indexPromise = null; throw err; });
    return indexPromise;
  }
  function prepare(clients) {
    return clients.map(function (c) {
      c._name = norm(c.name); c._alt = norm(c.alt); c._town = norm(c.town); c._e = norm(c.e);
      c._cn = (c.c || []).map(function (k) { return { n: norm(k.n), e: norm(k.e), p: digits(k.p), raw: k }; });
      c._p = digits(c.p); c._arch = norm(c.st) === 'archive';
      return c;
    });
  }
  /* Rank clients for a query. Every word must match somewhere. */
  function search(index, q, limit) {
    var words = norm(q).split(/\s+/).filter(Boolean);
    if (!words.length) return { list: [], total: 0 };
    var qd = digits(q);
    var out = [];
    index.forEach(function (c) {
      var score = 0, why = null, ok = true;
      words.forEach(function (w) {
        if (!ok) return;
        var s = 0, reason = null;
        if (c._name.indexOf(w) === 0) s = 100;
        else if ((' ' + c._name).indexOf(' ' + w) >= 0) s = 80;
        else if (c._name.indexOf(w) >= 0) s = 60;
        else if (c._alt && c._alt.indexOf(w) >= 0) { s = 50; reason = c.alt; }
        else if (c.sid && c.sid === w) { s = 45; reason = 'Legacy ID ' + c.sid; }
        else {
          for (var i = 0; i < c._cn.length; i++) {
            var k = c._cn[i];
            if (k.n && ((' ' + k.n).indexOf(' ' + w) >= 0 || (w.length > 2 && k.n.indexOf(w) >= 0))) { s = 40; reason = k.raw.n + (k.raw.e ? ' · ' + k.raw.e : ''); break; }
            if (k.e && w.length > 2 && k.e.indexOf(w) >= 0) { s = 35; reason = k.raw.n + ' · ' + k.raw.e; break; }
          }
          if (!s && c._e && w.length > 2 && c._e.indexOf(w) >= 0) { s = 35; reason = c.e; }
          if (!s && c._town && c._town.indexOf(w) === 0) { s = 20; reason = c.town; }
        }
        if (!s && qd.length >= 4) {
          if (c._p.indexOf(qd) >= 0) { s = 30; reason = c.p; }
          else for (var j = 0; j < c._cn.length; j++) if (c._cn[j].p.indexOf(qd) >= 0) { s = 30; reason = c._cn[j].raw.n + ' · ' + c._cn[j].raw.p; break; }
        }
        if (!s) ok = false; else { score += s; if (reason && !why) why = reason; }
      });
      if (ok) out.push({ c: c, score: score - (c._arch ? 15 : 0), why: why });
    });
    out.sort(function (a, b) { return b.score - a.score || a.c.name.localeCompare(b.c.name); });
    return { list: out.slice(0, limit || 8), total: out.length };
  }
  function highlight(text, q) {
    var frag = document.createDocumentFragment();
    var words = norm(q).split(/\s+/).filter(Boolean), t = text || '', nt = norm(t), pos = -1, len = 0;
    words.forEach(function (w) { var i = nt.indexOf(w); if (i >= 0 && (pos < 0 || i < pos)) { pos = i; len = w.length; } });
    if (pos < 0) { frag.appendChild(document.createTextNode(t)); return frag; }
    frag.appendChild(document.createTextNode(t.slice(0, pos)));
    frag.appendChild(el('mark', { text: t.slice(pos, pos + len) }));
    frag.appendChild(document.createTextNode(t.slice(pos + len)));
    return frag;
  }
  function subline(c, why) { return why || [c.alt && c.alt !== c.name ? c.alt : '', c.town].filter(Boolean).join(' · ') || ('Legacy ID ' + c.sid); }

  /* ---------------- recent clients ---------------- */
  var RECENT = 'cm_recent_clients';
  function rememberClient(id, name) {
    var list = store.get(RECENT, []).filter(function (r) { return r.id !== id; });
    list.unshift({ id: id, name: name }); store.set(RECENT, list.slice(0, 6));
  }
  var head = $('[data-recent-client]');
  if (head) rememberClient(parseInt(head.getAttribute('data-recent-client'), 10), head.getAttribute('data-name') || '');
  $$('[data-recent-clients]').forEach(function (box) {
    var list = store.get(RECENT, []);
    if (!list.length) return;
    list.forEach(function (r) { box.appendChild(el('a', { href: 'index.php?page=client&id=' + encodeURIComponent(r.id), text: r.name })); });
    box.hidden = false;
  });

  /* ---------------- instant finder ---------------- */
  $$('[data-finder]').forEach(function (form) {
    var input = $('[data-finder-input]', form), box = $('[data-finder-results]', form);
    if (!input || !box) return;
    var active = -1, items = [], lastQ = '';
    function close() { box.hidden = true; active = -1; input.setAttribute('aria-expanded', 'false'); }
    function setActive(i) {
      items.forEach(function (it, n) { it.setAttribute('aria-selected', n === i ? 'true' : 'false'); });
      active = i; if (items[i]) { input.setAttribute('aria-activedescendant', items[i].id); items[i].scrollIntoView({ block: 'nearest' }); }
    }
    function render(q) {
      lastQ = q;
      if (!q.trim()) { box.replaceChildren(); close(); return; }
      loadIndex().then(function (index) {
        if (q !== lastQ) return;
        var res = search(index, q, 8);
        box.replaceChildren(); items = [];
        if (!res.list.length) box.appendChild(el('div', { cls: 'fr-empty', text: 'No client matches “' + q + '”. Try a contact name, email or phone digits.' }));
        res.list.forEach(function (r, n) {
          var a = el('a', { cls: 'fr-item', href: 'index.php?page=client&id=' + r.c.id, role: 'option', id: 'fr-' + n, 'aria-selected': 'false' });
          var left = el('span'); var strong = el('strong'); strong.appendChild(highlight(r.c.name, q));
          if (r.c._arch) { strong.appendChild(document.createTextNode(' ')); strong.appendChild(el('em', { cls: 'tag', text: 'Archived' })); }
          var small = el('small'); small.appendChild(highlight(subline(r.c, r.why), q));
          left.appendChild(strong); left.appendChild(small);
          a.appendChild(left); a.appendChild(el('span', { cls: 'fr-go', 'aria-hidden': 'true', text: '›' }));
          a.addEventListener('click', function () { rememberClient(r.c.id, r.c.name); });
          box.appendChild(a); items.push(a);
        });
        if (res.total > res.list.length) box.appendChild(el('a', { cls: 'fr-more', href: 'index.php?page=clients&show=all&q=' + encodeURIComponent(q), text: 'See all ' + res.total + ' matches ›' }));
        box.hidden = false; input.setAttribute('aria-expanded', 'true');
        if (items.length) setActive(0);
      }).catch(function () { close(); /* fall back to normal form submit */ });
    }
    input.setAttribute('role', 'combobox'); input.setAttribute('aria-expanded', 'false');
    input.addEventListener('input', function () { render(input.value); });
    input.addEventListener('focus', function () { loadIndex().catch(function () { }); if (input.value.trim()) render(input.value); });
    input.addEventListener('keydown', function (e) {
      if (box.hidden) return;
      if (e.key === 'ArrowDown') { e.preventDefault(); setActive(Math.min(active + 1, items.length - 1)); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(Math.max(active - 1, 0)); }
      else if (e.key === 'Escape') { close(); }
      else if (e.key === 'Enter' && items[active]) { e.preventDefault(); items[active].click(); window.location.href = items[active].href; }
    });
    document.addEventListener('click', function (e) { if (!form.contains(e.target)) close(); });
    var wantFocus = input.hasAttribute('data-finder-autofocus') || (window.matchMedia && window.matchMedia('(pointer:fine)').matches && !input.value);
    if (wantFocus && !document.querySelector('.alert')) { input.focus({ preventScroll: true }); }
  });

  /* ---------------- client activity filter ---------------- */
  var tf = $('[data-timeline-filter]'), tl = $('[data-timeline]');
  if (tf && tl) {
    tf.hidden = false;
    $$('button', tf).forEach(function (b) {
      b.addEventListener('click', function () {
        var k = b.getAttribute('data-k');
        $$('button', tf).forEach(function (x) { var on = x === b; x.classList.toggle('on', on); x.setAttribute('aria-pressed', on ? 'true' : 'false'); });
        $$('.tl-item', tl).forEach(function (li) { li.hidden = k !== 'all' && li.getAttribute('data-k') !== k; });
      });
    });
  }

  /* ---------------- client type-ahead for forms ---------------- */
  $$('[data-client-picker]').forEach(function (wrap) {
    var select = $('[data-client-select]', wrap);
    if (!select) return;
    var ta = el('div', { cls: 'ta' });
    var chosen = el('div', { cls: 'ta-chosen' });
    var chosenName = el('span'); var change = el('button', { type: 'button', text: 'Change' });
    chosen.appendChild(chosenName); chosen.appendChild(change);
    var input = el('input', { type: 'search', cls: 'ta-input', placeholder: 'Type a client, contact or phone…', autocomplete: 'off', autocapitalize: 'off', spellcheck: 'false', 'aria-label': 'Find client' });
    var box = el('div', { cls: 'finder-results', role: 'listbox' }); box.hidden = true;
    ta.appendChild(chosen); ta.appendChild(input); ta.appendChild(box);
    select.hidden = true; select.setAttribute('aria-hidden', 'true'); select.tabIndex = -1;
    // the visible label should now point at the type-ahead input
    if (select.id) { var lbl = document.querySelector('label[for="' + select.id + '"]'); if (lbl) { input.id = select.id + '-find'; lbl.setAttribute('for', input.id); } }
    wrap.appendChild(ta);
    function showChosen() {
      var opt = select.options[select.selectedIndex];
      if (select.value && opt) { chosenName.textContent = opt.textContent; chosen.hidden = false; input.hidden = true; }
      else { chosen.hidden = true; input.hidden = false; }
    }
    function pick(id) {
      select.value = String(id); showChosen(); box.hidden = true; input.value = '';
      select.dispatchEvent(new Event('change', { bubbles: true }));
    }
    change.addEventListener('click', function () { select.value = ''; showChosen(); select.dispatchEvent(new Event('change', { bubbles: true })); input.focus(); });
    var active = -1, items = [];
    input.addEventListener('focus', function () { loadIndex().catch(function () { select.hidden = false; ta.hidden = true; }); });
    input.addEventListener('input', function () {
      var q = input.value;
      if (!q.trim()) { box.hidden = true; return; }
      loadIndex().then(function (index) {
        if (input.value !== q) return;
        var res = search(index, q, 7); box.replaceChildren(); items = []; active = -1;
        if (!res.list.length) box.appendChild(el('div', { cls: 'fr-empty', text: 'No match.' }));
        res.list.forEach(function (r, n) {
          var b = el('a', { cls: 'fr-item', href: '#', role: 'option', 'aria-selected': n === 0 ? 'true' : 'false' });
          var left = el('span'); var s = el('strong'); s.appendChild(highlight(r.c.name, q)); left.appendChild(s);
          var sm = el('small'); sm.appendChild(highlight(subline(r.c, r.why), q)); left.appendChild(sm); b.appendChild(left);
          b.addEventListener('click', function (e) { e.preventDefault(); pick(r.c.id); });
          box.appendChild(b); items.push(b);
        });
        active = items.length ? 0 : -1; box.hidden = false;
      }).catch(function () { select.hidden = false; ta.hidden = true; });
    });
    input.addEventListener('keydown', function (e) {
      if (box.hidden || !items.length) { if (e.key === 'Enter') e.preventDefault(); return; }
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault(); active = Math.max(0, Math.min(items.length - 1, active + (e.key === 'ArrowDown' ? 1 : -1)));
        items.forEach(function (it, n) { it.setAttribute('aria-selected', n === active ? 'true' : 'false'); });
      } else if (e.key === 'Enter') { e.preventDefault(); if (items[active]) items[active].click(); }
      else if (e.key === 'Escape') { box.hidden = true; }
    });
    select.addEventListener('cm-sync', showChosen);
    // A hidden required <select> would silently block submit; check it ourselves instead.
    if (select.required) {
      select.required = false;
      var msg = el('p', { cls: 'form-error', role: 'alert', text: 'Choose a client from the list.' }); msg.hidden = true; wrap.appendChild(msg);
      var form = select.form;
      if (form) form.addEventListener('submit', function (e) { if (!select.value) { e.preventDefault(); msg.hidden = false; input.hidden = false; chosen.hidden = true; input.focus(); } });
      select.addEventListener('change', function () { if (select.value) msg.hidden = true; });
    }
    showChosen();
  });

  /* ---------------- Log work form ---------------- */
  var wf = $('[data-work-form]');
  if (wf) {
    var taskSel = $('[data-task-select]', wf), clientSel = $('[data-client-select]', wf), help = $('[data-task-help]', wf);
    // Task list follows the chosen client.
    if (clientSel && taskSel) clientSel.addEventListener('change', function () {
      var cid = clientSel.value;
      if (!cid) { if (help) help.textContent = 'Choose a client to see only their tasks, or search all tasks.'; return; }
      fetch('lookup.php?tasks_for=' + encodeURIComponent(cid), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (data) {
        var keep = taskSel.value; taskSel.replaceChildren(el('option', { value: '', text: 'Unallocated — assign later' }));
        (data.tasks || []).forEach(function (t) {
          taskSel.appendChild(el('option', { value: String(t.id), text: (t.closed ? '[closed] ' : '') + (t.sid && t.sid !== String(t.id) ? '#' + t.sid : 'new #' + t.id) + ' · ' + t.title }));
        });
        taskSel.value = Array.prototype.some.call(taskSel.options, function (o) { return o.value === keep; }) ? keep : '';
        if (help) help.textContent = (data.tasks || []).length ? 'This client’s tasks, open first.' : 'No tasks for this client yet — the entry will be logged against the client.';
      }).catch(function () { });
    });
    // Search all tasks.
    var ts = $('[data-task-search]', wf);
    if (ts && taskSel) {
      ts.hidden = false;
      var tin = $('input', ts), tbox = $('[data-task-results]', ts), timer = null;
      tin.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
      tin.addEventListener('input', function () {
        clearTimeout(timer); var q = tin.value.trim();
        if (q.length < 2) { tbox.hidden = true; return; }
        timer = setTimeout(function () {
          fetch('lookup.php?task_q=' + encodeURIComponent(q), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (data) {
            if (tin.value.trim() !== q) return;
            tbox.replaceChildren();
            if (!(data.tasks || []).length) tbox.appendChild(el('div', { cls: 'fr-empty', text: 'No tasks match.' }));
            (data.tasks || []).forEach(function (t) {
              var a = el('a', { cls: 'fr-item', href: '#' });
              a.appendChild(el('span', null, [el('strong', { text: (t.closed ? '[closed] ' : '') + t.title }), el('small', { text: t.client + ' · ' + t.status + ' · #' + t.sid })]));
              a.addEventListener('click', function (e) {
                e.preventDefault();
                var exists = Array.prototype.some.call(taskSel.options, function (o) { return o.value === String(t.id); });
                if (!exists) taskSel.appendChild(el('option', { value: String(t.id), text: '#' + t.sid + ' · ' + t.title + ' · ' + t.client }));
                taskSel.value = String(t.id);
                // The server takes the client from the task; clear a conflicting client choice.
                if (clientSel && clientSel.value) { clientSel.value = ''; clientSel.dispatchEvent(new Event('cm-sync')); }
                if (help) help.textContent = 'Task chosen from search. Its client is recorded automatically.';
                tbox.hidden = true; tin.value = '';
              });
              tbox.appendChild(a);
            });
            tbox.hidden = false;
          }).catch(function () { });
        }, 180);
      });
    }
    // Duration presets.
    var presets = $('[data-presets]', wf), H = $('#hours', wf), M = $('#minutes', wf), S = $('#seconds', wf);
    if (presets) {
      presets.hidden = false;
      $$('button', presets).forEach(function (b) {
        b.addEventListener('click', function () {
          var m = parseInt(b.getAttribute('data-min'), 10); H.value = Math.floor(m / 60); M.value = m % 60; if (S) S.value = 0;
          $$('button', presets).forEach(function (x) { x.classList.toggle('on', x === b); });
        });
      });
    }
    // Mode switch incl. timer.
    $$('[data-js-only]', wf).forEach(function (n) { n.hidden = false; });
    var secDur = $('#m5-duration'), secTimes = $('#m5-times'), secTimer = $('#m5-timer');
    if (secDur && secTimes && secTimer) {
    function mode() { var r = $('input[name="mode"]:checked', wf); return r ? r.value : 'duration'; }
    function syncMode() { var m = mode(); secDur.hidden = m !== 'duration'; secTimes.hidden = m !== 'times'; secTimer.hidden = m !== 'timer'; }
    $$('input[name="mode"]', wf).forEach(function (r) { r.addEventListener('change', syncMode); });
    var TKEY = 'cm_timer', clock = $('[data-timer-clock]', wf), bStart = $('[data-timer-start]', wf), bStop = $('[data-timer-stop]', wf), bReset = $('[data-timer-reset]', wf), tick = null;
    function tstate() { return store.get(TKEY, { start: 0, acc: 0 }); }
    function elapsed(s) { return s.acc + (s.start ? Date.now() - s.start : 0); }
    function fmt(ms) { var t = Math.floor(ms / 1000); return Math.floor(t / 3600) + ':' + String(Math.floor(t % 3600 / 60)).padStart(2, '0') + ':' + String(t % 60).padStart(2, '0'); }
    function paint() {
      var s = tstate(); clock.textContent = fmt(elapsed(s)); clock.classList.toggle('running', !!s.start);
      bStart.textContent = s.start ? 'Pause' : (s.acc ? 'Resume' : 'Start'); bStop.disabled = !elapsed(s);
    }
    function loop() { clearInterval(tick); if (tstate().start) tick = setInterval(paint, 1000); paint(); }
    bStart.addEventListener('click', function () {
      var s = tstate();
      if (s.start) { s.acc += Date.now() - s.start; s.start = 0; } else { s.start = Date.now(); }
      store.set(TKEY, s); loop();
    });
    function useTimer() {
      // v0.11: whole minutes (rounded; at least 1 minute once the timer has run).
      var ms = elapsed(tstate());
      if (ms < 1000) return false;
      var t = Math.min(Math.max(1, Math.round(ms / 60000)), 1439) * 60;
      H.value = Math.floor(t / 3600); M.value = Math.floor(t % 3600 / 60); if (S) S.value = 0;
      store.del(TKEY); clearInterval(tick);
      var d = $('input[name="mode"][value="duration"]', wf); d.checked = true; syncMode(); paint(); return true;
    }
    bStop.addEventListener('click', function () { useTimer(); H.focus(); });
    bReset.addEventListener('click', function () { if (!elapsed(tstate()) || window.confirm('Reset the timer to zero?')) { store.del(TKEY); loop(); } });
    if (tstate().start || tstate().acc) { $('[data-timer-mode]', wf).checked = true; }
    syncMode(); loop();
    wf.addEventListener('submit', function (e) {
      if (mode() === 'timer' && !useTimer()) { e.preventDefault(); window.alert('Start the timer, or choose Duration.'); }
    });
    } // end timer/mode section (Log work page only)
  }

  /* ---------------- task form: contacts follow client, types follow category ---------------- */
  var tfm = $('[data-task-form]');
  if (tfm) {
    var cSel = $('[data-client-select]', tfm), kSel = $('[data-contact-select]', tfm), kHelp = $('[data-contact-help]', tfm);
    if (cSel && kSel) cSel.addEventListener('change', function () {
      var cid = cSel.value; kSel.replaceChildren(el('option', { value: '', text: '—' }));
      if (!cid) { if (kHelp) kHelp.textContent = 'Choose a client to list its contacts.'; return; }
      fetch('lookup.php?contacts_for=' + encodeURIComponent(cid), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
        (d.contacts || []).forEach(function (k) { kSel.appendChild(el('option', { value: String(k.id), text: k.name + (k.archived ? ' (archived)' : '') })); });
        if (kHelp) kHelp.textContent = (d.contacts || []).length ? '' : 'This client has no contacts yet.';
      }).catch(function () { });
    });
    var catSel = $('[data-category-select]', tfm), typSel = $('[data-type-select]', tfm);
    if (catSel && typSel) {
      var map = {}; try { map = JSON.parse(typSel.getAttribute('data-types') || '{}'); } catch (e) { }
      catSel.addEventListener('change', function () {
        var keep = typSel.value; typSel.replaceChildren(el('option', { value: '', text: '—' }));
        (map[catSel.value] || []).forEach(function (t) { typSel.appendChild(el('option', { value: t, text: t })); });
        if ((map[catSel.value] || []).indexOf(keep) >= 0) typSel.value = keep;
      });
    }
    // Enquiry: choosing a type fills the title while it is empty or still the last automatic fill.
    var tIn = $('[data-title-from-type]', tfm);
    if (tIn && typSel) {
      var lastAuto = '';
      typSel.addEventListener('change', function () {
        var cur = tIn.value.trim();
        if (typSel.value && (cur === '' || cur === lastAuto)) { tIn.value = typSel.value; lastAuto = typSel.value; }
      });
      // v0.12: choosing a template fills the title the same way.
      var tplSel = $('[data-template-select]', tfm);
      if (tplSel) tplSel.addEventListener('change', function () {
        var opt = tplSel.options[tplSel.selectedIndex], t = opt ? (opt.getAttribute('data-title') || '') : '', cur = tIn.value.trim();
        if (t && (cur === '' || cur === lastAuto)) { tIn.value = t; lastAuto = t; }
      });
    }
  }

  /* ---------------- v0.13: copy a Xero description ---------------- */
  $$('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var src = document.getElementById(b.getAttribute('data-copy')); if (!src) return;
      var t = src.textContent, done = function () { var o = b.textContent; b.textContent = 'Copied'; setTimeout(function () { b.textContent = o; }, 1500); };
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(t).then(done, function () { });
      else { var r = document.createRange(); r.selectNodeContents(src); var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(r); try { document.execCommand('copy'); done(); } catch (e) { } }
    });
  });

  /* ---------------- list filters apply as soon as they change ---------------- */
  $$('[data-autosubmit]').forEach(function (c) { c.addEventListener('change', function () { if (c.form) c.form.submit(); }); });

  /* ---------------- forms: prevent accidental double submit ---------------- */
  $$('form[method="post"]').forEach(function (f) {
    if (f.hasAttribute('data-signout')) return;
    f.addEventListener('submit', function (e) {
      if (e.defaultPrevented) return;
      if (f.getAttribute('data-submitting')) { e.preventDefault(); return; }
      f.setAttribute('data-submitting', '1');
      $$('button[type="submit"], button:not([type])', f).forEach(function (b) { b.disabled = true; b.setAttribute('aria-busy', 'true'); b.textContent = 'Please wait…'; });
    });
  });
  // Restore buttons when returning via the back/forward cache.
  window.addEventListener('pageshow', function (e) { if (e.persisted) $$('form[data-submitting]').forEach(function (f) { f.removeAttribute('data-submitting'); $$('button', f).forEach(function (b) { b.disabled = false; }); }); });

  /* ---------------- sign out clears device-side traces ---------------- */
  $$('[data-signout]').forEach(function (f) {
    f.addEventListener('submit', function () {
      try { Object.keys(localStorage).forEach(function (k) { if (k.indexOf('cm_') === 0) localStorage.removeItem(k); }); sessionStorage.clear(); } catch (e) { }
    });
  });
})();
