/* Till (C1/C8 filters and search), shift close (C6/C9 live differences), cash moves (C10 filter, C11 sheet). */
(function () {
  'use strict';
  const S = window.SOFREXA;
  const parse = s => {
    s = String(s || '').trim().replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٬\s₺£$€]/g, '').replace('٫', ',');
    if (s.indexOf(',') !== -1) s = s.replace(/\./g, '').replace(',', '.');
    else if ((s.match(/\./g) || []).length > 1 || /\.\d{3}$/.test(s)) s = s.replace(/\./g, '');
    const v = parseFloat(s);
    return isNaN(v) ? null : v;
  };

  /* ------------------------------------------------------------ C1 / C8 */
  const filter = S.$('[data-till-filter]');
  let channel = 'all';
  let q = '';
  function applyTill() {
    S.$$('.tcard').forEach(c => {
      c.hidden = (channel !== 'all' && c.dataset.channel !== channel) || (q !== '' && c.dataset.q.indexOf(q) === -1);
    });
  }
  if (filter) {
    filter.addEventListener('click', e => {
      const chip = e.target.closest('.chip[data-filter]');
      if (!chip) return;
      channel = chip.dataset.filter;
      S.$$('.chip', filter).forEach(c => c.classList.toggle('is-selected', c === chip));
      applyTill();
    });
    // phone label of the tables chip ("Masa")
    const t = filter.querySelector('[data-label-m]');
    if (t && !window.matchMedia('(min-width: 1024px)').matches) t.firstChild.textContent = t.dataset.labelM;
  }
  const search = S.$('[data-till-search]');
  if (search) search.addEventListener('input', () => { q = search.value.trim().toLocaleLowerCase(); applyTill(); });
  if (filter) document.addEventListener('status', () => { if (!S.$('.scrim:not([hidden])') && !q && document.activeElement === document.body) location.reload(); });

  /* ------------------------------------------------------------ C6 / C9 cash count */
  const shift = S.$('[data-shift]');
  if (shift) {
    const fmt = (cur, v) => {
      if (cur === 'TRY') return S.money(Math.round(v * 100));
      const sym = { GBP: '£', USD: '$', EUR: '€' }[cur] || cur;
      return (v < 0 ? '−' : '') + sym + S.digits(Math.abs(v).toFixed(Math.abs(v % 1) > 0.001 ? 2 : 0).replace('.', ','));
    };
    const update = () => {
      let first = null;
      S.$$('.cnt[data-cur]', shift).forEach(row => {
        const cur = row.dataset.cur;
        const exp = parseFloat(row.dataset.expected) / (cur === 'TRY' ? 100 : 1);
        const v = parse(row.querySelector('input').value);
        const out = row.querySelector('[data-diff]');
        if (v === null) { out.textContent = ''; out.className = 'cnt__diff t-label-l'; return; }
        const diff = Math.round((v - exp) * 100) / 100;
        const ok = Math.abs(diff) < 0.005;
        out.textContent = ok ? (window.matchMedia('(min-width: 1024px)').matches ? S.digits('0') : '✓') : fmt(cur, diff).replace(/^₺/, diff > 0 ? '+₺' : '₺');
        out.className = 'cnt__diff t-label-l ' + (ok ? 'c-success' : 'c-danger');
        if (!ok && !first) first = [cur, out.textContent];
      });
      S.$('[data-diff-banner]', shift).hidden = !first;
      S.$('[data-diff-note]', shift).hidden = !first;
      if (first) {
        const title = S.$('[data-diff-title]', shift);
        title.textContent = window.matchMedia('(min-width: 1024px)').matches
          ? S.tr('js.shift_diff', { cur: first[0] === 'TRY' ? 'TL' : first[0], diff: first[1] })
          : S.tr('js.shift_diff_m', { diff: first[1] });
      }
    };
    shift.addEventListener('input', update);
    update();
  }

  /* ------------------------------------------------------------ C10 filter */
  const mf = S.$('[data-move-filter]');
  if (mf) mf.addEventListener('click', e => {
    const chip = e.target.closest('.chip');
    if (!chip) return;
    S.$$('.chip', mf).forEach(c => c.classList.toggle('is-selected', c === chip));
    S.$$('.mrow[data-kind]').forEach(r => { r.hidden = chip.dataset.kind !== '' && r.dataset.kind !== chip.dataset.kind; });
  });

  /* ------------------------------------------------------------ C11 sheet */
  document.addEventListener('sheet:loaded', e => {
    const form = e.detail.querySelector('[data-move-form]');
    if (!form) return;
    const sync = () => {
      const kind = form.querySelector('[name=kind]:checked').value;
      S.$$('[data-reasons]', form).forEach(box => {
        const on = box.dataset.reasons === kind;
        box.hidden = !on;
        box.querySelectorAll('input').forEach((i, n) => { i.disabled = !on; if (on && !box.querySelector('input:checked')) box.querySelector('input').checked = true; });
      });
      const btn = form.querySelector('[data-save-label]');
      btn.querySelector('span').textContent = JSON.parse(btn.dataset.saveLabel)[kind];
      const cur = form.querySelector('[name=currency]:checked');
      form.elements.amount.placeholder = (cur ? cur.dataset.sym : '₺') + '0';
    };
    form.addEventListener('change', ev => {
      if (ev.target.name === 'photo') {
        const st = form.querySelector('[data-photo-state]');
        st.textContent = ev.target.files.length ? st.dataset.added : st.textContent;
      }
      sync();
    });
    sync();
  });
})();
