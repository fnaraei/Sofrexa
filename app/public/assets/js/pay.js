/* Payment (C2/C3): method and currency switch the panel; the received amount gives the change in lira
   at the hand-entered rate; quick amounts round up; mixed = cash first, the rest by card. */
(function () {
  'use strict';
  const S = window.SOFREXA;
  const form = S.$('[data-pay-form]');
  if (!form) return;
  const cfg = JSON.parse(form.dataset.pay);
  const share = cfg.share;
  const sym = cur => (cur === 'TRY' ? '₺' : cfg.rates[cur].sym);
  const rate = cur => (cur === 'TRY' ? 1 : cfg.rates[cur].rate);
  const num2 = (v, dec) => {
    const s = v.toFixed(dec).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return S.lang === 'fa' ? S.digits(s.replace(/\./g, '٬').replace(/,/g, '٫')) : s;
  };
  const fxText = (cur, v) => (cur === 'TRY' ? S.money(Math.round(v * 100)) : sym(cur) + num2(v, Math.abs(v % 1) > 0.0001 ? 2 : 0));
  /** "1.234,50" / "36.82" / "۳۶٫۸۲" → number; 0 when it is not a number. The same rules as Money::number on the server. */
  const parse = s => {
    s = String(s || '').trim().replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d))
      .replace(/٫/g, ',').replace(/−/g, '-').replace(/[٬\s  ₺£$€]|TL|TRY|GBP|USD|EUR/g, '');
    if (/^-?\d{1,3}(\.\d{3})*(,\d+)?$/.test(s) || /^-?\d+(,\d+)?$/.test(s)) return parseFloat(s.replace(/\./g, '').replace(',', '.'));
    if (/^-?\d+(\.\d+)?$/.test(s)) return parseFloat(s);
    return 0;
  };
  const method = () => (form.querySelector('[name=method]:checked') || {}).value || 'cash';
  const currency = () => (form.querySelector('[name=currency]:checked') || {}).value || 'TRY';
  const dueIn = cur => (cur === 'TRY' ? share / 100 : Math.round(share / 100 / rate(cur) * 100) / 100);
  const toTry = (cur, v) => Math.round(v * rate(cur) * 100);

  function quick(cur) {
    const box = S.$('[data-quick]', form);
    if (!box) return;
    const due = dueIn(cur);
    const steps = cur === 'TRY' ? [50, 100, 200, 500, 1000, 5000] : [5, 10, 50, 100];
    const vals = [];
    steps.forEach(st => { const v = Math.ceil(due / st) * st; if (v > due + 0.0001 && vals.indexOf(v) === -1) vals.push(v); });
    const input = form.elements.received;
    const cur0 = parse(input.value);
    const chips = [[due, cfg.str.exact.replace('{amount}', fxText(cur, due))]].concat(vals.slice(0, 3).map(v => [v, fxText(cur, v)]));
    box.innerHTML = chips.map(([v, label]) => '<button type="button" class="chip' + (Math.abs(cur0 - v) < 0.001 ? ' is-selected' : '') + '" data-v="' + v + '">' + S.esc(label) + '</button>').join('');
  }

  function render() {
    const m = method();
    const cur = currency();
    S.$$('[data-show]', form).forEach(el => { el.hidden = el.dataset.show.split(' ').indexOf(m) === -1; });
    const fx = cur !== 'TRY';
    S.$$('[data-rate-box]', form).forEach(el => { if (!fx) el.hidden = true; });
    if (fx) {
      const r = cfg.rates[cur];
      const line = S.$('[data-rate-line]', form);
      if (line) line.textContent = cfg.str.rate.replace('{sym}', r.sym).replace('{rate}', num2(r.rate, 2)).replace('{when}', r.when).replace('{who}', r.by);
      const lm = S.$('[data-rate-line-m]', form);
      if (lm) lm.textContent = cfg.str.rate_m.replace('{sym}', r.sym).replace('{rate}', num2(r.rate, 2)).replace('{due}', fxText(cur, dueIn(cur)));
    }
    const dueEl = S.$('[data-due]', form);
    if (dueEl) dueEl.textContent = fxText(cur, dueIn(cur));
    const dueTry = S.$('[data-due-try]', form);
    if (dueTry) dueTry.textContent = fx ? '= ' + S.money(share) : '';
    const label = form.elements.received.closest('.field').querySelector('.field__label');
    if (label) label.textContent = cfg.str.received.replace('{sym}', sym(cur));
    // change or shortfall (cash and mixed)
    const got = parse(form.elements.received.value);
    const gotTry = got > 0 ? (fx && Math.abs(got - dueIn(cur)) < 0.001 ? share : toTry(cur, got)) : 0;
    const box = S.$('[data-change-box]', form);
    const out = S.$('[data-change]', form);
    if (m === 'cash') {
      box.hidden = got <= 0;
      box.classList.toggle('is-short', gotTry < share);
      box.querySelector('.grow').innerHTML = gotTry < share
        ? S.esc(cfg.str.short)
        : '<span class="only-desktop">' + S.esc(cfg.str.change) + '</span><span class="only-mobile">' + S.esc(cfg.str.change_m) + '</span>';
      out.textContent = S.money(Math.abs(gotTry - share));
    } else if (m === 'mixed') {
      box.hidden = true;
      const rest = S.$('[data-rest]', form);
      rest.textContent = cfg.str.rest.replace('{amount}', S.money(Math.max(0, share - gotTry)));
    }
    quick(cur);
  }

  form.addEventListener('change', e => {
    if (e.target.name === 'currency' || e.target.name === 'method') form.elements.received.value = '';
    if (e.target.hasAttribute('data-persons')) { go(parseInt(e.target.value, 10)); return; }
    render();
  });
  form.addEventListener('input', e => { if (e.target.name === 'received') render(); });
  form.addEventListener('click', e => {
    const chip = e.target.closest('[data-quick] .chip');
    if (chip) { form.elements.received.value = num2(parseFloat(chip.dataset.v), currency() === 'TRY' ? 0 : 2).replace(/,00$/, ''); render(); }
  });

  // split by guest: persons in the address (the page recalculates the share)
  function go(persons) {
    const u = new URL(location.href);
    if (persons >= 2) { u.searchParams.set('persons', persons); u.searchParams.set('k', form.elements.k.value || 0); } else { u.searchParams.delete('persons'); u.searchParams.delete('k'); }
    location.href = u.toString();
  }
  const modes = S.$('[data-split-mode]');
  if (modes) modes.addEventListener('change', e => {
    if (e.target.value === 'person') go(2);
    else if (e.target.value === 'one') go(0);
  });

  // customer with an account
  const search = S.$('[data-cust-search]', form);
  const list = S.$('[data-cust-list]', form);
  let timer = null;
  if (search) search.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(async () => {
      const q = search.value.trim();
      if (q.length < 2) { list.innerHTML = ''; return; }
      const res = await S.api('/cashier/customers?q=' + encodeURIComponent(q), undefined, { quiet: true }).catch(() => ({ rows: [] }));
      list.innerHTML = res.rows.map(c => '<label class="pickrow"><input type="radio" name="cust_pick" value="' + S.esc(c.id) + '"><span class="grow col gap-2"><span class="t-label-l">' + S.esc(c.name) + '</span><span class="t-body-s c-muted">' + S.esc(c.phone + ' · ' + c.balance) + '</span></span>' + S.icon('check', 20) + '</label>').join('');
    }, 250);
  });
  if (list) list.addEventListener('change', e => { form.elements.customer_id.value = e.target.value; });

  // submit (desktop button in the panel, phone button in the action bar)
  async function submit() {
    const btns = S.$$('[type=submit], [data-pay-submit]');
    btns.forEach(b => b.classList.add('is-busy'));
    try {
      const res = await S.api(form.action, new FormData(form));
      if (res.redirect) location.href = res.redirect;
    } catch (err) { /* toast shown */ } finally { btns.forEach(b => b.classList.remove('is-busy')); }
  }
  form.addEventListener('submit', e => { e.preventDefault(); submit(); });
  const mb = S.$('[data-pay-submit]');
  if (mb) mb.addEventListener('click', submit);

  render();
})();

