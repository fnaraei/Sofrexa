/* Sofrexa — small client layer shared by every page: fetch with CSRF, toasts, sheets, ajax forms,
   sync indicator, idle lock and the service worker. No framework, no build step. */
(function () {
  'use strict';
  const S = window.SOFREXA || {};
  window.SOFREXA = S;

  S.strings = (typeof S.t === 'object' && S.t) || {};
  /** Translate a js.* key sent by the server: S.tr('js.saved'). */
  S.tr = function (key, params) {
    let s = S.strings[key] || key;
    if (params) Object.keys(params).forEach(k => { s = s.replace('{' + k + '}', params[k]); });
    return s;
  };

  S.$ = (sel, root) => (root || document).querySelector(sel);
  S.$$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));

  S.icon = function (name, size) {
    size = size || 20;
    return '<svg class="ic" width="' + size + '" height="' + size + '" aria-hidden="true"><use href="' + S.icons + '#i-' + name + '"/></svg>';
  };

  S.esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  /** Digits in the user's language (Persian digits for fa). */
  S.digits = function (s) {
    s = String(s);
    return S.lang === 'fa' ? s.replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]) : s;
  };

  /** kuruş → "₺1.234" (Turkish grouping), negative as "−₺5". */
  S.money = function (kurus, plus) {
    const neg = kurus < 0;
    const abs = Math.abs(kurus);
    const lira = Math.floor(abs / 100);
    const rest = abs % 100;
    let s = lira.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    if (rest) s += ',' + String(rest).padStart(2, '0');
    s = (neg ? '−' : (plus ? '+' : '')) + '₺' + s;
    return S.lang === 'fa' ? S.digits(s.replace(/\./g, '٬').replace(/,/g, '٫')) : s;
  };

  /* ------------------------------------------------------------ fetch */
  S.api = async function (url, data, opts) {
    opts = opts || {};
    const init = {
      method: opts.method || (data === undefined ? 'GET' : 'POST'),
      headers: { 'Accept': 'application/json', 'X-CSRF': S.csrf },
      credentials: 'same-origin',
    };
    if (data instanceof FormData) {
      init.body = data;
    } else if (data !== undefined) {
      init.headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(data);
    }
    let res;
    try {
      res = await fetch(url, init);
    } catch (e) {
      if (!opts.quiet) S.toast(S.tr('js.offline'), 'error');
      throw e;
    }
    let json = null;
    try { json = await res.json(); } catch (e) { json = { ok: res.ok }; }
    if (res.status === 401) {
      location.href = '/login?next=' + encodeURIComponent(location.pathname + location.search);
      throw new Error('auth');
    }
    if (!res.ok || json.ok === false) {
      const err = new Error((json && json.error) || S.tr('js.error'));
      err.data = json;
      err.status = res.status;
      if (!opts.quiet) S.toast(err.message, 'error');
      throw err;
    }
    return json;
  };

  /* ------------------------------------------------------------ toasts */
  S.toast = function (msg, type, action) {
    const box = S.$('.toasts');
    if (!box) return;
    const el = document.createElement('div');
    el.className = 'toast' + (type === 'error' ? ' toast--error' : '');
    el.setAttribute('role', type === 'error' ? 'alert' : 'status');
    el.innerHTML = S.icon(type === 'error' ? 'alert' : 'check-circle', 20) + '<span class="toast__msg">' + S.esc(msg) + '</span>';
    if (action) {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'toast__action';
      b.textContent = action.label;
      b.addEventListener('click', () => { action.run(); el.remove(); });
      el.appendChild(b);
    }
    box.appendChild(el);
    setTimeout(() => el.remove(), type === 'error' ? 6000 : (action ? 10000 : 3500));
  };

  /* ------------------------------------------------------------ sheets (bottom sheet / dialog) */
  S.openSheet = function (id) {
    const scrim = typeof id === 'string' ? document.getElementById(id) : id;
    if (!scrim) return null;
    scrim.hidden = false;
    document.body.classList.add('has-sheet');
    const f = scrim.querySelector('[autofocus], input:not([type=hidden]), select, textarea');
    if (f && window.matchMedia('(min-width: 1024px)').matches) setTimeout(() => f.focus(), 30);
    scrim.dispatchEvent(new CustomEvent('sheet:open'));
    return scrim;
  };
  S.closeSheet = function (scrim) {
    if (!scrim) return;
    scrim.hidden = true;
    if (!S.$('.scrim:not([hidden])')) document.body.classList.remove('has-sheet');
    scrim.dispatchEvent(new CustomEvent('sheet:close'));
  };

  document.addEventListener('click', function (e) {
    const open = e.target.closest('[data-sheet]');
    if (open) {
      e.preventDefault();
      const scrim = S.openSheet(open.dataset.sheet);
      if (scrim) scrim.dispatchEvent(new CustomEvent('sheet:from', { detail: open }));
      return;
    }
    const close = e.target.closest('[data-close]');
    if (close) { S.closeSheet(close.closest('.scrim')); return; }
    if (e.target.classList && e.target.classList.contains('scrim')) { S.closeSheet(e.target); return; }
    const row = e.target.closest('[data-href]');
    if (row && !e.target.closest('a, button, input, select, textarea, label')) { location.href = row.dataset.href; }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      const open = S.$$('.scrim:not([hidden])').pop();
      if (open) S.closeSheet(open);
    }
  });

  /* ------------------------------------------------------------ ajax forms and actions */
  document.addEventListener('submit', async function (e) {
    const form = e.target;
    if (!form.matches('[data-ajax]')) return;
    e.preventDefault();
    if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) return;
    const btn = form.querySelector('[type=submit]');
    if (btn) btn.classList.add('is-busy');
    try {
      const res = await S.api(form.action, new FormData(form));
      // bubbles: the page-wide listener below reloads when the server answers { reload: true }
      form.dispatchEvent(new CustomEvent('ajax:done', { detail: res, bubbles: true }));
      if (res.message) S.toast(res.message);
      else if (!res.redirect && form.dataset.toast !== 'off') S.toast(S.tr('js.saved'));
      if (res.redirect) location.href = res.redirect;
      else if (form.hasAttribute('data-reload')) setTimeout(() => location.reload(), 350);
      else if (form.closest('.scrim') && !form.hasAttribute('data-keep-open')) S.closeSheet(form.closest('.scrim'));
    } catch (err) {
      form.dispatchEvent(new CustomEvent('ajax:fail', { detail: err, bubbles: true }));
    } finally {
      if (btn) btn.classList.remove('is-busy');
    }
  });

  /** <button data-post="/url" data-confirm="…" data-body='{"a":1}' data-reload> */
  document.addEventListener('click', async function (e) {
    const b = e.target.closest('[data-post]');
    if (!b) return;
    e.preventDefault();
    if (b.dataset.confirm && !window.confirm(b.dataset.confirm)) return;
    b.classList.add('is-busy');
    try {
      const res = await S.api(b.dataset.post, b.dataset.body ? JSON.parse(b.dataset.body) : {});
      b.dispatchEvent(new CustomEvent('post:done', { detail: res, bubbles: true }));
      if (res.message) S.toast(res.message);
      if (res.redirect) location.href = res.redirect;
      else if (b.hasAttribute('data-reload')) setTimeout(() => location.reload(), 350);
      else if (b.closest('.scrim') && !b.hasAttribute('data-keep-open')) S.closeSheet(b.closest('.scrim'));
    } catch (err) { /* toast shown */ } finally {
      b.classList.remove('is-busy');
    }
  });

  /* ------------------------------------------------------------ sheets loaded from the server */
  /** GET url → {html} (a .scrim[data-dyn]) opened on top, or {redirect}. Removed again when closed. */
  S.loadSheet = async function (url) {
    const res = await S.api(url);
    if (res.redirect) { location.href = res.redirect; return null; }
    S.$$('.scrim:not([hidden])').forEach(s => S.closeSheet(s));
    const box = document.createElement('div');
    box.innerHTML = res.html.trim();
    const scrim = box.firstElementChild;
    document.body.appendChild(scrim);
    scrim.addEventListener('sheet:close', () => setTimeout(() => scrim.remove(), 50));
    S.openSheet(scrim);
    document.dispatchEvent(new CustomEvent('sheet:loaded', { detail: scrim }));
    return scrim;
  };
  document.addEventListener('click', function (e) {
    const b = e.target.closest('[data-load-sheet]');
    if (!b) return;
    e.preventDefault();
    S.loadSheet(b.dataset.loadSheet).catch(() => {});
  });
  document.addEventListener('ajax:done', e => { if (e.detail && e.detail.reload) setTimeout(() => location.reload(), 300); });
  document.addEventListener('post:done', e => { if (e.detail && e.detail.reload) setTimeout(() => location.reload(), 300); });

  /* ------------------------------------------------------------ quantity stepper and reason chips */
  document.addEventListener('click', function (e) {
    const step = e.target.closest('[data-step]');
    if (step) {
      const box = step.closest('[data-stepper]');
      const input = box.querySelector('[data-stepper-v]');
      const min = parseFloat(input.dataset.min || '0');
      const max = parseFloat(input.dataset.max || '99');
      const v = Math.min(max, Math.max(min, (parseFloat(input.value) || 0) + parseFloat(step.dataset.step)));
      input.value = v;
      box.querySelector('[data-stepper-n]').textContent = S.digits(String(v).replace('.', ','));
      input.dispatchEvent(new Event('change', { bubbles: true }));
      return;
    }
    // ready-time chips of an online order to accept: the chosen minutes go with "Onayla"
    const eta = e.target.closest('[data-eta-chips] [data-eta]');
    if (eta) {
      const box = eta.closest('[data-eta-chips]');
      box.querySelectorAll('.chip').forEach(c => c.classList.toggle('is-selected', c === eta));
      const ok = box.closest('.sheet').querySelector('[data-post$="/move/approve"]');
      if (ok) ok.dataset.body = JSON.stringify({ eta: parseInt(eta.dataset.eta, 10) });
      return;
    }
    const chip = e.target.closest('[data-fill] .chip');
    if (chip) {
      const wrap = chip.closest('[data-fill]');
      const field = wrap.closest('form').elements[wrap.dataset.fill];
      if (field) { field.value = chip.dataset.value; field.dispatchEvent(new Event('input', { bubbles: true })); }
      wrap.querySelectorAll('.chip').forEach(c => c.classList.toggle('is-selected', c === chip));
    }
  });

  /* ------------------------------------------------------------ editable cells mark themselves changed */
  document.addEventListener('input', function (e) {
    const box = e.target.closest('.editable');
    if (box) box.classList.toggle('is-changed', e.target.value !== e.target.defaultValue);
  });

  /* ------------------------------------------------------------ "food is ready" alert (W12)
     Waiters carry their own phones, so the alert rings there rather than at the till: a full-screen card
     and a chime that repeats until they answer. A browser only plays sound after a tap, so the first tap
     anywhere unlocks it, and a screen wake lock keeps the phone awake while the app is in front. */
  const serves = !!(S.user && S.user.serves);
  const Ring = {
    ctx: null, lock: null, open: null, timer: null, left: 0,
    on() { try { return localStorage.getItem('sfx-alert') !== 'off'; } catch (e) { return true; } },
    unlock() {
      try {
        this.ctx = this.ctx || new (window.AudioContext || window.webkitAudioContext)();
        if (this.ctx.state === 'suspended') this.ctx.resume();
      } catch (e) { /* no audio on this device */ }
    },
    /** Four rising notes, louder than the kitchen's: it has to carry across a full room. */
    chime() {
      if (!this.on()) return;
      this.unlock();
      const c = this.ctx;
      if (!c) return;
      try {
        [[0, 784], [0.2, 1047], [0.4, 1319], [0.62, 1319]].forEach(function (n) {
          const o = c.createOscillator(), g = c.createGain(), at = c.currentTime + n[0];
          o.type = 'triangle';
          o.frequency.value = n[1];
          g.gain.setValueAtTime(0.0001, at);
          g.gain.exponentialRampToValueAtTime(0.8, at + 0.02);
          g.gain.exponentialRampToValueAtTime(0.0001, at + 0.2);
          o.connect(g).connect(c.destination);
          o.start(at);
          o.stop(at + 0.22);
        });
      } catch (e) { /* no audio on this device */ }
      try { if (navigator.vibrate) navigator.vibrate([150, 80, 150, 80, 250]); } catch (e) { /* no vibration */ }
    },
    async wake() {
      if (!serves || !('wakeLock' in navigator) || document.hidden || this.lock) return;
      try {
        this.lock = await navigator.wakeLock.request('screen');
        this.lock.addEventListener('release', () => { this.lock = null; });
      } catch (e) { /* the browser said no; the phone just dims as usual */ }
    },
    /** The alerts the server sent: ring about the oldest one, and drop the card once it is handled. */
    sync(list) {
      const a = list[0];
      if (!a) return this.close();
      if (a.id !== this.open) this.show(a);
    },
    show(a) {
      this.close();
      this.open = a.id;
      const ready = a.kind !== 'qr';
      const mins = Math.floor((Date.now() - a.at) / 60000);
      const rows = (a.items || []).map(i =>
        // the quantity is already formatted for the reader ("1,5"), so compare it as text
        '<li class="ralert__item"><span>' + S.esc(i.name) + '</span><b>' + (i.qty && i.qty !== '1' ? '×' + S.digits(i.qty) : '') + '</b></li>').join('');
      const el = document.createElement('div');
      el.className = 'ralert' + (ready ? '' : ' ralert--qr');
      el.innerHTML = '<div class="ralert__card" role="alertdialog" aria-live="assertive">'
        + '<span class="ralert__ic">' + S.icon(ready ? 'bell' : 'qr', 64) + '</span>'
        + '<div class="ralert__where">' + S.esc(a.where) + '</div>'
        + (a.area ? '<div class="ralert__area">' + S.esc(a.area) + '</div>' : '')
        + (a.station ? '<span class="ralert__st">' + S.icon('chef-hat', 20) + S.esc(a.station) + '</span>' : '')
        + (rows ? '<ul class="ralert__items">' + rows + '</ul>' : (a.what ? '<ul class="ralert__items"><li class="ralert__item"><span>' + S.esc(a.what) + '</span></li></ul>' : ''))
        + '<div class="ralert__ago">' + S.icon('clock', 20) + S.esc(mins < 1 ? S.tr('js.ring_now') : S.tr('js.ring_ago', { n: S.digits(mins) })) + '</div>'
        + '<div class="ralert__acts">'
        + '<button class="ralert__go" type="button" data-ralert="done">' + S.icon('check', 20) + S.esc(S.tr(ready ? 'js.ring_got' : 'js.ring_review')) + '</button>'
        + '<button class="ralert__later" type="button" data-ralert="later">' + S.esc(S.tr('js.ring_later')) + '</button>'
        + '</div></div>';
      document.body.appendChild(el);
      this.el = el;
      this.left = 20; // about three minutes of ringing, then the card waits quietly
      this.chime();
      this.timer = setInterval(() => { if (--this.left > 0) this.chime(); else this.stop(); }, 8000);
      this.wake();
    },
    stop() { if (this.timer) { clearInterval(this.timer); this.timer = null; } },
    close() {
      this.stop();
      if (this.el) { this.el.remove(); this.el = null; }
      this.open = null;
    },
    /**
     * "Aldım": the waiter is going for the plates, so the alert is handled and the card goes.
     * "Sonra": seen but not done — it stops ringing and stays on the notifications page.
     * A guest order has to be looked at, so "Gözden geçir" opens the notifications page instead.
     */
    async answer(act) {
      const id = this.open;
      if (!id) return;
      const review = act === 'done' && this.el && this.el.classList.contains('ralert--qr');
      this.close();
      try { await S.api('/my/notifications/' + id + '/' + (act === 'done' && !review ? 'done' : 'later'), {}, { quiet: true }); } catch (e) { /* the next poll tries again */ }
      if (review) { location.href = '/my/notifications'; return; }
      pollStatus();
    },
  };
  document.addEventListener('pointerdown', () => Ring.unlock(), { once: true });
  document.addEventListener('click', e => {
    const b = e.target.closest('[data-ralert]');
    if (b) Ring.answer(b.dataset.ralert);
  });
  if (serves) { Ring.wake(); }

  /* ------------------------------------------------------------ sync indicator */
  const syncLabels = { online: 'cloud-check', syncing: 'refresh', offline: 'wifi-off' };
  S.setSync = function (state, label) {
    S.$$('[data-sync]').forEach(el => {
      el.className = 'sync sync--' + state;
      el.innerHTML = S.icon(syncLabels[state] || 'cloud-check', 16) + '<span>' + S.esc(label) + '</span>';
    });
  };
  async function pollStatus() {
    // a waiter keeps asking even behind another tab, so a ready plate still rings
    if (!S.user || (document.hidden && !serves)) return;
    try {
      const r = await S.api('/api/status', undefined, { quiet: true });
      S.setSync(r.sync.state, r.sync.label);
      if (r.csrf) S.csrf = r.csrf;
      const dot = S.$('[data-notif-dot]');
      if (dot) dot.hidden = !r.unread;
      S.$$('[data-offline-banner]').forEach(b => { b.hidden = r.sync.state !== 'offline'; });
      if (serves) Ring.sync(r.alerts || []);
      document.dispatchEvent(new CustomEvent('status', { detail: r }));
    } catch (e) {
      if (e.message !== 'auth') S.setSync('offline', S.tr('js.offline'));
    }
  }
  if (S.user) {
    // a waiter's phone asks more often: a plate the kitchen has made should not sit under the lamp
    setInterval(pollStatus, serves ? 5000 : 15000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) { pollStatus(); Ring.wake(); } });
  }

  /* ------------------------------------------------------------ idle lock (shared devices) */
  if (S.user && S.idleLock > 0) {
    let last = Date.now();
    ['pointerdown', 'keydown', 'scroll'].forEach(ev => document.addEventListener(ev, () => { last = Date.now(); }, { passive: true }));
    setInterval(() => {
      if (Date.now() - last > S.idleLock * 60000) {
        const f = document.createElement('form');
        f.method = 'post';
        f.action = '/logout';
        f.innerHTML = '<input type="hidden" name="_csrf" value="' + S.esc(S.csrf) + '">';
        document.body.appendChild(f);
        f.submit();
      }
    }, 20000);
  }

  /* ------------------------------------------------------------ PWA */
  if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost')) {
    navigator.serviceWorker.register('/sw.js').catch(() => {});
  }
})();

/* Filter forms: submit when a date or select changes. */
document.addEventListener('change', function (e) {
  const f = e.target.closest('form[data-autosubmit]');
  if (f && (e.target.type === 'date' || e.target.tagName === 'SELECT')) f.submit();
});
