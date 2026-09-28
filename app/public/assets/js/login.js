/* PIN sign-in (Figma L1–L3): pick a name, type 4 digits on the keypad or the keyboard. */
(function () {
  'use strict';
  const S = window.SOFREXA;
  const root = document.querySelector('[data-login]');
  if (!root) return;

  // Clock on the till PC brand panel.
  const clock = root.querySelector('[data-clock]');
  if (clock) {
    const tick = () => {
      const d = new Date();
      clock.textContent = S.digits(String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0'));
    };
    tick();
    setInterval(tick, 5000);
  }

  const box = root.querySelector('[data-pin-login]');
  if (!box) return;
  const dots = Array.from(box.querySelectorAll('[data-dots] i'));
  const dotsBox = box.querySelector('[data-dots]');
  const people = Array.from(box.querySelectorAll('.sp'));
  let userId = people.length ? people[0].dataset.user : null;
  let pin = '';
  let busy = false;
  let lockedUntil = 0;

  // Remember the last person who signed in on this device.
  try {
    const last = localStorage.getItem('sofrexa.lastUser');
    const p = last && people.find(x => x.dataset.user === last);
    if (p) select(p);
  } catch (e) { /* storage blocked */ }

  function select(p) {
    people.forEach(x => { x.classList.toggle('is-selected', x === p); x.setAttribute('aria-checked', x === p ? 'true' : 'false'); });
    userId = p.dataset.user;
    pin = '';
    draw();
    p.scrollIntoView({ block: 'nearest', inline: 'center' });
  }

  function draw() {
    dots.forEach((d, i) => d.classList.toggle('is-on', i < pin.length));
  }

  async function submit() {
    if (busy || !userId) return;
    if (Date.now() < lockedUntil) {
      S.toast(S.tr('js.locked_wait').replace('{wait}', Math.ceil((lockedUntil - Date.now()) / 1000)), 'error');
      pin = '';
      draw();
      return;
    }
    busy = true;
    try {
      const res = await S.api('/login/pin', { user_id: userId, pin: pin, next: root.dataset.next }, { quiet: true });
      try { localStorage.setItem('sofrexa.lastUser', userId); } catch (e) { /* ignore */ }
      location.href = res.redirect || '/';
    } catch (err) {
      pin = '';
      draw();
      dotsBox.classList.remove('is-error');
      void dotsBox.offsetWidth;
      dotsBox.classList.add('is-error');
      if (err.data && err.data.wait) lockedUntil = Date.now() + err.data.wait * 1000;
      S.toast(err.message, 'error');
      if (navigator.vibrate) navigator.vibrate(120);
    } finally {
      busy = false;
    }
  }

  function press(k) {
    if (busy) return;
    dotsBox.classList.remove('is-error');
    if (k === 'back') {
      pin = pin.slice(0, -1);
    } else {
      pin = (pin + k).slice(0, 4);
    }
    draw();
    if (pin.length === 4) setTimeout(submit, 80);
  }

  people.forEach(p => p.addEventListener('click', () => select(p)));
  box.querySelectorAll('[data-key]').forEach(b => b.addEventListener('click', () => press(b.dataset.key)));

  document.addEventListener('keydown', function (e) {
    if (e.target.closest('input, textarea')) return;
    const fa = '۰۱۲۳۴۵۶۷۸۹'.indexOf(e.key);
    if (/^[0-9]$/.test(e.key)) press(e.key);
    else if (fa >= 0) press(String(fa));
    else if (e.key === 'Backspace') press('back');
    else if (e.key === 'Enter' && pin.length === 4) submit();
    else if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
      const i = people.findIndex(p => p.dataset.user === userId);
      const dir = (e.key === 'ArrowRight') === (document.dir !== 'rtl') ? 1 : -1;
      const n = people[(i + dir + people.length) % people.length];
      if (n) select(n);
    }
  });
})();
