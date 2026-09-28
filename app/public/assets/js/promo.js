/* Promotions (Figma PR1–PR4): list toggles, and the editor — language tabs, what it applies to, the dish search,
   "+N kategori" and the live preview ("Margarita ₺650 → ₺520"). The desktop editor is a dialog loaded on demand. */
(function () {
  'use strict';
  const S = window.SOFREXA;

  /* list: the on/off switch works in place and does not open the editor */
  document.addEventListener('click', e => {
    if (e.target.closest('.promo__toggle')) e.stopPropagation();
  }, true);
  document.addEventListener('change', async e => {
    const t = e.target.closest('[data-promo-toggle]');
    if (!t) return;
    try {
      await S.api('/menu/promotions/' + t.dataset.promoToggle + '/toggle', { on: t.checked ? 1 : 0 });
      setTimeout(() => location.reload(), 250);
    } catch (err) { t.checked = !t.checked; }
  });
  document.addEventListener('keydown', e => {
    const row = e.target.closest && e.target.closest('.promorow[data-load-sheet]');
    if (row && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); S.loadSheet(row.dataset.loadSheet).catch(() => {}); }
  });

  /* editor */
  const previews = () => S.$$('[data-promo-preview]');
  let timer = null;
  async function preview(form) {
    const fd = new FormData(form);
    const body = { scope: fd.get('scope') || 'all', pct: fd.get('pct') || '0', targets: fd.getAll('targets[]') };
    try {
      const r = await S.api('/menu/promotions/preview', body, { quiet: true });
      previews().forEach(p => {
        S.$('[data-preview-title]', p).textContent = S.digits(r.title);
        S.$('[data-preview-sub]', p).textContent = S.digits(r.sub);
      });
      S.$$('[data-promo-save]').forEach(b => { b.disabled = !r.n; });
    } catch (err) { /* keep the last one */ }
  }
  function sync(form) {
    const scope = (form.querySelector('[name=scope]:checked') || {}).value || 'all';
    S.$$('[data-scope-pane]', form).forEach(p => { p.hidden = p.dataset.scopePane !== scope; });
    clearTimeout(timer);
    timer = setTimeout(() => preview(form), 200);
  }
  function init(root) {
    S.$$('[data-promo-form]', root).forEach(form => {
      if (form.dataset.ready) return;
      form.dataset.ready = '1';
      form.addEventListener('input', () => sync(form));
      form.addEventListener('change', () => sync(form));
      sync(form);
    });
  }
  document.addEventListener('sheet:loaded', e => init(e.detail));
  init(document);

  document.addEventListener('click', e => {
    const tab = e.target.closest('[data-lang-tab]');
    if (tab) {
      const box = tab.closest('[data-langtabs]');
      S.$$('[data-lang-tab]', box).forEach(t => t.classList.toggle('is-active', t === tab));
      S.$$('[data-lang-pane]', box).forEach(p => { p.hidden = p.dataset.langPane !== tab.dataset.langTab; });
      return;
    }
    const more = e.target.closest('[data-more-cats]');
    if (more) {
      S.$$('[data-more]', more.parentElement).forEach(c => { c.hidden = false; });
      more.remove();
    }
  });
  document.addEventListener('input', e => {
    const q = e.target.closest('[data-item-search]');
    if (!q) return;
    const v = q.value.trim().toLocaleLowerCase();
    S.$$('[data-item-row]', q.closest('[data-scope-pane]')).forEach(r => { r.hidden = v !== '' && r.dataset.itemRow.indexOf(v) < 0; });
  });
  /* a required name hidden in another language tab: show that tab */
  document.addEventListener('invalid', e => {
    const pane = e.target.closest && e.target.closest('[data-lang-pane]');
    if (pane && pane.hidden) {
      const tab = S.$('[data-lang-tab="' + pane.dataset.langPane + '"]', pane.closest('[data-langtabs]'));
      if (tab) tab.click();
    }
  }, true);
})();
