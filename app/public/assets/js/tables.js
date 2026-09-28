/* Table map (W1/W11): desktop tiles select a table into the right panel, phone tiles open the W7 sheet
   (a free table goes straight to the menu); the search sheet jumps to a table number. */
(function () {
  'use strict';
  const S = window.SOFREXA;
  const desktop = () => window.matchMedia('(min-width: 1024px)').matches;
  const panel = S.$('[data-table-panel]');

  async function select(tile) {
    S.$$('.ttile.is-selected').forEach(t => t.classList.remove('is-selected'));
    tile.classList.add('is-selected');
    const id = tile.dataset.table;
    const u = new URL(location.href);
    u.searchParams.set('t', id);
    history.replaceState(null, '', u);
    const res = await S.api('/tables/' + id + '/panel');
    panel.innerHTML = res.html;
  }

  document.addEventListener('click', e => {
    const tile = e.target.closest('.ttile[data-table]');
    if (!tile) return;
    // a guest's QR order waiting: the approval sheet (W5)
    if (tile.dataset.qr) {
      e.preventDefault();
      S.loadSheet('/qr/orders/' + tile.dataset.qr).catch(() => {});
      return;
    }
    if (desktop() && tile.hasAttribute('data-select')) {
      e.preventDefault();
      if (tile.classList.contains('is-selected') && tile.dataset.state === 'free') { location.href = '/tables/' + tile.dataset.table + '/order'; return; }
      select(tile).catch(() => {});
      return;
    }
    if (!desktop() && tile.dataset.state !== 'free') {
      e.preventDefault();
      S.loadSheet('/tables/' + tile.dataset.table + '/actions').catch(() => {});
    }
  });
  // double click on desktop opens the order screen
  document.addEventListener('dblclick', e => {
    const tile = e.target.closest('.ttile[data-table]');
    if (tile && desktop()) location.href = '/tables/' + tile.dataset.table + '/order';
  });

  // search: jump to a table by its number
  const find = S.$('[data-table-search]');
  if (find) find.addEventListener('click', () => S.openSheet('table-find'));
  const form = S.$('[data-table-find]');
  if (form) form.addEventListener('submit', e => {
    e.preventDefault();
    const n = form.elements.n.value.trim().replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d));
    const tile = S.$$('.tiles:not(.tiles--desk) .ttile, .tiles--desk .ttile').find(t => t.querySelector('.ttile__no').textContent.replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)) === n);
    if (tile) { S.closeSheet(form.closest('.scrim')); tile.click(); tile.scrollIntoView({ block: 'center' }); }
    else S.toast(S.tr('js.error'), 'error');
  });

  // keep the map fresh while it is open (other waiters, the kitchen, QR orders)
  let last = Date.now();
  document.addEventListener('status', () => {
    if (Date.now() - last > 28000 && !S.$('.scrim:not([hidden])')) { last = Date.now(); location.reload(); }
  });
})();
