/* Areas and tables (M5/M6): area sheet, phone QR sheet after picking a table. */
(function () {
  'use strict';
  const S = window.SOFREXA;
  const sheet = document.getElementById('area-edit');
  const phone = () => !window.matchMedia('(min-width: 1024px)').matches;

  function openArea(a) {
    const f = sheet.querySelector('form');
    f.reset();
    const first = f.querySelector('[data-lang-tab]');
    if (first) first.click();
    f.elements.id.value = a ? a.id : '';
    ['tr', 'en', 'ru', 'fa'].forEach(l => { f.elements['names[' + l + ']'].value = a && a.names[l] ? a.names[l] : ''; });
    f.elements.smoking.checked = !!(a && a.smoking);
    S.openSheet(sheet);
  }

  document.addEventListener('click', e => {
    const a = e.target.closest('[data-area]');
    if (a) { openArea(JSON.parse(a.dataset.area)); return; }
    if (e.target.closest('[data-area-new]')) { openArea(null); return; }
    const tile = e.target.closest('[data-table-tile]');
    if (tile && phone()) {
      e.preventDefault();
      location.href = tile.href + '&sheet=1';
    }
  });

  // Language tabs inside the area sheet (same markup as the item editor).
  document.addEventListener('click', e => {
    const tab = e.target.closest('[data-lang-tab]');
    if (!tab) return;
    const box = tab.closest('[data-langtabs]');
    box.querySelectorAll('[data-lang-tab]').forEach(b => b.classList.toggle('is-active', b === tab));
    box.querySelectorAll('[data-lang-pane]').forEach(p => { p.hidden = p.dataset.langPane !== tab.dataset.langTab; });
  });

  if (new URLSearchParams(location.search).get('sheet') === '1' && phone()) S.openSheet('qr-sheet');
})();
