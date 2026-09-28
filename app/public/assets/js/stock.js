/* Stock screens: list search (S1/S6), goods-in lines and totals (S2), counting with a device draft (S3),
   waste value (S4), recipe cost (S5), sharing the shopping list (S7). */
(function () {
  'use strict';
  const S = window.SOFREXA;
  const parse = s => {
    s = String(s || '').trim().replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d))
      .replace(/[٬\s₺]/g, '').replace(/٫/g, ',').replace(/−/g, '-');
    if (s.indexOf(',') !== -1) s = s.replace(/\./g, '').replace(',', '.');
    else if ((s.match(/\./g) || []).length > 1 || /\.\d{3}$/.test(s)) s = s.replace(/\./g, '');
    const v = parseFloat(s);
    return isNaN(v) ? null : v;
  };
  const qtyText = v => {
    const dec = Math.abs(v - Math.round(v)) < 0.0005 ? 0 : (Math.abs(v * 10 - Math.round(v * 10)) < 0.005 ? 1 : (Math.abs(v * 100 - Math.round(v * 100)) < 0.05 ? 2 : 3));
    const s = v.toFixed(dec).replace('.', ',');
    return S.lang === 'fa' ? S.digits(s.replace(',', '٫')) : s;
  };
  const store = {
    get(k) { try { return JSON.parse(localStorage.getItem(k) || 'null'); } catch (e) { return null; } },
    set(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} },
    del(k) { try { localStorage.removeItem(k); } catch (e) {} },
  };

  /** Search field with a result list: calls pick(row) or create(query). */
  function picker(box, opts) {
    const input = box.querySelector('[data-add-search]');
    const results = box.querySelector('[data-add-results]');
    let timer = null;
    input.addEventListener('input', () => {
      clearTimeout(timer);
      timer = setTimeout(async () => {
        const q = input.value.trim();
        if (!q) { results.hidden = true; return; }
        const res = await S.api('/stock/search?q=' + encodeURIComponent(q) + (opts.kind ? '&kind=' + opts.kind : ''), undefined, { quiet: true }).catch(() => ({ rows: [] }));
        results.innerHTML = res.rows.map((r, i) => '<button type="button" class="pickrow" data-i="' + i + '">' + S.icon(r.kind === 'semi' ? 'layers' : 'box', 20)
          + '<span class="grow col gap-2"><span class="t-label-l">' + S.esc(r.name) + '</span><span class="t-body-s c-muted">' + S.esc((r.group ? r.group + ' · ' : '') + r.on_hand_text + ' ' + r.unit_label) + '</span></span></button>').join('')
          + (opts.create ? '<button type="button" class="pickrow" data-new>' + S.icon('plus', 20) + '<span class="t-label-l">' + S.esc(opts.create.replace('{q}', q)) + '</span></button>' : '');
        results.hidden = false;
        results.onclick = e => {
          const b = e.target.closest('.pickrow');
          if (!b) return;
          results.hidden = true;
          if (b.hasAttribute('data-new')) { opts.onCreate(q); input.value = ''; return; }
          opts.onPick(res.rows[parseInt(b.dataset.i, 10)]);
          input.value = '';
        };
      }, 200);
    });
  }

  /** Opens the new-item sheet with a name; calls done(item) after saving instead of reloading. */
  async function newItem(name, done) {
    const scrim = await S.loadSheet('/stock/items/new/sheet?name=' + encodeURIComponent(name)).catch(() => null);
    if (!scrim) return;
    const f = scrim.querySelector('[data-stock-item]');
    f.elements.reload.value = '0';
    f.addEventListener('ajax:done', e => { if (e.detail && e.detail.item) done(e.detail.item); });
  }

  /* ------------------------------------------------------------ S1 / S6 */
  const toggle = S.$('[data-search-toggle]');
  if (toggle) toggle.addEventListener('click', () => {
    const f = S.$('.stock-filter');
    f.classList.toggle('is-open');
    if (f.classList.contains('is-open')) f.querySelector('input').focus();
  });

  /* ------------------------------------------------------------ S2 goods in */
  const pur = S.$('[data-purchase]');
  if (pur) {
    const str = JSON.parse(pur.dataset.str);
    const box = S.$('[data-lines]', pur);
    const draftKey = 'purchase-draft';
    let lines = [];
    function render() {
      box.innerHTML = lines.map((l, i) => '<div class="prow2" data-i="' + i + '">'
        + '<span class="prow2__name">' + S.icon('box', 18) + '<span class="t-label-m ellipsis">' + S.esc(l.name) + '</span></span>'
        + '<span class="cellin"><input value="' + S.esc(l.qty) + '" inputmode="decimal" data-f="qty" aria-label="qty"></span>'
        + '<span class="t-body-m c-secondary">' + S.esc(l.unit_label) + '</span>'
        + '<span class="cellin cellin--price"><input value="' + S.esc(l.price) + '" inputmode="decimal" data-f="price" aria-label="price"></span>'
        + '<span class="t-body-m c-secondary">%' + S.digits(String(l.vat)) + '</span>'
        + '<span class="t-label-m num" data-amount></span>'
        + '<button type="button" class="ibtn ibtn--ghost ibtn--s" data-remove aria-label="' + S.esc(str.remove) + '">' + S.icon('close', 18) + '</button></div>').join('');
      totals();
    }
    function totals() {
      let net = 0; let vat = 0; const rates = {};
      S.$$('.prow2[data-i]', box).forEach(row => {
        const l = lines[parseInt(row.dataset.i, 10)];
        const q = parse(l.qty) || 0;
        const p = Math.round((parse(l.price) || 0) * 100);
        const amount = Math.round(q * p);
        row.querySelector('[data-amount]').textContent = S.money(amount);
        net += amount;
        vat += Math.round(amount * l.vat / 100);
        rates[l.vat] = 1;
      });
      S.$('[data-net]', pur).textContent = S.money(net);
      S.$('[data-vat]', pur).textContent = S.money(vat);
      const rs = Object.keys(rates);
      S.$('[data-vat-label]', pur).textContent = rs.length === 1 ? str.vat.replace('{r}', S.digits(rs[0])) : str.vat.replace(' (%{r})', '').replace(' ({r}%)', '');
      S.$('[data-total]', pur).textContent = S.money(net + vat);
    }
    box.addEventListener('input', e => {
      const row = e.target.closest('[data-i]');
      lines[parseInt(row.dataset.i, 10)][e.target.dataset.f] = e.target.value;
      totals();
    });
    box.addEventListener('click', e => {
      const rm = e.target.closest('[data-remove]');
      if (!rm) return;
      lines.splice(parseInt(rm.closest('[data-i]').dataset.i, 10), 1);
      render();
    });
    const add = r => {
      lines.push({ id: r.id, name: r.name, unit_label: r.unit_label, qty: '', price: r.cost > 0 ? qtyText(r.cost / 100) : '', vat: r.vat });
      render();
      const last = box.lastElementChild;
      if (last) last.querySelector('input').focus();
    };
    picker(S.$('.puradd', pur), { create: str.new, onPick: add, onCreate: q => newItem(q, add) });
    // a new supplier from the list
    const sel = pur.querySelector('[data-supplier]');
    sel.addEventListener('change', async () => {
      if (sel.value !== '__new') return;
      const name = (window.prompt(sel.options[sel.selectedIndex].text.replace('+ ', '')) || '').trim();
      sel.value = '';
      if (!name) return;
      const res = await S.api('/stock/suppliers/save', { name: name });
      const o = new Option(name, res.id, true, true);
      sel.insertBefore(o, sel.querySelector('option[value="__new"]'));
    });
    // draft on this device
    const d = store.get(draftKey);
    if (d && d.lines && d.lines.length) {
      lines = d.lines;
      ['doc_no', 'note'].forEach(k => { if (d[k]) pur.elements[k].value = d[k]; });
      render();
    }
    S.$$('[data-draft]', pur).forEach(b => b.addEventListener('click', () => {
      store.set(draftKey, { lines: lines, doc_no: pur.elements.doc_no.value, note: pur.elements.note.value });
      S.toast(str.draft);
    }));
    pur.addEventListener('submit', async e => {
      e.preventDefault();
      const btns = S.$$('[type=submit]');
      btns.forEach(b => b.classList.add('is-busy'));
      try {
        const res = await S.api(pur.action, {
          supplier_id: pur.elements.supplier_id.value, doc_no: pur.elements.doc_no.value, day: pur.elements.day.value, pay: pur.elements.pay.value, note: pur.elements.note.value,
          lines: lines.map(l => ({ stock_item_id: l.id, qty: String(parse(l.qty) || 0), unit_price: String(parse(l.price) || 0), vat: l.vat })),
        });
        store.del(draftKey);
        if (res.redirect) location.href = res.redirect;
      } catch (err) { /* toast shown */ } finally { btns.forEach(b => b.classList.remove('is-busy')); }
    });
    render();
  }

  /* ------------------------------------------------------------ S3 count */
  const cnt = S.$('[data-count]');
  if (cnt) {
    const str = JSON.parse(cnt.dataset.str);
    const key = cnt.dataset.key;
    const rows = S.$$('[data-cnt]', cnt);
    const saved = store.get(key) || {};
    rows.forEach(r => { const i = r.querySelector('input'); if (saved[i.name] !== undefined) i.value = saved[i.name]; });
    function update() {
      let done = 0; const diffs = []; let value = 0; const draft = {};
      rows.forEach(r => {
        const input = r.querySelector('input');
        const v = parse(input.value);
        const state = r.querySelector('.cntrow__state');
        r.classList.remove('is-diff', 'is-ok');
        if (v === null) { state.innerHTML = S.icon('chevron-right', 22); return; }
        draft[input.name] = input.value;
        done++;
        const exp = parseFloat(r.dataset.expected);
        const d = Math.round((v - exp) * 1000) / 1000;
        if (Math.abs(d) < 0.0005) { r.classList.add('is-ok'); state.innerHTML = S.icon('check-circle', 22); return; }
        r.classList.add('is-diff');
        state.innerHTML = S.icon('alert', 22);
        diffs.push({ d: d, unit: r.dataset.unit, name: r.querySelector('.t-label-l').textContent });
        value += d * parseFloat(r.dataset.cost);
      });
      store.set(key, draft);
      S.$('[data-progress]', cnt).style.width = (rows.length ? done * 100 / rows.length : 0) + '%';
      const sub = str.sub.replace('{a}', S.digits(String(done))).replace('{b}', S.digits(String(rows.length)));
      S.$$('.appbar__sub, .page-head__titles p').forEach(el => { el.textContent = sub; });
      const dt = S.$('[data-diff-text]');
      const dv = S.$('[data-diff-value]');
      if (!diffs.length) { dt.textContent = str.none; dt.className = 't-label-m c-success'; dv.textContent = ''; return; }
      dt.className = 't-label-m c-warning';
      dt.textContent = diffs.length === 1 ? str.one.replace('{q}', (diffs[0].d > 0 ? '+' : '−') + qtyText(Math.abs(diffs[0].d))).replace('{unit}', diffs[0].unit).replace('{name}', diffs[0].name.toLocaleLowerCase()) : str.many.replace('{n}', S.digits(String(diffs.length)));
      dv.textContent = '≈ ' + S.money(Math.round(value));
    }
    cnt.addEventListener('input', update);
    const search = S.$('[data-count-search]');
    if (search) search.addEventListener('click', () => { const f = S.$('[data-count-filter]'); f.hidden = !f.hidden; if (!f.hidden) f.querySelector('input').focus(); });
    const q = S.$('[data-count-q]', cnt);
    if (q) q.addEventListener('input', () => { const v = q.value.trim().toLocaleLowerCase(); rows.forEach(r => { r.hidden = v !== '' && r.dataset.name.indexOf(v) === -1; }); });
    cnt.addEventListener('submit', async e => {
      e.preventDefault();
      try {
        const res = await S.api(cnt.action, new FormData(cnt));
        store.del(key);
        if (res.redirect) location.href = res.redirect;
      } catch (err) { /* toast shown */ }
    });
    update();
  }

  /* ------------------------------------------------------------ S4 waste */
  const waste = S.$('[data-waste]');
  if (waste) {
    const str = JSON.parse(waste.dataset.str);
    let item = null;
    const worth = () => {
      const q = parseFloat(waste.elements.qty.value) || 0;
      S.$('[data-worth]', waste).textContent = item ? str.worth.replace('{amount}', S.money(Math.round(q * item.cost))) : '';
    };
    picker(S.$('.puradd', waste), { onPick: r => {
      item = r;
      waste.elements.stock_item_id.value = r.id;
      S.$('[data-picked]', waste).hidden = false;
      S.$('[data-picked-name]', waste).textContent = r.name;
      S.$('[data-picked-sub]', waste).textContent = str.stock.replace('{q}', r.on_hand_text).replace(/\{unit\}/g, r.unit_label).replace('{price}', r.cost_text);
      S.$('[data-qty-label]', waste).textContent = str.qty.replace('{unit}', r.unit_label);
      const step = ['kg', 'L'].indexOf(r.unit_label) !== -1 ? 0.1 : (['g', 'ml'].indexOf(r.unit_label) !== -1 ? 10 : 1);
      S.$$('[data-step]', waste).forEach(b => { b.dataset.step = b.dataset.step.startsWith('-') ? -step : step; });
      worth();
    } });
    waste.addEventListener('change', worth);
  }

  /* ------------------------------------------------------------ S5 recipe */
  const rec = S.$('[data-recipe]');
  if (rec) {
    const str = JSON.parse(rec.dataset.str);
    const price = parseInt(rec.dataset.price, 10);
    const net = parseInt(rec.dataset.net, 10);
    const box = S.$('[data-rlines]', rec);
    let n = S.$$('[data-rline]', rec).length;
    function recalc() {
      let total = 0;
      S.$$('[data-rline]', box).forEach(row => {
        const q = parse(row.querySelector('[data-rqty]').value) || 0;
        // "Fire %": the part lost preparing it — the line takes q / (1 − fire) from stock, as Stock::gross on the server
        const w = Math.min(90, Math.max(0, parse((row.querySelector('[name$="[waste_pct]"]') || {}).value) || 0));
        const c = Math.round(q / (1 - w / 100) * parseFloat(row.dataset.cost));
        row.querySelector('[data-rcost]').textContent = S.money(Math.round(c / 100) * 100);
        total += c;
      });
      S.$('[data-total-cost]', rec).textContent = S.money(Math.round(total / 100) * 100);
      const pr = S.$('[data-profit]', rec);
      if (pr && net > 0) {
        pr.textContent = S.money(Math.round((net - total) / 100) * 100);
        S.$('[data-margin]', rec).textContent = '%' + S.digits(String(Math.round((net - total) * 100 / net)));
        S.$('[data-food]', rec).textContent = '%' + S.digits(String(Math.round(total * 100 / net)));
      }
    }
    box.addEventListener('input', recalc);
    box.addEventListener('click', e => {
      const rm = e.target.closest('[data-rremove]');
      if (rm) {
        let row = rm.closest('[data-rline]');
        let next = row.nextElementSibling;
        while (next && next.hasAttribute('data-rchild')) { const x = next.nextElementSibling; next.remove(); next = x; }
        row.remove();
        recalc();
        return;
      }
      const tg = e.target.closest('[data-rtoggle]');
      if (tg) {
        const open = tg.getAttribute('aria-expanded') === 'true';
        tg.setAttribute('aria-expanded', open ? 'false' : 'true');
        tg.innerHTML = S.icon(open ? 'chevron-right' : 'chevron-down', 18);
        let next = tg.closest('[data-rline]').nextElementSibling;
        while (next && next.hasAttribute('data-rchild')) { next.hidden = open; next = next.nextElementSibling; }
      }
    });
    const addBox = S.$('[data-radd]', rec);
    let kind = 'raw';
    S.$$('[data-radd-open]', rec).forEach(b => b.addEventListener('click', () => {
      kind = b.dataset.raddOpen;
      addBox.hidden = false;
      addBox.querySelector('input').focus();
    }));
    const addLine = r => {
      const f = r.unit === 'kg' || r.unit === 'lt' ? 1000 : 1;
      const du = r.unit === 'kg' ? 'g' : (r.unit === 'lt' ? 'ml' : r.unit_label);
      const i = n++;
      const semi = r.kind === 'semi';
      const row = document.createElement('div');
      row.className = 'rrow' + (semi ? ' rrow--group' : '');
      row.setAttribute('data-rline', '');
      row.dataset.cost = String(r.cost / f);
      row.innerHTML = '<input type="hidden" name="lines[' + i + '][stock_item_id]" value="' + S.esc(r.id) + '"><input type="hidden" name="lines[' + i + '][factor]" value="' + f + '">'
        + '<span class="rrow__name">' + (semi ? S.icon('layers', 18) + '<span class="t-label-m ellipsis">' + S.esc(str.semi.replace('{name}', r.name)) + '</span>' : S.icon('box', 18) + '<span class="t-body-m ellipsis">' + S.esc(r.name) + '</span>') + '</span>'
        + '<span class="cellin"><input name="lines[' + i + '][qty]" inputmode="decimal" data-rqty></span><span class="t-body-s c-secondary">' + S.esc(du) + '</span>'
        + '<span class="cellin cellin--s"><input name="lines[' + i + '][waste_pct]" placeholder="—" inputmode="decimal"></span>'
        + '<span class="rrow__cost"><span class="t-label-m c-secondary num" data-rcost>₺0</span><button type="button" class="ibtn ibtn--ghost ibtn--s" data-rremove aria-label="' + S.esc(str.remove) + '">' + S.icon('close', 18) + '</button></span>';
      box.appendChild(row);
      const empty = S.$('[data-rempty]', rec);
      if (empty) empty.remove();
      addBox.hidden = true;
      row.querySelector('[data-rqty]').focus();
    };
    picker(addBox, { get kind() { return kind; }, onPick: addLine });
    // the picker reads opts.kind at search time
  }

  /* ------------------------------------------------------------ S7 share */
  document.addEventListener('click', async e => {
    const b = e.target.closest('[data-share]');
    if (!b) return;
    const text = b.dataset.share;
    if (navigator.share) {
      try { await navigator.share({ text: text }); return; } catch (err) { if (err && err.name === 'AbortError') return; }
    }
    window.open('https://wa.me/?text=' + encodeURIComponent(text), '_blank', 'noopener');
  });
})();
