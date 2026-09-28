/* Phone order (C4): customer by phone with saved addresses, a client-side basket from the menu (options
   sheet for items with choices), payment at the door; the order is created in one go. Board (C5): refresh. */
(function () {
  'use strict';
  const S = window.SOFREXA;
  const form = S.$('[data-phone-order]');

  if (!form) {
    // C5 board: keep it fresh while nothing is open
    document.addEventListener('status', () => { if (!S.$('.scrim:not([hidden])')) location.reload(); });
    return;
  }

  const cfg = JSON.parse(form.dataset.cfg);
  const cart = [];   // {item_id, name, price (unit incl. options), qty, mods: [ids], modNames: [], note}
  const type = () => form.querySelector('[name=type]:checked').value;
  const parse = s => {
    s = String(s || '').trim().replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٬\s₺]/g, '');
    if (s.indexOf(',') !== -1) s = s.replace(/\./g, '').replace(',', '.');
    else if ((s.match(/\./g) || []).length > 1 || /\.\d{3}$/.test(s)) s = s.replace(/\./g, '');
    const v = parseFloat(s);
    return isNaN(v) ? 0 : v;
  };

  /* ------------------------------------------------------------ type, payment */
  function syncType() {
    const t = type();
    S.$$('[data-when]', form).forEach(el => { el.hidden = el.dataset.when !== t; });
    S.$('[data-title]', form).textContent = cfg.str['title_' + t];
    S.$('[data-order-title]', form).textContent = cfg.str[t];
    const pt = S.$('[data-pay-title]', form);
    pt.textContent = t === 'pickup' ? pt.dataset.counter : pt.dataset.door;
    render();
  }
  function syncPay() {
    const pay = form.querySelector('[name=pay]:checked').value;
    S.$('[data-cash-given]', form).hidden = pay !== 'cash';
  }
  form.addEventListener('change', e => {
    if (e.target.name === 'type') syncType();
    if (e.target.name === 'pay') syncPay();
    if (e.target.name === 'address_id') { S.$('[data-new-address]', form).hidden = true; }
  });

  /* ------------------------------------------------------------ customer by phone */
  let timer = null;
  const phone = S.$('[data-phone]', form);
  phone.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(lookup, 350);
  });
  async function lookup() {
    const digits = phone.value.replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/\D/g, '');
    const match = S.$('[data-match]', form);
    const fresh = S.$('[data-newcust]', form);
    const box = S.$('[data-addresses]', form);
    if (digits.length < 7) { match.hidden = true; fresh.hidden = true; form.elements.customer_id.value = ''; box.innerHTML = ''; return; }
    const res = await S.api('/delivery/customer?phone=' + encodeURIComponent(phone.value), undefined, { quiet: true }).catch(() => ({ customer: null }));
    const c = res.customer;
    form.elements.customer_id.value = c ? c.id : '';
    match.hidden = !c;
    fresh.hidden = !!c;
    if (c) {
      S.$('[data-match-av]', form).textContent = c.initials;
      S.$('[data-match-name]', form).textContent = c.name;
      S.$('[data-match-line]', form).textContent = c.line;
      form.elements.name.value = c.name;
      box.innerHTML = c.addresses.map((a, i) => '<label class="addr"><input type="radio" name="address_id" value="' + S.esc(a.id) + '"' + (i === 0 ? ' checked' : '') + '>' + S.icon('map-pin', 20)
        + '<span class="col grow" style="gap:0"><span class="t-label-m">' + S.esc(a.label) + '</span><span class="t-body-s c-secondary">' + S.esc(a.address) + '</span></span></label>').join('');
      S.$('[data-new-address]', form).hidden = c.addresses.length > 0;
    } else {
      box.innerHTML = '';
      S.$('[data-new-address]', form).hidden = false;
    }
  }
  S.$('[data-add-address]', form).addEventListener('click', () => {
    S.$$('[name=address_id]', form).forEach(r => { r.checked = false; });
    const na = S.$('[data-new-address]', form);
    na.hidden = false;
    na.querySelector('textarea, input').focus();
  });

  /* ------------------------------------------------------------ basket */
  function render() {
    const box = S.$('[data-cart]', form);
    if (!cart.length) {
      box.innerHTML = '<div class="empty">' + S.esc(cfg.str.empty) + '</div>';
    } else {
      box.innerHTML = cart.map((l, i) => '<button type="button" class="oline oline--new" data-cart-line="' + i + '"><span class="oline__qty num">' + S.digits(String(l.qty)) + '×</span>'
        + '<span class="oline__mid"><span class="oline__name">' + S.esc(l.name) + '</span>' + (l.modNames.length || l.note ? '<span class="oline__mods">' + S.esc(l.modNames.concat(l.note ? [l.note] : []).join(' · ')) + '</span>' : '')
        + '<span class="oline__status oline__status--new"><i class="oline__dot"></i><span>' + S.esc(cfg.str.new) + '</span></span></span>'
        + '<span class="oline__price num">' + S.money(l.price * l.qty) + '</span></button>').join('');
    }
    const sub = cart.reduce((s, l) => s + l.price * l.qty, 0);
    S.$('[data-sub]', form).textContent = S.money(sub);
    S.$('[data-total]', form).textContent = S.money(sub + (type() === 'delivery' ? cfg.fee : 0));
    S.$$('.mtile[data-item]', form).forEach(tile => {
      if (tile.classList.contains('is-soldout')) return;
      const q = cart.filter(l => l.item_id === tile.dataset.item).reduce((s, l) => s + l.qty, 0);
      tile.classList.toggle('is-incart', q > 0);
      const badge = tile.querySelector('.mtile__qty');
      badge.innerHTML = q > 0 ? S.esc(S.digits(String(q))) : S.icon('plus', 16);
    });
  }
  function add(line) {
    const same = cart.find(l => l.item_id === line.item_id && l.mods.join() === line.mods.join() && l.note === line.note);
    if (same) same.qty += line.qty; else cart.push(line);
    render();
  }
  form.addEventListener('click', e => {
    const cl = e.target.closest('[data-cart-line]');
    if (!cl) return;
    const i = parseInt(cl.dataset.cartLine, 10);
    const l = cart[i];
    const removed = Object.assign({}, l);
    if (l.qty > 1) l.qty -= 1; else cart.splice(i, 1);
    render();
    S.toast(l.name + ' −1', null, { label: cfg.str.undo, run: () => { removed.qty = 1; add(removed); } });
  });

  const menu = S.$('[data-menu]', form);
  let lp = null;
  let longFired = false;
  menu.addEventListener('pointerdown', e => {
    const tile = e.target.closest('.mtile[data-item]:not([disabled])');
    if (!tile || !tile.dataset.groups) return;
    longFired = false;
    lp = setTimeout(() => { longFired = true; options(tile); }, 480);
  });
  ['pointerup', 'pointerleave', 'pointercancel'].forEach(ev => menu.addEventListener(ev, () => clearTimeout(lp)));
  menu.addEventListener('click', e => {
    const tile = e.target.closest('.mtile[data-item]:not([disabled])');
    if (!tile || longFired) return;
    if (tile.dataset.required) { options(tile); return; }
    add({ item_id: tile.dataset.item, name: tile.dataset.name, price: parseInt(tile.dataset.price, 10), qty: 1, mods: [], modNames: [], note: '' });
  });

  async function options(tile) {
    const scrim = await S.loadSheet('/orders/item/' + tile.dataset.item + '/options').catch(() => null);
    if (!scrim) return;
    const f = scrim.querySelector('[data-options]');
    const btn = f.querySelector('[type=submit]');
    const unit = () => parseInt(f.dataset.price, 10) + S.$$('input:checked[data-price]', f).reduce((s, i) => s + (parseInt(i.dataset.price, 10) || 0), 0);
    const price = () => { btn.querySelector('span').textContent = btn.dataset.addLabel.replace('{amount}', S.money(unit() * (parseFloat(f.elements.qty.value) || 1))); };
    f.addEventListener('change', price);
    price();
    f.addEventListener('submit', e => {
      e.preventDefault();
      for (const g of S.$$('[data-group]', f)) {
        if (S.$$('input:checked', g).length < (parseInt(g.dataset.min, 10) || 0)) { S.toast(g.dataset.name, 'error'); return; }
      }
      const picked = S.$$('[data-group] input:checked', f);
      add({
        item_id: tile.dataset.item, name: tile.dataset.name, price: unit(), qty: parseFloat(f.elements.qty.value) || 1,
        mods: picked.map(i => i.value), modNames: picked.map(i => i.closest('label').textContent.trim()), note: f.elements.note.value.trim(),
      });
      S.closeSheet(scrim);
    });
  }

  /* ------------------------------------------------------------ categories and search */
  let cat = 'pop';
  let query = '';
  function filter() {
    let shown = 0;
    S.$$('.mtile[data-item]', menu).forEach(tile => {
      const ok = query ? tile.dataset.q.indexOf(query) !== -1 : (cat === 'pop' ? !!tile.dataset.pop : tile.dataset.cat === cat);
      tile.hidden = !ok;
      if (ok) shown++;
    });
    S.$('[data-menu-empty]', form).hidden = shown > 0;
  }
  S.$('[data-cats]', form).addEventListener('click', e => {
    const chip = e.target.closest('.chip[data-cat]');
    if (!chip) return;
    cat = chip.dataset.cat;
    S.$$('[data-cats] .chip', form).forEach(c => c.classList.toggle('is-selected', c === chip));
    filter();
  });
  S.$('[data-menu-search]', form).addEventListener('input', e => { query = e.target.value.trim().toLocaleLowerCase(); filter(); });

  /* ------------------------------------------------------------ create */
  form.addEventListener('submit', async e => {
    e.preventDefault();
    const btn = form.querySelector('.phord__order [type=submit]');
    const data = {
      type: type(), phone: phone.value, name: form.elements.name.value, customer_id: form.elements.customer_id.value,
      address_id: (form.querySelector('[name=address_id]:checked') || {}).value || '', address: form.elements.address.value, address_label: form.elements.address_label.value,
      courier_id: (form.querySelector('[name=courier_id]:checked') || {}).value || '', pay: form.querySelector('[name=pay]:checked').value,
      cash_given: parse(form.elements.cash_given.value) ? String(parse(form.elements.cash_given.value)) : '', note: form.elements.note.value, pickup_at: form.elements.pickup_at.value,
      items: cart.map(l => ({ item_id: l.item_id, qty: l.qty, mods: l.mods, note: l.note })),
    };
    btn.classList.add('is-busy');
    try {
      const res = await S.api('/delivery', data);
      if (res.redirect) location.href = res.redirect;
    } catch (err) {
      const errs = (err.data && err.data.errors) || {};
      Object.keys(errs).forEach(k => { const f = form.elements[k]; if (f && f.closest) { const fl = f.closest('.field'); if (fl) fl.classList.add('is-error'); } });
    } finally { btn.classList.remove('is-busy'); }
  });

  syncType();
  syncPay();
  filter();
})();
