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
    setTimeout(() => el.remove(), type === 'error' ? 6000 : 3500);
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
      form.dispatchEvent(new CustomEvent('ajax:done', { detail: res }));
      if (res.message) S.toast(res.message);
      else if (!res.redirect && form.dataset.toast !== 'off') S.toast(S.tr('js.saved'));
      if (res.redirect) location.href = res.redirect;
      else if (form.hasAttribute('data-reload')) setTimeout(() => location.reload(), 350);
      else if (form.closest('.scrim') && !form.hasAttribute('data-keep-open')) S.closeSheet(form.closest('.scrim'));
    } catch (err) {
      form.dispatchEvent(new CustomEvent('ajax:fail', { detail: err }));
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
    } catch (err) { /* toast shown */ } finally {
      b.classList.remove('is-busy');
    }
  });

  /* ------------------------------------------------------------ editable cells mark themselves changed */
  document.addEventListener('input', function (e) {
    const box = e.target.closest('.editable');
    if (box) box.classList.toggle('is-changed', e.target.value !== e.target.defaultValue);
  });

  /* ------------------------------------------------------------ sync indicator */
  const syncLabels = { online: 'cloud-check', syncing: 'refresh', offline: 'wifi-off' };
  S.setSync = function (state, label) {
    S.$$('[data-sync]').forEach(el => {
      el.className = 'sync sync--' + state;
      el.innerHTML = S.icon(syncLabels[state] || 'cloud-check', 16) + '<span>' + S.esc(label) + '</span>';
    });
  };
  async function pollStatus() {
    if (!S.user || document.hidden) return;
    try {
      const r = await S.api('/api/status', undefined, { quiet: true });
      S.setSync(r.sync.state, r.sync.label);
      if (r.csrf) S.csrf = r.csrf;
      const dot = S.$('[data-notif-dot]');
      if (dot) dot.hidden = !r.unread;
      document.dispatchEvent(new CustomEvent('status', { detail: r }));
    } catch (e) {
      if (e.message !== 'auth') S.setSync('offline', S.tr('js.offline'));
    }
  }
  if (S.user) {
    setInterval(pollStatus, 15000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) pollStatus(); });
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
