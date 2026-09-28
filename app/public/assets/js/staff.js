/* Staff (ST1–ST4): the shift ring clock, payroll selection and the pay sheet, PIN reset, month picker. */
(function () {
  'use strict';
  const S = window.SOFREXA;

  /* ST3: elapsed time in the ring */
  const ring = S.$('[data-since]');
  if (ring) {
    const out = S.$('[data-ring-time]', ring);
    const tick = () => {
      const min = Math.max(0, Math.floor((Date.now() - Number(ring.dataset.since)) / 60000));
      out.textContent = S.digits(Math.floor(min / 60) + ':' + String(min % 60).padStart(2, '0'));
    };
    tick();
    setInterval(tick, 20000);
  }

  S.$$('select[data-go]').forEach(sel => sel.addEventListener('change', () => { location.href = sel.dataset.go + sel.value; }));

  /* ST2: pick rows, pay the picked ones */
  const picks = () => S.$$('[data-payroll] [data-pick]');
  const buttons = S.$$('[data-pay-selected]');
  function sync() {
    const n = new Set(picks().filter(c => c.checked).map(c => c.value)).size;
    buttons.forEach(b => { b.disabled = n === 0; });
  }
  document.addEventListener('change', e => {
    const all = e.target.closest('[data-pick-all]');
    if (all) picks().forEach(c => { c.checked = all.checked; });
    const one = e.target.closest('[data-pick]');
    if (one) picks().filter(c => c.value === one.value).forEach(c => { c.checked = one.checked; });
    if (all || one) sync();
  });
  buttons.forEach(b => b.addEventListener('click', () => {
    const ids = [...new Set(picks().filter(c => c.checked).map(c => c.value))];
    if (ids.length) S.loadSheet(b.dataset.paySelected + '&ids=' + encodeURIComponent(ids.join(',')));
  }));
  const parse = v => {
    const s = String(v).replace(/[۰-۹]/g, d => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d))).replace(/٬/g, '.').replace(/٫/g, ',').replace(/[^\d,.]/g, '');
    const t = s.includes(',') ? s.replace(/\./g, '').replace(',', '.') : (/\.\d{3}$/.test(s) || (s.match(/\./g) || []).length > 1 ? s.replace(/\./g, '') : s);
    return Math.round((parseFloat(t) || 0) * 100);
  };
  document.addEventListener('sheet:loaded', e => {
    const f = e.detail.querySelector('[data-paysheet]');
    if (!f) return;
    const total = S.$('[data-pay-total]', f);
    const sum = () => { total.textContent = S.money(S.$$('[data-pay-amt]', f).reduce((a, i) => a + parse(i.value), 0)); };
    f.addEventListener('input', sum);
    sum();
  });

  /* ST4: PIN reset shows the new PIN once */
  document.addEventListener('click', async e => {
    const b = e.target.closest('[data-pin-reset]');
    if (!b) return;
    e.preventDefault();
    const form = document.querySelector('[data-user-form]');
    const ask = form ? form.dataset.pinConfirm.replace('{name}', b.dataset.name) : '';
    if (ask && !window.confirm(ask)) return;
    try {
      const r = await S.api(b.dataset.pinReset, {});
      const sheet = document.getElementById('pin-sheet');
      sheet.querySelector('[data-pin-title]').textContent = form ? form.dataset.pinTitle.replace('{name}', b.dataset.name) : b.dataset.name;
      sheet.querySelector('[data-pin-digits]').innerHTML = String(r.pin).split('').map(d => '<b>' + S.digits(d) + '</b>').join('');
      S.openSheet(sheet);
    } catch (err) { /* toast shown */ }
  });
})();
