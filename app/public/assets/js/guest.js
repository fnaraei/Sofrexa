/* QR guest pages (Figma Q1–Q4): the cart on the phone, dish controls, CartBar, the cart view (Q2),
   option and note sheets, search, category rail and the live order status (Q3). */
(function () {
  'use strict';
  const S = window.SOFREXA;

  /* ------------------------------------------------------------ Q3: live status */
  const live = S.$('[data-status]');
  if (live) {
    const refresh = async () => {
      if (document.hidden) return;
      try {
        const r = await S.api(live.dataset.status, undefined, { quiet: true });
        if (r.redirect) { location.href = r.redirect; return; }
        live.innerHTML = r.html;
      } catch (e) { /* next round */ }
    };
    setInterval(refresh, 8000);
    document.addEventListener('visibilitychange', refresh);
  }

  /* ------------------------------------------------------------ Q1 / Q2 */
  const app = S.$('[data-guest]');
  const dataEl = S.$('#guest-data');
  if (!app || !dataEl) {
    railAndSearch();
    return;
  }
  const D = JSON.parse(dataEl.textContent);
  const key = 'sfx-cart-' + app.dataset.code;
  let cart = load();

  function load() {
    try {
      const c = JSON.parse(localStorage.getItem(key) || 'null');
      if (c && Array.isArray(c.lines)) {
        c.lines = c.lines.filter(l => D.items[l.item] && D.items[l.item].ok && l.qty > 0);
        return c;
      }
    } catch (e) { /* private mode */ }
    return { lines: [], note: '' };
  }
  function save() {
    try { localStorage.setItem(key, JSON.stringify(cart)); } catch (e) { /* private mode */ }
    draw();
  }

  const lineKey = (item, mods, note) => item + '|' + mods.slice().sort().join(',') + '|' + (note || '');
  const modsPrice = (item, mods) => {
    let sum = 0;
    (D.items[item].g || []).forEach(g => g.options.forEach(o => { if (mods.indexOf(o.id) >= 0) sum += o.price; }));
    return sum;
  };
  const modNames = (item, mods) => {
    const out = [];
    (D.items[item].g || []).forEach(g => g.options.forEach(o => { if (mods.indexOf(o.id) >= 0) out.push(o.name); }));
    return out;
  };
  const unit = l => D.items[l.item].p + modsPrice(l.item, l.mods);
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

  /* dish controls: ＋ (Default) or the stepper with the gold ＋ (InCart) */
  function drawDishes() {
    S.$$('[data-dish]').forEach(card => {
      const id = card.dataset.dish;
      const ctl = S.$('[data-ctl]', card);
      if (!ctl) return;
      const q = qtyOf(id);
      card.classList.toggle('is-incart', q > 0);
      ctl.innerHTML = q > 0
        ? '<span class="dstep"><button type="button" data-dish-minus="' + id + '" aria-label="' + S.esc(S.tr('js.qr_less')) + '">' + S.icon('minus', 18) + '</button>'
          + '<span class="dstep__n t-label-l num">' + S.digits(q) + '</span>'
          + '<button type="button" class="dstep__plus" data-add="' + id + '" aria-label="' + S.esc(S.tr('js.qr_more')) + '">' + S.icon('plus', 18) + '</button></span>'
        : '<button type="button" class="dish__add" data-add="' + id + '" aria-label="' + S.esc(S.tr('js.qr_more')) + '">' + S.icon('plus', 20) + '</button>';
    });
  }

  function drawBar() {
    const bar = S.$('[data-open-cart]');
    if (!bar) return;
    const n = count();
    bar.hidden = n === 0;
    S.$('[data-cart-n]', bar).textContent = S.digits(n);
    S.$('[data-cart-total]', bar).textContent = S.money(total());
  }

  function drawCart() {
    const box = S.$('[data-cart-lines]');
    if (!box) return;
    box.innerHTML = cart.lines.map(l => {
      const it = D.items[l.item];
      const note = modNames(l.item, l.mods).concat(l.note ? [l.note] : []).join(', ');
      const img = it.img ? '<img class="cline__img" src="' + S.esc(it.img) + '" alt="">' : '<span class="cline__img">' + S.icon('utensils', 22) + '</span>';
      const noteHtml = note
        ? '<button type="button" class="cline__note t-body-s c-secondary" data-line-note="' + S.esc(l.k) + '">' + S.esc(S.tr('js.qr_note', { note: note })) + '</button>'
        : '<button type="button" class="cline__note t-body-s c-accent" data-line-note="' + S.esc(l.k) + '">' + S.esc(S.tr('js.qr_add_note')) + '</button>';
      return '<div class="cline">' + img
        + '<div class="cline__col"><span class="t-label-l">' + S.esc(it.n) + '</span>' + noteHtml
        + '<span class="t-label-m c-accent num">' + S.money(l.qty * unit(l)) + '</span></div>'
        + '<div class="qty"><button type="button" data-line-step="-1" data-k="' + S.esc(l.k) + '" aria-label="−">' + S.icon('minus', 18) + '</button>'
        + '<span class="qty__n num">' + S.digits(l.qty) + '</span>'
        + '<button type="button" data-line-step="1" data-k="' + S.esc(l.k) + '" aria-label="+">' + S.icon('plus', 18) + '</button></div></div>';
    }).join('');
    S.$('[data-cart-empty]').hidden = cart.lines.length > 0;
    const sum = S.money(total());
    S.$('[data-cart-sub]').textContent = sum;
    S.$('[data-cart-sum]').textContent = sum;
    const send = S.$('[data-send]');
    S.$('span', send).textContent = send.dataset.label.replace('{amount}', sum);
    send.disabled = cart.lines.length === 0;
    const note = S.$('[data-cart-note]');
    if (note && document.activeElement !== note) note.value = cart.note || '';
  }

  function draw() {
    drawDishes();
    drawBar();
    drawCart();
  }

  /* ------------------------------------------------------------ the cart view (Q2), with the phone's back button */
  const views = { menu: S.$('[data-view="menu"]'), cart: S.$('[data-view="cart"]') };
  let menuScroll = 0;
  function show(name) {
    if (name === 'cart') menuScroll = window.scrollY;
    views.menu.hidden = name !== 'menu';
    views.cart.hidden = name !== 'cart';
    window.scrollTo(0, name === 'menu' ? menuScroll : 0);
  }
  window.addEventListener('popstate', () => show(location.hash === '#cart' ? 'cart' : 'menu'));
  if (location.hash === '#cart') history.replaceState(null, '', location.pathname + location.search);

  /* ------------------------------------------------------------ option sheet (items with choices) */
  const opts = S.$('#guest-opts');
  let optItem = null;
  function openOptions(id) {
    const it = D.items[id];
    optItem = id;
    S.$('[data-opts-title]', opts).textContent = it.n;
    S.$('[data-opts-body]', opts).innerHTML = it.g.map((g, gi) => {
      const plus = p => p > 0 ? ' +' + S.money(p) : '';
      const head = '<div class="overline">' + S.esc(g.name) + '</div>';
      const body = g.multi
        ? g.options.map(o => '<label class="checkrow checkrow--opt"><span class="check"><input type="checkbox" name="g' + gi + '" value="' + S.esc(o.id) + '"><span class="check__box">' + S.icon('check', 16) + '</span></span><span class="t-body-l">' + S.esc(o.name + plus(o.price)) + '</span></label>').join('')
        : '<div class="chips chips--wrap">' + g.options.map((o, k) => '<label class="chip"><input type="radio" name="g' + gi + '" value="' + S.esc(o.id) + '"' + (k === 0 && g.min > 0 ? ' checked' : '') + '>' + S.esc(o.name + plus(o.price)) + '</label>').join('') + '</div>';
      return '<div class="optgroup' + (g.multi ? ' optgroup--multi' : '') + '" data-g="' + gi + '">' + head + body + '</div>';
    }).join('');
    const f = S.$('[data-opts]', opts);
    f.elements.line_note.value = '';
    f.elements.qty.value = 1;
    S.$('[data-stepper-n]', opts).textContent = S.digits(1);
    optPrice();
    S.openSheet(opts);
  }
  function optChosen() {
    return S.$$('input:checked', S.$('[data-opts-body]', opts)).map(i => i.value);
  }
  function optPrice() {
    const f = S.$('[data-opts]', opts);
    const q = parseInt(f.elements.qty.value, 10) || 1;
    const btn = S.$('[type=submit]', f);
    S.$('span', btn).textContent = btn.dataset.label.replace('{amount}', S.money(q * (D.items[optItem].p + modsPrice(optItem, optChosen()))));
  }
  if (opts) {
    opts.addEventListener('change', optPrice);
    S.$('[data-opts]', opts).addEventListener('submit', e => {
      e.preventDefault();
      const it = D.items[optItem];
      const chosen = optChosen();
      for (let gi = 0; gi < it.g.length; gi++) {
        const g = it.g[gi];
        const n = g.options.filter(o => chosen.indexOf(o.id) >= 0).length;
        if (n < g.min || (g.max > 0 && n > g.max)) {
          S.toast(S.tr('js.qr_choose', { name: g.name }), 'error');
          return;
        }
      }
      const f = e.target;
      add(optItem, parseInt(f.elements.qty.value, 10) || 1, chosen, f.elements.line_note.value);
      S.closeSheet(opts);
    });
  }

  /* ------------------------------------------------------------ note of a cart line ("Not ekle") */
  const noteSheet = S.$('#guest-note');
  let noteKey = null;
  if (noteSheet) {
    S.$('[data-note-form]', noteSheet).addEventListener('submit', e => {
      e.preventDefault();
      const line = cart.lines.find(l => l.k === noteKey);
      if (line) {
        const note = e.target.elements.line_note.value.trim();
        const k = lineKey(line.item, line.mods, note);
        const same = cart.lines.find(l => l.k === k && l !== line);
        if (same) {
          same.qty = Math.min(D.max, same.qty + line.qty);
          cart.lines = cart.lines.filter(l => l !== line);
        } else {
          line.note = note;
          line.k = k;
        }
        save();
      }
      S.closeSheet(noteSheet);
    });
  }

  /* ------------------------------------------------------------ clicks */
  document.addEventListener('click', e => {
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
    const ln = e.target.closest('[data-line-note]');
    if (ln) {
      noteKey = ln.dataset.lineNote;
      const line = cart.lines.find(l => l.k === noteKey);
      S.$('#f-guest-note').value = line ? line.note : '';
      S.openSheet(noteSheet);
      return;
    }
    if (e.target.closest('[data-open-cart]')) {
      history.pushState({ cart: 1 }, '', '#cart');
      show('cart');
      return;
    }
    if (e.target.closest('[data-close-cart]')) {
      if (location.hash === '#cart') history.back();
      else show('menu');
    }
  });

  const noteInput = S.$('[data-cart-note]');
  if (noteInput) noteInput.addEventListener('input', () => {
    cart.note = noteInput.value;
    try { localStorage.setItem(key, JSON.stringify(cart)); } catch (e) { /* private mode */ }
  });

  /* ------------------------------------------------------------ send */
  const send = S.$('[data-send]');
  if (send) send.addEventListener('click', async () => {
    if (!cart.lines.length) return;
    send.classList.add('is-busy');
    try {
      const r = await S.api(location.pathname.replace(/\/$/, '') + '/order', {
        lines: cart.lines.map(l => ({ item: l.item, qty: l.qty, mods: l.mods, note: l.note })),
        note: cart.note || '',
      }, { quiet: true });
      cart = { lines: [], note: '' };
      try { localStorage.removeItem(key); } catch (e) { /* private mode */ }
      location.href = r.redirect;
    } catch (err) {
      S.toast(err.message, 'error');
      if (err.data && err.data.closed) setTimeout(() => location.reload(), 1500);
    } finally {
      send.classList.remove('is-busy');
    }
  });

  railAndSearch();
  draw();

  /* ------------------------------------------------------------ search and the category rail (scroll-spy) */
  function railAndSearch() {
    const search = S.$('[data-guest-search]');
    const cats = S.$$('[data-cat]');
    const none = S.$('[data-guest-none]');
    if (search) search.addEventListener('input', () => {
      // the same folding as the server's data-search: I, İ and ı all count as i
      const q = search.value.trim().replace(/[İIı]/g, 'i').toLowerCase();
      let any = false;
      cats.forEach(c => {
        let shown = 0;
        S.$$('[data-dish]', c).forEach(d => {
          const hit = !q || d.dataset.search.indexOf(q) >= 0;
          d.hidden = !hit;
          if (hit) shown++;
        });
        c.hidden = shown === 0;
        any = any || shown > 0;
      });
      if (none) none.hidden = any;
    });
    const chips = S.$$('[data-rail]');
    if (!chips.length || !('IntersectionObserver' in window)) return;
    const select = id => chips.forEach(ch => {
      const on = ch.dataset.rail === id;
      ch.classList.toggle('is-selected', on);
      if (on) ch.scrollIntoView({ block: 'nearest', inline: 'center', behavior: 'smooth' });
    });
    let lock = 0;
    chips.forEach(ch => ch.addEventListener('click', e => {
      e.preventDefault();
      const sec = document.getElementById('c-' + ch.dataset.rail);
      if (!sec) return;
      lock = Date.now();
      select(ch.dataset.rail);
      sec.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }));
    const io = new IntersectionObserver(entries => {
      if (Date.now() - lock < 900) return;
      const vis = entries.filter(en => en.isIntersecting).sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);
      if (vis.length) select(vis[0].target.dataset.cat);
    }, { rootMargin: '-72px 0px -60% 0px' });
    cats.forEach(c => io.observe(c));
  }
})();
