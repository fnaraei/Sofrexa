/* Prices and stock (M7/M8): edits stay highlighted as a preview; bulk % change with rounding; one save for all. */
(function () {
  'use strict';
  const S = window.SOFREXA;
  const root = document.querySelector('[data-quick]');
  if (!root) return;
  const L = k => root.dataset['l' + k.charAt(0).toUpperCase() + k.slice(1)] || '';
  const rows = {};
  JSON.parse(root.dataset.rows).forEach(r => { rows[r.id] = r; });
  const edits = {}; // id -> {price?, stock?}  (stock: null = unlimited)
  const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));

  const toDigits = s => String(s).replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/٫/g, ',').replace(/٬/g, '.');
  function parseMoney(s) {
    s = toDigits(s).replace(/[^\d.,]/g, '');
    if (s === '') return null;
    const v = s.includes(',') ? parseFloat(s.replace(/\./g, '').replace(',', '.')) : parseFloat(s.replace(/\.(?=\d{3}(\D|$))/g, ''));
    return isNaN(v) ? null : Math.round(v * 100);
  }
  function parseStock(s) {
    s = toDigits(s).trim();
    if (s === '' || s === '—' || s === '-') return null;
    const n = parseInt(s.replace(/\D/g, ''), 10);
    return isNaN(n) ? null : n;
  }
  const pct = (a, b) => {
    const p = (b - a) / a * 100;
    return (p >= 0 ? '+' : '−') + '%' + S.digits(Math.abs(p).toFixed(1).replace('.', ','));
  };

  function current(id) {
    const r = rows[id];
    const e = edits[id] || {};
    return { price: 'price' in e ? e.price : r.price, stock: 'stock' in e ? e.stock : r.stock };
  }

  function statusBadge(r, stock) {
    if (!r.on) return '<span class="badge"><i class="badge__dot"></i>' + S.esc(L('off')) + '</span>';
    if (stock === null) return '<span class="badge badge--success"><i class="badge__dot"></i>' + S.esc(L('on')) + '</span>';
    const left = Math.max(0, stock - r.sold);
    if (left === 0) return '<span class="badge badge--danger"><i class="badge__dot"></i>' + S.esc(L('out')) + '</span>';
    if (left <= Math.max(3, Math.ceil(stock * 0.4))) return '<span class="badge badge--warning"><i class="badge__dot"></i>' + S.esc(L('low')) + '</span>';
    return '<span class="badge badge--success"><i class="badge__dot"></i>' + S.esc(L('on')) + '</span>';
  }

  function paint(id) {
    const r = rows[id];
    const c = current(id);
    const e = edits[id] || {};
    $$('[data-id="' + id + '"]').forEach(inp => {
      const k = inp.dataset.k;
      if (document.activeElement !== inp) inp.value = k === 'price' ? S.money(c.price) : (c.stock === null ? '—' : S.digits(c.stock));
      inp.closest('.editable').classList.toggle('is-changed', k in e);
    });
    $$('[data-row="' + id + '"]').forEach(tr => {
      const diff = tr.querySelector('[data-diff]');
      if (diff) { diff.textContent = 'price' in e ? pct(r.price, e.price) : '—'; diff.className = 'right nowrap ' + ('price' in e ? 't-label-m c-success' : ''); }
      const left = tr.querySelector('[data-left]');
      if (left) left.textContent = c.stock === null ? L('unlimited') : S.digits(Math.max(0, c.stock - r.sold));
      const st = tr.querySelector('[data-status]');
      if (st) st.innerHTML = statusBadge(r, c.stock);
      const sub = tr.querySelector('[data-msub]');
      if (sub) {
        if ('price' in e) { sub.textContent = L('old').replace('{price}', S.money(r.price)) + ' · ' + pct(r.price, e.price); sub.className = 't-body-s c-success'; }
        else { sub.textContent = sub.dataset.cat + (c.stock !== null && c.stock - r.sold <= 0 ? ' · ' + L('out').toLocaleLowerCase(S.lang) : ''); sub.className = 't-body-s c-muted'; }
      }
    });
    counter();
  }

  function counter() {
    const n = Object.keys(edits).length;
    $$('[data-quick-save]').forEach(b => {
      b.disabled = n === 0;
      b.querySelector('span').textContent = n ? L('save').replace('{n}', S.digits(n)) : L('save0');
    });
  }

  function set(id, k, v) {
    const r = rows[id];
    const e = edits[id] || {};
    const same = k === 'price' ? v === r.price || v === null : v === r.stock;
    if (same) delete e[k]; else e[k] = v;
    if (Object.keys(e).length) edits[id] = e; else delete edits[id];
    paint(id);
  }

  root.addEventListener('input', ev => {
    const inp = ev.target.closest('[data-id]');
    if (!inp) return;
    const v = inp.dataset.k === 'price' ? parseMoney(inp.value) : parseStock(inp.value);
    set(inp.dataset.id, inp.dataset.k, v);
  });
  root.addEventListener('focusout', ev => { const inp = ev.target.closest('[data-id]'); if (inp) paint(inp.dataset.id); });
  root.addEventListener('keydown', ev => {
    if (ev.key !== 'Enter' || !ev.target.closest('[data-id]')) return;
    ev.preventDefault();
    const all = $$('input[data-k="' + ev.target.dataset.k + '"]').filter(x => x.offsetParent);
    const next = all[all.indexOf(ev.target) + 1];
    if (next) { next.focus(); next.select(); }
  });

  // Bulk change.
  function bulk(cat, p, step) {
    Object.values(rows).forEach(r => {
      if (cat && r.cat !== cat) return;
      if (!p) { set(r.id, 'price', r.price); return; }
      let v = r.price * (1 + p / 100);
      v = step ? Math.round(v / step) * step : Math.round(v);
      set(r.id, 'price', v);
    });
    const n = Object.values(rows).filter(r => !cat || r.cat === cat).length;
    const catLabel = cat ? (document.querySelector('[data-bulk="cat"] option[value="' + cat + '"]') || {}).textContent : L('all');
    const stepLabel = '₺' + S.digits(step / 100);
    const title = document.querySelector('[data-bulk-title]');
    if (title) title.textContent = p ? title.dataset.active : title.dataset.idle;
    const line = document.querySelector('[data-bulk-line]');
    if (line && p) line.textContent = L('line').replace('{cat}', catLabel).replace('{n}', S.digits(n)).replace('{pct}', S.digits(Math.abs(p))).replace('{dir}', p > 0 ? L('up') : L('down')).replace('{step}', stepLabel);
    const mt = document.querySelector('[data-bulk-mtitle]');
    if (mt && p) mt.textContent = L('mline').replace('{cat}', catLabel).replace('{sign}', p > 0 ? '+' : '−').replace('{pct}', S.digits(Math.abs(p)));
    const ms = document.querySelector('[data-bulk-msub]');
    if (ms && p) ms.textContent = L('msub').replace('{step}', stepLabel).replace('{n}', S.digits(n));
    document.querySelector('[data-bulkbar]').classList.toggle('is-active', !!p);
  }
  const bsel = k => document.querySelector('[data-bulk="' + k + '"]');
  ['cat', 'pct', 'step'].forEach(k => { const s = bsel(k); if (s) s.addEventListener('change', () => bulk(bsel('cat').value, +bsel('pct').value, +bsel('step').value)); });
  const mApply = document.querySelector('[data-bulk-apply]');
  if (mApply) mApply.addEventListener('click', () => {
    const m = k => document.querySelector('[data-bulk-m="' + k + '"]').value;
    bulk(m('cat'), +m('pct'), +m('step'));
  });
  function undoPrices() {
    Object.keys(edits).forEach(id => { delete edits[id].price; if (!Object.keys(edits[id]).length) delete edits[id]; });
    Object.keys(rows).forEach(paint);
    if (bsel('pct')) bsel('pct').value = '0';
    const title = document.querySelector('[data-bulk-title]');
    if (title) title.textContent = title.dataset.idle;
    document.querySelector('[data-bulkbar]').classList.remove('is-active');
  }
  $$('[data-bulk-undo]').forEach(b => b.addEventListener('click', undoPrices));
  $$('[data-quick-reset]').forEach(b => b.addEventListener('click', () => { Object.keys(edits).forEach(id => delete edits[id]); Object.keys(rows).forEach(paint); undoPrices(); }));

  $$('[data-quick-save]').forEach(b => b.addEventListener('click', async () => {
    const items = {};
    Object.entries(edits).forEach(([id, e]) => {
      items[id] = {};
      if ('price' in e) items[id].price = String(e.price / 100).replace('.', ',');
      if ('stock' in e) items[id].daily_stock = e.stock === null ? '' : String(e.stock);
    });
    b.classList.add('is-busy');
    try {
      const r = await S.api('/menu/quick', { items });
      Object.keys(edits).forEach(id => delete edits[id]);
      S.toast(r.message);
      setTimeout(() => location.reload(), 500);
    } catch (e) { /* toast shown */ } finally { b.classList.remove('is-busy'); }
  }));

  window.addEventListener('beforeunload', ev => { if (Object.keys(edits).length) { ev.preventDefault(); ev.returnValue = L('leave'); } });
  Object.keys(rows).forEach(paint);
})();
