/* Customers (CU1–CU7): phone search toggle, statement month, loyalty tiers (add a row), printing the statement. */
(function () {
  'use strict';
  const S = window.SOFREXA;

  const toggle = S.$('[data-search-toggle]');
  if (toggle) toggle.addEventListener('click', () => {
    const f = S.$('.cust-filter');
    f.classList.toggle('is-open');
    if (f.classList.contains('is-open')) f.querySelector('input').focus();
  });

  S.$$('select[data-go]').forEach(sel => sel.addEventListener('change', () => { location.href = sel.dataset.go + sel.value; }));

  /* CU5: "Seviye ekle" adds an empty row */
  const add = S.$('[data-tier-add]');
  if (add) {
    let i = 0;
    add.addEventListener('click', () => {
      const tpl = S.$('[data-tier-tpl]');
      const box = document.createElement('div');
      box.innerHTML = tpl.innerHTML.replace(/__i/g, String(i++));
      const row = box.firstElementChild;
      S.$('[data-tier-rows]').appendChild(row);
      row.querySelector('input').focus();
    });
  }

  /* CU5 tier cells: "10000" → "₺10.000 +", "3" → "%3" */
  document.addEventListener('focusout', e => {
    const f = e.target.closest && e.target.closest('[data-tf]');
    if (!f || f.value.trim() === '') return;
    const raw = f.value.replace(/[۰-۹]/g, d => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d))).replace(/٫/g, ',');
    if (f.dataset.tf === 'pct') {
      const v = parseFloat(raw.replace(/[^\d,.]/g, '').replace(',', '.'));
      if (!isNaN(v)) f.value = '%' + S.digits(String(v).replace('.', ','));
    } else {
      const v = parseInt(raw.replace(/[^\d]/g, ''), 10);
      if (!isNaN(v)) f.value = S.money(v * 100) + ' +';
    }
  });

  S.$$('[data-print]').forEach(b => b.addEventListener('click', () => window.print()));
  if (S.$('[data-print]') && new URLSearchParams(location.search).get('print') === '1') setTimeout(() => window.print(), 300);
})();
