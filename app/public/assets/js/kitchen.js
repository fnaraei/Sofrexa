/* Kitchen screens (K1 TV, K2/K3 chef): timers tick every second, the tickets refresh every 5 s and a new
   ticket plays a sound; Başla / Hazır / call again / tap an item; "Hazır" can be undone for 10 s. */
(function () {
  'use strict';
  const S = window.SOFREXA;
  const root = S.$('[data-kds]');
  if (!root) return;
  const base = root.dataset.base;
  const view = root.dataset.view;
  const station = root.dataset.station;
  let busy = false;
  let seen = null;

  /* ------------------------------------------------------------ sound (WebAudio, unlocked by the first tap) */
  let ctx = null;
  const soundOn = () => { try { return localStorage.getItem('kds-sound') !== 'off'; } catch (e) { return true; } };
  function beep() {
    if (!soundOn()) return;
    try {
      ctx = ctx || new (window.AudioContext || window.webkitAudioContext)();
      [0, 0.28].forEach(at => {
        const o = ctx.createOscillator();
        const g = ctx.createGain();
        o.type = 'sine';
        o.frequency.value = 880;
        g.gain.setValueAtTime(0.0001, ctx.currentTime + at);
        g.gain.exponentialRampToValueAtTime(0.35, ctx.currentTime + at + 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + at + 0.22);
        o.connect(g).connect(ctx.destination);
        o.start(ctx.currentTime + at);
        o.stop(ctx.currentTime + at + 0.25);
      });
    } catch (e) { /* no audio */ }
  }
  document.addEventListener('pointerdown', () => { if (ctx && ctx.state === 'suspended') ctx.resume(); else if (!ctx) { try { ctx = new (window.AudioContext || window.webkitAudioContext)(); } catch (e) {} } }, { once: true });
  function paintSound() { S.$$('[data-kds-sound]').forEach(b => b.classList.toggle('is-off', !soundOn())); }
  document.addEventListener('click', e => {
    const b = e.target.closest('[data-kds-sound]');
    if (!b) return;
    try { localStorage.setItem('kds-sound', soundOn() ? 'off' : 'on'); } catch (err) {}
    paintSound();
    S.toast(S.tr(soundOn() ? 'js.kds_sound_on' : 'js.kds_sound_off'));
    if (soundOn()) beep();
  });
  paintSound();

  /* ------------------------------------------------------------ timers */
  const mmss = s => String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
  setInterval(() => {
    S.$$('[data-timer]:not([data-frozen])', root).forEach(el => {
      const s = parseInt(el.dataset.timer, 10) + 1;
      el.dataset.timer = s;
      el.textContent = S.digits(mmss(s));
    });
  }, 1000);

  /* ------------------------------------------------------------ polling */
  async function poll(force) {
    if (busy || (document.hidden && !force)) return;
    try {
      const res = await S.api(base + '/poll?view=' + view + '&s=' + encodeURIComponent(station), undefined, { quiet: true });
      if (busy) return;
      const box = S.$('[data-kds-tickets]', root);
      if (box) box.innerHTML = res.html;
      const badges = S.$('[data-kds-badges]', root);
      if (badges && res.badges) badges.innerHTML = res.badges;
      S.$$('[data-clock]').forEach(c => { c.textContent = res.clock; });
      const fresh = res.keys.filter(k => k.endsWith('|new'));
      if (seen && fresh.some(k => seen.indexOf(k) === -1)) beep();
      seen = res.keys;
    } catch (e) { /* offline for a moment */ }
  }
  setInterval(poll, 5000);
  poll(true);
  if (view === 'm' && S.$('[data-kds-chef]')) setInterval(() => { if (!busy && !S.$('.scrim:not([hidden])')) location.reload(); }, 30000);

  /* ------------------------------------------------------------ actions */
  async function act(name, body) {
    busy = true;
    try {
      const res = await S.api(base + '/act/' + name, body);
      if (res.message && !res.undo) S.toast(res.message);
      if (res.undo) S.toast(res.message, null, { label: S.tr('js.kds_undo'), run: () => act('recall', body) });
      return res;
    } finally {
      busy = false;
      poll(true);
    }
  }
  root.addEventListener('click', e => {
    const line = e.target.closest('[data-kds-line]');
    if (line) {
      line.classList.toggle('is-done');
      act('line', { line: line.dataset.kdsLine }).catch(() => {});
      return;
    }
    const btn = e.target.closest('[data-kds-act]');
    if (!btn) return;
    const card = btn.closest('[data-order]');
    btn.classList.add('is-busy');
    act(btn.dataset.kdsAct, { order: card.dataset.order, round: card.dataset.round, station: card.dataset.station }).catch(() => {}).finally(() => btn.classList.remove('is-busy'));
  });
})();
