/* Cancelled dishes (IP1–IP4): the "Masaya ver" / "Personele yaz" sheets — search the list, and the confirm button says
   where the dish goes ("Masa 4 hesabına ekle · ₺650", "Ali’ye yaz · ₺650"). */
(function () {
  'use strict';
  const S = window.SOFREXA;

  function sync(form) {
    const picked = form.querySelector('[data-void-pick]:checked');
    const btn = S.$('[data-void-submit]', form);
    if (!btn) return;
    btn.disabled = !picked;
    const label = btn.querySelector('span') || btn;
    label.textContent = picked ? picked.dataset.label : btn.dataset.empty;
  }

  document.addEventListener('sheet:loaded', e => {
    S.$$('[data-void-form]', e.detail).forEach(sync);
  });
  document.addEventListener('change', e => {
    const form = e.target.closest('[data-void-form]');
    if (form) sync(form);
  });
  document.addEventListener('input', e => {
    const q = e.target.closest('[data-void-search]');
    if (!q) return;
    const v = q.value.trim().toLocaleLowerCase();
    S.$$('[data-void-row]', q.closest('[data-void-form]')).forEach(r => { r.hidden = v !== '' && r.dataset.voidRow.indexOf(v) < 0; });
  });
})();
