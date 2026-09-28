/* Online ordering pages (Figma O1–O9): field errors, the password rule, the 6-digit code, the resend countdown,
   the cart (phone view and desktop CartPanel), options, search and categories, checkout switches, live tracking. */
(function () {
  'use strict';
  const S = window.SOFREXA;
  const CART = 'sfx-online-cart';

  /* ------------------------------------------------------------ forms: field errors under the inputs */
  document.addEventListener('ajax:fail', e => {
    const form = e.target;
    if (!form.matches('[data-online-form]')) return;
    const errors = (e.detail && e.detail.data && e.detail.data.errors) || {};
    Object.keys(errors).forEach(name => {
      const el = form.elements[name];
      const field = el && (el.closest ? el.closest('.field') : null);
      if (!field) return;
      field.classList.add('is-error');
      let help = field.querySelector('.field__help');
      if (!help) {
        help = document.createElement('div');
        help.className = 'field__help';
        field.appendChild(help);
      }
      help.textContent = errors[name];
    });
  });
  document.addEventListener('input', e => {
    const field = e.target.closest && e.target.closest('.field.is-error');
    if (field) field.classList.remove('is-error');
  });
  document.addEventListener('ajax:done', e => {
    if (e.detail && e.detail.clear_cart) {
      try { localStorage.removeItem(CART); } catch (err) { /* private mode */ }
    }
  });

  /* "En az 8 karakter ve bir rakam" turns green when met */
  S.$$('[data-pw-rule]').forEach(input => {
    const hint = S.$(input.dataset.pwRule);
    input.addEventListener('input', () => {
      const ok = input.value.length >= 8 && /\d/.test(input.value);
      hint.classList.toggle('c-success', ok);
      hint.classList.toggle('c-muted', !ok);
    });
  });

  /* CodeInput: one input drawn as six cells */
  S.$$('[data-code]').forEach(box => {
    const input = S.$('input', box);
    const cells = S.$$('.ocode__cell', box);
    const draw = () => {
      const v = input.value.replace(/\D/g, '').slice(0, 6);
      if (v !== input.value) input.value = v;
      cells.forEach((c, i) => {
        c.textContent = v[i] ? S.digits(v[i]) : '';
        c.classList.toggle('is-active', i === Math.min(v.length, 5) && document.activeElement === input && v.length < 6);
      });
      if (v.length === 6 && !box.dataset.sent) {
        box.dataset.sent = '1';
        const form = input.form;
        if (form && form.requestSubmit) form.requestSubmit();
      }
      if (v.length < 6) delete box.dataset.sent;
    };
    ['input', 'focus', 'blur'].forEach(ev => input.addEventListener(ev, draw));
    box.addEventListener('click', () => input.focus());
    draw();
  });

  /* "Kod gelmedi mi? 0:42 sonra tekrar gönder" → a link at 0 */
  S.$$('[data-resend]').forEach(el => {
    let left = parseInt(el.dataset.resend, 10) || 0;
    const label = el.tagName === 'BUTTON' ? S.$('span', el) : el;
    const tick = () => {
      const t = Math.floor(left / 60) + ':' + String(left % 60).padStart(2, '0');
      if (left > 0) {
        label.textContent = el.dataset.textWait.replace('{t}', S.digits(t));
        el.classList.add('is-wait');
        if (el.tagName === 'BUTTON') el.disabled = true;
      } else {
        label.textContent = el.dataset.textGo;
        el.classList.remove('is-wait');
        if (el.tagName === 'BUTTON') el.disabled = false;
      }
    };
    tick();
    setInterval(() => { if (left > 0) { left--; tick(); } }, 1000);
    el.addEventListener('click', async () => {
      if (left > 0) return;
      try {
        const r = await S.api(el.dataset.resendUrl, { purpose: el.dataset.resendPurpose || 'verify' });
        S.toast(r.message);
        left = r.wait || 60;
        tick();
      } catch (err) { /* toast shown */ }
    });
  });

  /* ------------------------------------------------------------ tracking (O4) */
  const track = S.$('[data-track]');
  if (track) {
    const refresh = async () => {
      if (document.hidden) return;
      try {
        const r = await S.api(track.dataset.track, undefined, { quiet: true });
        if (r.html) track.innerHTML = r.html;
      } catch (e) { /* next round */ }
    };
    setInterval(refresh, 10000);
    document.addEventListener('visibilitychange', refresh);
  }

  /* ------------------------------------------------------------ checkout (O3 / O6) */
  S.$$('[data-checkout]').forEach(form => {
    const sync = () => {
      const typeEl = form.querySelector('[name=type]:checked') || form.querySelector('[name=type]');
      const type = typeEl ? typeEl.value : 'delivery';
      S.$$('[data-when-type]', form).forEach(el => { el.hidden = el.dataset.whenType !== type; });
      S.$$('[data-pay-head], [data-step-title], [data-asap-label]', form).forEach(el => { el.textContent = el.dataset[type]; });
      const cash = (form.querySelector('[name=pay]:checked') || {}).value !== 'card';
      S.$$('[data-cash-box]', form).forEach(el => { el.hidden = !cash; });
      // tile texts: card at the door or at the restaurant; cash "₺2.000 ile ödenecek" once an amount is typed
      S.$$('[data-pay=card]', form).forEach(t => { S.$('.opt__sub', t).textContent = t.dataset[type]; });
      const given = form.elements.cash_given && form.elements.cash_given.value.replace(/[^\d]/g, '');
      S.$$('[data-pay=cash]', form).forEach(t => {
        S.$('.opt__sub', t).textContent = given ? t.dataset.subWith.replace('{amount}', S.money(parseInt(given, 10) * 100)) : t.dataset.subEmpty;
      });
      const slot = form.querySelector('[name=when]:checked');
      S.$$('[data-slot-box]', form).forEach(el => { el.hidden = !(slot && slot.value === 'slot'); });
      const tot = S.$('[data-total]', form);
      if (tot) {
        const sum = parseInt(tot.dataset.sub, 10) + (type === 'delivery' ? parseInt(tot.dataset.fee, 10) : 0);
        S.$$('[data-total]', form).forEach(x => { x.textContent = S.money(sum); });
        const btn = S.$('[data-place-btn]');
        if (btn && form.id === 'm-checkout') S.$('span', btn).textContent = btn.dataset.label.replace('{amount}', S.money(sum));
      }
    };
    form.addEventListener('change', sync);
    form.addEventListener('input', e => { if (e.target.name === 'cash_given') sync(); });
    sync();
  });
  document.addEventListener('click', e => {
    const pick = e.target.closest('[data-pick-addr]');
    if (!pick) return;
    const f = S.$('#m-checkout');
    S.$('[data-addr-input]', f).value = pick.dataset.pickAddr;
    S.$('[data-addr-title]', f).textContent = pick.dataset.title;
    S.$('[data-addr-line]', f).textContent = pick.dataset.line;
    S.closeSheet(pick.closest('.scrim'));
  });

  /* ------------------------------------------------------------ menu and cart (O5, phones like Q1/Q2) */
  const app = S.$('[data-online]');
  const dataEl = S.$('#online-data');
  railAndSearch();
  if (!app || !dataEl) return;
  const D = JSON.parse(dataEl.textContent);
  const MIN = parseInt(app.dataset.min, 10) || 0;
  let cart = load();

  function load() {
    try {
      const c = JSON.parse(localStorage.getItem(CART) || 'null');
      if (c && Array.isArray(c.lines)) {
        c.lines = c.lines.filter(l => D.items[l.item] && D.items[l.item].ok && l.qty > 0);
        if (D.types.indexOf(c.type) < 0) c.type = D.types[0];
        return c;
      }
    } catch (e) { /* private mode */ }
    return { lines: [], type: D.types[0] || 'delivery' };
  }
  function save() {
    try { localStorage.setItem(CART, JSON.stringify(cart)); } catch (e) { /* private mode */ }
    draw();
  }
  const lineKey = (item, mods, note) => item + '|' + mods.slice().sort().join(',') + '|' + (note || '');
  const optsOf = (item, mods) => {
    const out = { price: 0, names: [] };
    (D.items[item].g || []).forEach(g => g.options.forEach(o => { if (mods.indexOf(o.id) >= 0) { out.price += o.price; out.names.push(o.name); } }));
    return out;
  };
  const unit = l => D.items[l.item].p + optsOf(l.item, l.mods).price;
  const count = () => cart.lines.reduce((s, l) => s + l.qty, 0);
  const total = () => cart.lines.reduce((s, l) => s + l.qty * unit(l), 0);
  const qtyOf = item => cart.lines.filter(l => l.item === item).reduce((s, l) => s + l.qty, 0);
  function add(item, qty, mods, note) {
    mods = mods || [];
    note = (note || '').trim();
    const k = lineKey(item, mods, note);
    const line = cart.lines.find(l => l.k === k);
    if (line) line.qty = Math.min(D.max, line.qty + qty);
    else cart.lines.push({ k: k, item: item, qty: Math.min(D.max, qty), mods: mods, note: note });
    save();
  }
  function step(k, d) {
    const line = cart.lines.find(l => l.k === k);
    if (!line) return;
    line.qty = Math.min(D.max, line.qty + d);
    if (line.qty <= 0) cart.lines = cart.lines.filter(l => l !== line);
    save();
  }
  const stepper = (k, q) => '<div class="qty"><button type="button" data-line-step="-1" data-k="' + S.esc(k) + '" aria-label="−">' + S.icon('minus', 18) + '</button>'
    + '<span class="qty__n num">' + S.digits(q) + '</span><button type="button" data-line-step="1" data-k="' + S.esc(k) + '" aria-label="+">' + S.icon('plus', 18) + '</button></div>';

  function draw() {
    // dish controls: phones ＋ / gold stepper (Q1), desktop "Ekle" / QtyStepper (O5)
    S.$$('[data-dish]').forEach(card => {
      const id = card.dataset.dish;
      const ctl = S.$('[data-ctl]', card);
      if (!ctl) return;
      const q = qtyOf(id);
      card.classList.toggle('is-incart', q > 0);
      if (ctl.dataset.ctl === 'd') {
        ctl.innerHTML = q > 0
          ? '<div class="qty"><button type="button" data-dish-minus="' + id + '" aria-label="−">' + S.icon('minus', 18) + '</button><span class="qty__n num">' + S.digits(q) + '</span><button type="button" data-add="' + id + '" aria-label="+">' + S.icon('plus', 18) + '</button></div>'
          : '<button type="button" class="btn btn--accent btn--s" data-add="' + id + '">' + S.icon('plus', 16) + '<span>' + S.esc(S.tr('js.on_add')) + '</span></button>';
      } else {
        ctl.innerHTML = q > 0
          ? '<span class="dstep"><button type="button" data-dish-minus="' + id + '" aria-label="−">' + S.icon('minus', 18) + '</button><span class="dstep__n t-label-l num">' + S.digits(q) + '</span><button type="button" class="dstep__plus" data-add="' + id + '" aria-label="+">' + S.icon('plus', 18) + '</button></span>'
          : '<button type="button" class="dish__add" data-add="' + id + '" aria-label="+">' + S.icon('plus', 20) + '</button>';
      }
    });
    const n = count();
    const sum = total();
    const bar = S.$('[data-open-cart]');
    if (bar) {
      bar.hidden = n === 0;
      S.$('[data-cart-n]', bar).textContent = S.digits(n);
      S.$('[data-cart-total]', bar).textContent = S.money(sum);
    }
    const fee = cart.type === 'delivery' ? parseInt(app.dataset.fee, 10) || 0 : 0;
    const below = cart.type === 'delivery' && sum < MIN;
    S.$$('[data-cart-lines]').forEach(box => {
      const desk = box.dataset.cartLines === 'd';
      box.innerHTML = cart.lines.map(l => {
        const it = D.items[l.item];
        const note = optsOf(l.item, l.mods).names.concat(l.note ? [l.note] : []).join(', ');
        if (desk) {
          return '<div class="ocline">' + (it.img ? '<img class="ocline__img" src="' + S.esc(it.img) + '" alt="">' : '<span class="ocline__img">' + S.icon('utensils', 18) + '</span>')
            + '<div class="grow col gap-2"><span class="t-label-m">' + S.esc(it.n) + '</span>' + (note ? '<span class="t-body-s c-muted ellipsis">' + S.esc(note) + '</span>' : '')
            + '<span class="t-body-s c-accent num">' + S.money(l.qty * unit(l)) + '</span></div>' + stepper(l.k, l.qty) + '</div>';
        }
        return '<div class="cline">' + (it.img ? '<img class="cline__img" src="' + S.esc(it.img) + '" alt="">' : '<span class="cline__img">' + S.icon('utensils', 22) + '</span>')
          + '<div class="cline__col"><span class="t-label-l">' + S.esc(it.n) + '</span>' + (note ? '<span class="cline__note t-body-s c-secondary">' + S.esc(note) + '</span>' : '')
          + '<span class="t-label-m c-accent num">' + S.money(l.qty * unit(l)) + '</span></div>' + stepper(l.k, l.qty) + '</div>';
      }).join('');
    });
    S.$$('[data-cart-empty]').forEach(el => { el.hidden = n > 0; });
    S.$$('[data-cart-sub]').forEach(el => { el.textContent = S.money(sum); });
    S.$$('[data-cart-sum]').forEach(el => { el.textContent = S.money(sum + fee); });
    S.$$('[data-fee-row]').forEach(el => { el.hidden = cart.type !== 'delivery'; });
    S.$$('[data-min-banner]').forEach(el => {
      el.hidden = !(below && n > 0);
      const t = S.$('.banner__text', el);
      if (t) t.textContent = S.tr('js.on_more', { amount: S.money(MIN - sum) });
    });
    S.$$('[data-continue]').forEach(b => {
      S.$('span', b).textContent = b.dataset.label.replace('{amount}', S.money(sum + fee));
      b.disabled = n === 0 || below;
    });
    S.$$('[data-cart-type] input').forEach(i => { i.checked = i.value === cart.type; });
  }

  /* the phone's cart view (Q2 look), with the back button */
  const views = { menu: S.$('[data-view="menu"]'), cart: S.$('[data-view="cart"]') };
  let menuScroll = 0;
  function show(name) {
    if (!views.cart) return;
    if (name === 'cart') menuScroll = window.scrollY;
    views.menu.hidden = name !== 'menu';
    views.cart.hidden = name !== 'cart';
    window.scrollTo(0, name === 'menu' ? menuScroll : 0);
  }
  window.addEventListener('popstate', () => show(location.hash === '#sepet' ? 'cart' : 'menu'));
  if (location.hash === '#sepet') history.replaceState(null, '', location.pathname + location.search);

  /* options of a dish */
  const opts = S.$('#guest-opts');
  let optItem = null;
  function openOptions(id) {
    const it = D.items[id];
    optItem = id;
    S.$('[data-opts-title]', opts).textContent = it.n;
    S.$('[data-opts-body]', opts).innerHTML = it.g.map((g, gi) => {
      const plus = p => p > 0 ? ' +' + S.money(p) : '';
      const body = g.multi
        ? g.options.map(o => '<label class="checkrow checkrow--opt"><span class="check"><input type="checkbox" name="g' + gi + '" value="' + S.esc(o.id) + '"><span class="check__box">' + S.icon('check', 16) + '</span></span><span class="t-body-l">' + S.esc(o.name + plus(o.price)) + '</span></label>').join('')
        : '<div class="chips chips--wrap">' + g.options.map((o, k) => '<label class="chip"><input type="radio" name="g' + gi + '" value="' + S.esc(o.id) + '"' + (k === 0 && g.min > 0 ? ' checked' : '') + '>' + S.esc(o.name + plus(o.price)) + '</label>').join('') + '</div>';
      return '<div class="optgroup' + (g.multi ? ' optgroup--multi' : '') + '"><div class="overline">' + S.esc(g.name) + '</div>' + body + '</div>';
    }).join('');
    const f = S.$('[data-opts]', opts);
    f.elements.line_note.value = '';
    f.elements.qty.value = 1;
    S.$('[data-stepper-n]', opts).textContent = S.digits(1);
    optPrice();
    S.openSheet(opts);
  }
  const chosen = () => S.$$('input:checked', S.$('[data-opts-body]', opts)).map(i => i.value);
  function optPrice() {
    const f = S.$('[data-opts]', opts);
    const btn = S.$('[type=submit]', f);
    const q = parseInt(f.elements.qty.value, 10) || 1;
    S.$('span', btn).textContent = btn.dataset.label.replace('{amount}', S.money(q * (D.items[optItem].p + optsOf(optItem, chosen()).price)));
  }
  if (opts) {
    opts.addEventListener('change', optPrice);
    S.$('[data-opts]', opts).addEventListener('submit', e => {
      e.preventDefault();
      const it = D.items[optItem];
      const c = chosen();
      for (const g of it.g) {
        const n = g.options.filter(o => c.indexOf(o.id) >= 0).length;
        if (n < g.min || (g.max > 0 && n > g.max)) { S.toast(S.tr('js.qr_choose', { name: g.name }), 'error'); return; }
      }
      add(optItem, parseInt(e.target.elements.qty.value, 10) || 1, c, e.target.elements.line_note.value);
      S.closeSheet(opts);
    });
  }

  document.addEventListener('click', async e => {
    const a = e.target.closest('[data-add]');
    if (a) {
      const it = D.items[a.dataset.add];
      if (it.g && it.g.length) openOptions(a.dataset.add);
      else add(a.dataset.add, 1, [], '');
      return;
    }
    const m = e.target.closest('[data-dish-minus]');
    if (m) {
      const lines = cart.lines.filter(l => l.item === m.dataset.dishMinus);
      if (lines.length) step(lines[lines.length - 1].k, -1);
      return;
    }
    const ls = e.target.closest('[data-line-step]');
    if (ls) { step(ls.dataset.k, parseInt(ls.dataset.lineStep, 10)); return; }
    if (e.target.closest('[data-open-cart]')) { history.pushState({ cart: 1 }, '', '#sepet'); show('cart'); return; }
    if (e.target.closest('[data-close-cart]')) { if (location.hash === '#sepet') history.back(); else show('menu'); return; }
    const go = e.target.closest('[data-continue]');
    if (go && !go.disabled) {
      go.classList.add('is-busy');
      try {
        const r = await S.api('/online/sepet', { lines: cart.lines.map(l => ({ item: l.item, qty: l.qty, mods: l.mods, note: l.note })), type: cart.type });
        location.href = r.redirect;
      } catch (err) { /* toast shown */ } finally { go.classList.remove('is-busy'); }
    }
  });
  document.addEventListener('change', e => {
    if (e.target.closest('[data-cart-type]')) { cart.type = e.target.value; save(); }
  });
  draw();

  /* ------------------------------------------------------------ search, category rail (phones) and CategoryNav (desktop) */
  function railAndSearch() {
    S.$$('[data-online-search]').forEach(search => search.addEventListener('input', () => {
      const q = search.value.trim().replace(/[İIı]/g, 'i').toLowerCase();
      const scope = search.closest('.only-desktop') || search.closest('.gview') || document;
      let any = false;
      S.$$('[data-cat]', scope).forEach(c => {
        let shown = 0;
        S.$$('[data-dish]', c).forEach(d => {
          const hit = !q || d.dataset.search.indexOf(q) >= 0;
          d.hidden = !hit;
          if (hit) shown++;
        });
        c.hidden = shown === 0;
        any = any || shown > 0;
      });
      S.$$('[data-none]', scope).forEach(n => { n.hidden = any; });
    }));
    if (!('IntersectionObserver' in window)) return;
    const title = S.$('[data-active-title]');
    const links = S.$$('[data-rail], [data-catnav]');
    let lock = 0;
    const select = id => {
      links.forEach(l => {
        const on = (l.dataset.rail || l.dataset.catnav) === id;
        l.classList.toggle(l.dataset.rail ? 'is-selected' : 'is-active', on);
        if (on && l.dataset.rail) l.scrollIntoView({ block: 'nearest', inline: 'center', behavior: 'smooth' });
      });
      const sec = S.$('.only-desktop [data-cat="' + id + '"]');
      if (title && sec) title.textContent = sec.dataset.catName;
    };
    links.forEach(l => l.addEventListener('click', e => {
      e.preventDefault();
      const id = l.dataset.rail || l.dataset.catnav;
      const sec = document.getElementById((l.dataset.rail ? 'm-c-' : 'd-c-') + id);
      if (!sec) return;
      lock = Date.now();
      select(id);
      sec.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }));
    const io = new IntersectionObserver(entries => {
      if (Date.now() - lock < 900) return;
      const vis = entries.filter(en => en.isIntersecting && en.target.offsetParent !== null).sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);
      if (vis.length) select(vis[0].target.dataset.cat);
    }, { rootMargin: '-80px 0px -60% 0px' });
    S.$$('[data-cat]').forEach(c => io.observe(c));
  }
})();