/* Customer of the bill (search, new) and C12 loyalty points: all / half / keep with the live summary. */
(function () {
  'use strict';
  const S = window.SOFREXA;
  const group = n => {
    const s = String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return S.lang === 'fa' ? S.digits(s.replace(/\./g, '٬')) : s;
  };

  function customerPicker(box) {
    const order = box.dataset.order;
    const q = S.$('[data-cust-q]', box);
    const list = S.$('[data-cust-results]', box);
    let timer = null;
    let seq = 0;
    q.addEventListener('input', () => {
      clearTimeout(timer);
      timer = setTimeout(async () => {
        const term = q.value.trim();
        const my = ++seq;
        if (term.length < 2) { list.innerHTML = ''; return; }
        const res = await S.api('/customers/search?q=' + encodeURIComponent(term), undefined, { quiet: true }).catch(() => ({ rows: [] }));
        if (my !== seq) return;
        list.innerHTML = '';
        res.rows.forEach(r => {
          const b = document.createElement('button');
          b.type = 'button';
          b.className = 'pickrow';
          b.dataset.post = '/cashier/pay/' + order + '/customer';
          b.dataset.body = JSON.stringify({ customer_id: r.id });
          b.innerHTML = '<span class="avatar avatar--s"></span><span class="col grow" style="gap:0;min-width:0"><span class="t-label-m ellipsis"></span><span class="t-body-s c-muted ellipsis"></span></span>';
          b.querySelector('.avatar').textContent = r.name.split(/\s+/).map(w => w[0] || '').join('').slice(0, 2).toUpperCase();
          b.querySelector('.t-label-m').textContent = r.name;
          b.querySelector('.t-body-s').textContent = r.sub;
          if (r.blacklist) b.classList.add('is-danger');
          list.appendChild(b);
        });
        if (!res.rows.length) list.innerHTML = '<div class="empty">' + S.tr('js.no_customer') + '</div>';
      }, 220);
    });
    const add = S.$('[data-cust-new]', box);
    add.addEventListener('click', () => {
      const term = q.value.trim();
      const digits = term.replace(/\D+/g, '');
      const key = digits.length >= 7 && digits.length >= term.replace(/\s+/g, '').length - 2 ? 'phone' : 'name';
      S.loadSheet(add.dataset.custNew + (term ? '&' + key + '=' + encodeURIComponent(term) : ''));
    });
  }

  function redeem(form) {
    const cfg = JSON.parse(form.dataset.redeem);
    const btn = S.$('[data-r-btn] span', form);
    function render() {
      const on = S.$('input[name=mode]:checked', form);
      const amount = on ? Number(on.dataset.amount) : 0;
      const pay = Math.max(0, cfg.base - amount);
      const earn = cfg.earn > 0 ? Math.floor(Math.floor(pay * cfg.earn / 100) / cfg.pv) : 0;
      S.$('[data-r-disc]', form).textContent = S.money(-amount);
      S.$('[data-r-pay]', form).textContent = S.money(pay);
      S.$('[data-r-earn]', form).textContent = '+' + cfg.str.points.replace('{n}', group(earn));
      btn.textContent = amount > 0 ? cfg.str.apply.replace('{amount}', S.money(pay)) : cfg.str.keep;
    }
    form.addEventListener('change', render);
    render();
  }

  document.addEventListener('sheet:loaded', e => {
    const box = e.detail.querySelector('[data-cust-pick]');
    if (box) customerPicker(box);
    const f = e.detail.querySelector('[data-redeem]');
    if (f) redeem(f);
  });
})();
