/* Order taking (W2, W3, W4, W10): a tap adds one, a long press (or an item with a required choice) opens
   the options sheet; the order is created with the first item; the panel, send bar and in-cart badges
   follow every change from the server's answer. */
(function () {
  'use strict';
  const S = window.SOFREXA;
  const root = S.$('[data-take]');
  if (!root) return;
  const ctx = JSON.parse(root.dataset.take);
  const menu = S.$('[data-menu]');

  /* ------------------------------------------------------------ server state → screen */
  function apply(res) {
    if (res.order_id && ctx.order !== res.order_id) {
      ctx.order = res.order_id;
      if (!ctx.summary) history.replaceState(null, '', res.url);
      const sum = S.$('[data-summary]');
      if (sum) sum.href = res.url + '/summary';
    }
    if (ctx.summary) { location.reload(); return; }
    const panel = S.$('[data-order-panel]');
    if (panel && res.panel) panel.innerHTML = res.panel;
    const t = S.$('[data-new-text]');
    if (t) t.textContent = res.newText;
    const nt = S.$('[data-new-total]');
    if (nt) nt.textContent = res.newTotal;
    S.$$('.actionbar [data-send]').forEach(b => { b.disabled = !res.new; });
    if (menu && res.incart) {
      S.$$('.mtile[data-item]', menu).forEach(tile => {
        const q = res.incart[tile.dataset.item] || 0;
        if (tile.classList.contains('is-soldout')) return;
        tile.classList.toggle('is-incart', q > 0);
        const badge = tile.querySelector('.mtile__qty');
        badge.innerHTML = q > 0 ? S.esc(S.digits(String(q).replace('.', ','))) : S.icon('plus', 16);
        badge.classList.toggle('num', q > 0);
      });
    }
  }

  async function add(itemId, extra) {
    const body = Object.assign({ order_id: ctx.order, item_id: itemId, qty: 1, quiet: true }, extra || {});
    if (!ctx.order) {
      if (ctx.table) { body.table_id = ctx.table; body.guests = ctx.guests; } else body.channel = ctx.channel;
    }
    apply(await S.api('/orders/add', body));
  }

  /* ------------------------------------------------------------ menu tiles: tap and long press */
  if (menu) {
    let timer = null;
    let longFired = false;
    menu.addEventListener('pointerdown', e => {
      const tile = e.target.closest('.mtile[data-item]:not([disabled])');
      if (!tile) return;
      longFired = false;
      timer = setTimeout(() => { longFired = true; options(tile.dataset.item); }, 480);
    });
    ['pointerup', 'pointerleave', 'pointercancel'].forEach(ev => menu.addEventListener(ev, () => clearTimeout(timer)));
    menu.addEventListener('contextmenu', e => { if (e.target.closest('.mtile')) e.preventDefault(); });
    menu.addEventListener('click', e => {
      const tile = e.target.closest('.mtile[data-item]:not([disabled])');
      if (!tile || longFired) return;
      if (tile.dataset.required) { options(tile.dataset.item); return; }
      tile.classList.add('is-busy');
      add(tile.dataset.item).catch(() => {}).finally(() => tile.classList.remove('is-busy'));
    });
  }

  /* ------------------------------------------------------------ categories and search */
  let cat = 'pop';
  let query = '';
  function filter() {
    if (!menu) return;
    let shown = 0;
    S.$$('.mtile[data-item]', menu).forEach(tile => {
      const ok = query ? tile.dataset.q.indexOf(query) !== -1 : (cat === 'pop' ? !!tile.dataset.pop : tile.dataset.cat === cat);
      tile.hidden = !ok;
      if (ok) shown++;
    });
    const empty = S.$('[data-menu-empty]');
    if (empty) empty.hidden = shown > 0;
  }
  const cats = S.$('[data-cats]');
  if (cats) cats.addEventListener('click', e => {
    const chip = e.target.closest('.chip[data-cat]');
    if (!chip) return;
    cat = chip.dataset.cat;
    S.$$('.chip', cats).forEach(c => c.classList.toggle('is-selected', c === chip));
    S.$$('[data-menu-search]').forEach(i => { i.value = ''; });
    query = '';
    filter();
  });
  S.$$('[data-menu-search]').forEach(input => input.addEventListener('input', () => {
    query = input.value.trim().toLocaleLowerCase();
    filter();
  }));
  const toggle = S.$('[data-search-toggle]');
  if (toggle) toggle.addEventListener('click', () => {
    const box = S.$('[data-msearch]');
    box.hidden = !box.hidden;
    if (!box.hidden) box.querySelector('input').focus();
  });

  /* ------------------------------------------------------------ send, pre-bill */
  document.addEventListener('click', async e => {
    const send = e.target.closest('[data-send]');
    if (send && ctx.order) {
      send.classList.add('is-busy');
      try {
        const res = await S.api('/orders/' + ctx.order + '/send', {});
        S.toast(res.message);
        apply(res);
      } catch (err) { /* toast shown */ } finally { send.classList.remove('is-busy'); }
      return;
    }
    const pre = e.target.closest('[data-prebill]');
    if (pre && ctx.order) {
      pre.classList.add('is-busy');
      try {
        const res = await S.api('/orders/' + ctx.order + '/prebill', {});
        S.toast(res.message);
        apply(res);
      } catch (err) { /* toast shown */ } finally { pre.classList.remove('is-busy'); }
      return;
    }
    const line = e.target.closest('[data-line]');
    if (line) { S.loadSheet('/orders/lines/' + line.dataset.line).catch(() => {}); return; }
    const remove = e.target.closest('[data-line-remove]');
    if (remove) {
      e.preventDefault();
      const res = await S.api('/orders/lines/' + remove.dataset.lineRemove, { qty: 0 });
      S.closeSheet(remove.closest('.scrim'));
      apply(res);
    }
  });

  /* ------------------------------------------------------------ options sheet (W4) */
  async function options(itemId) {
    const scrim = await S.loadSheet('/orders/item/' + itemId + '/options').catch(() => null);
    if (!scrim) return;
    const form = scrim.querySelector('[data-options]');
    const btn = form.querySelector('[type=submit]');
    const price = () => {
      let unit = parseInt(form.dataset.price, 10);
      S.$$('input:checked[data-price]', form).forEach(i => { unit += parseInt(i.dataset.price, 10) || 0; });
      const qty = parseFloat(form.elements.qty.value) || 1;
      btn.querySelector('span').textContent = btn.dataset.addLabel.replace('{amount}', S.money(Math.round(unit * qty)));
    };
    form.addEventListener('change', e => {
      const g = e.target.closest('[data-group]');
      if (g && e.target.type === 'checkbox') {
        const max = parseInt(g.dataset.max, 10) || 99;
        const on = S.$$('input:checked', g);
        if (on.length > max) e.target.checked = false;
      }
      price();
    });
    price();
    form.addEventListener('submit', async e => {
      e.preventDefault();
      for (const g of S.$$('[data-group]', form)) {
        if (S.$$('input:checked', g).length < (parseInt(g.dataset.min, 10) || 0)) { S.toast(S.tr('js.error') + ' · ' + g.dataset.name, 'error'); return; }
      }
      const mods = S.$$('[data-group] input:checked', form).map(i => i.value);
      btn.classList.add('is-busy');
      try {
        await add(itemId, { mods: mods, qty: parseFloat(form.elements.qty.value) || 1, note: form.elements.note.value });
        S.closeSheet(scrim);
      } catch (err) { /* toast shown */ } finally { btn.classList.remove('is-busy'); }
    });
  }

  /* ------------------------------------------------------------ line sheets */
  document.addEventListener('submit', async e => {
    const lf = e.target.closest('[data-line-form]');
    const vf = e.target.closest('[data-void-form]');
    if (!lf && !vf) return;
    e.preventDefault();
    const f = lf || vf;
    const btn = f.querySelector('[type=submit]');
    btn.classList.add('is-busy');
    try {
      const res = lf
        ? await S.api('/orders/lines/' + lf.dataset.lineForm, { qty: parseFloat(f.elements.qty.value), note: f.elements.note.value })
        : await S.api('/orders/lines/' + vf.dataset.voidForm + '/void', { qty: parseFloat(f.elements.qty.value), reason: f.elements.reason.value });
      if (res.message) S.toast(res.message);
      S.closeSheet(f.closest('.scrim'));
      apply(res);
    } catch (err) {
      if (err.data && err.data.errors && err.data.errors.reason) f.elements.reason.closest('.field').classList.add('is-error');
    } finally { btn.classList.remove('is-busy'); }
  });

  filter();
})();
