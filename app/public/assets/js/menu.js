/* Menu pages (M1–M4): on-sale switches, language tabs, photo preview, option-group picker,
   category and option-group sheets, drag to reorder categories. */
(function () {
  'use strict';
  const S = window.SOFREXA;
  const $ = (s, r) => (r || document).querySelector(s);
  const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));

  // On-sale switch in the list: saves at once.
  document.addEventListener('change', async e => {
    const t = e.target.closest('[data-toggle-item]');
    if (!t) return;
    try {
      await S.api('/menu/items/' + t.dataset.toggleItem + '/toggle', { field: t.dataset.field, on: t.checked ? 1 : 0 });
      $$('[data-toggle-item="' + t.dataset.toggleItem + '"][data-field="' + t.dataset.field + '"]').forEach(x => { x.checked = t.checked; });
    } catch (err) { t.checked = !t.checked; }
  });
  // Clicking the switch must not open the row.
  document.addEventListener('click', e => { if (e.target.closest('.toggle')) e.stopPropagation(); }, true);

  // TR / EN / RU / FA tabs.
  document.addEventListener('click', e => {
    const tab = e.target.closest('[data-lang-tab]');
    if (!tab) return;
    const box = tab.closest('[data-langtabs]');
    $$('[data-lang-tab]', box).forEach(b => b.classList.toggle('is-active', b === tab));
    $$('[data-lang-pane]', box).forEach(p => { p.hidden = p.dataset.langPane !== tab.dataset.langTab; });
  });
  function resetLangTabs(root) {
    $$('[data-langtabs]', root).forEach(box => { const first = $('[data-lang-tab]', box); if (first) first.click(); });
  }
  // An invalid (required) field in a hidden language pane: show that pane.
  document.addEventListener('invalid', e => {
    const pane = e.target.closest('[data-lang-pane]');
    if (pane && pane.hidden) { const tab = $('[data-lang-tab="' + pane.dataset.langPane + '"]', pane.closest('[data-langtabs]')); if (tab) tab.click(); }
  }, true);

  // Mirrors: phone toggles drive the desktop ones that are posted.
  document.addEventListener('change', e => {
    const el = e.target;
    if (el.dataset.mirror) $$('[data-mirror="' + el.dataset.mirror + '"]').forEach(x => { if (x !== el) x.checked = el.checked; });
    if (el.dataset.mirrorBoth) el.dataset.mirrorBoth.split(' ').forEach(k => $$('[data-mirror="' + k + '"]').forEach(x => { x.checked = el.checked; }));
    if (el.dataset.mirrorSelect) { const t = document.querySelector('[name="' + el.dataset.mirrorSelect + '"]'); if (t) t.value = el.value; }
  });

  // Photo: preview before saving.
  const photoInput = $('[data-photo-input]');
  $$('[data-photo-pick]').forEach(b => b.addEventListener('click', () => photoInput && photoInput.click()));
  if (photoInput) photoInput.addEventListener('change', () => {
    const f = photoInput.files[0];
    if (!f) return;
    const prev = $('[data-photo-preview]');
    const img = document.createElement('img');
    img.src = URL.createObjectURL(f);
    img.setAttribute('data-photo-preview', '');
    prev.replaceWith(img);
  });

  // Option groups attached to the item (M2 "Seçenekler").
  const apply = $('[data-groups-apply]');
  if (apply) apply.addEventListener('click', () => {
    const box = $('[data-attached]');
    box.innerHTML = '';
    const picked = $$('[data-pick-group]').filter(c => c.checked).map(c => JSON.parse(c.dataset.pickGroup));
    if (!picked.length) box.innerHTML = '<p class="t-body-s c-muted">' + S.esc(box.dataset.empty || '') + '</p>';
    picked.forEach(g => {
      const row = document.createElement('div');
      row.className = 'optview__row';
      row.innerHTML = '<input type="hidden" name="groups[]" value="' + S.esc(g.id) + '"><span class="optview__label">' + S.esc(g.label) + '</span><div class="chips chips--wrap gap-6">'
        + g.options.map(o => '<span class="chip chip--static">' + S.esc(o) + '</span>').join('') + '</div>';
      box.appendChild(row);
    });
    S.closeSheet(apply.closest('.scrim'));
  });

  // Item form: after saving a new item, go to its page; otherwise stay.
  const itemForm = $('[data-item-form]');
  if (itemForm) itemForm.addEventListener('ajax:done', e => { if (!e.detail.redirect) S.toast(e.detail.message); });

  // Categories: list → edit sheet.
  const catEdit = document.getElementById('cat-edit');
  function openCat(c) {
    const f = $('form', catEdit);
    f.reset();
    resetLangTabs(f);
    f.elements.id.value = c ? c.id : '';
    ['tr', 'en', 'ru', 'fa'].forEach(l => { f.elements['names[' + l + ']'].value = c && c.names[l] ? c.names[l] : ''; });
    $$('input[name=station]', f).forEach(r => { r.checked = r.value === (c ? c.station : 'kitchen'); });
    $$('input[name=section]', f).forEach(r => { r.checked = r.value === (c ? c.section : 'food'); });
    f.elements.vat_rate.value = c ? String(c.vat).replace('.', ',') : '';
    f.elements.active.checked = c ? c.active : true;
    const del = $('[data-cat-delete]', f);
    del.hidden = !c || c.count > 0;
    del.onclick = async () => {
      if (!c || !window.confirm(S.tr('js.confirm'))) return;
      await S.api('/menu/categories/' + c.id + '/delete', {});
      location.reload();
    };
    S.openSheet(catEdit);
  }
  document.addEventListener('click', e => {
    const c = e.target.closest('[data-cat]');
    if (c) { openCat(JSON.parse(c.dataset.cat)); return; }
    if (e.target.closest('[data-cat-new]')) openCat(null);
  });

  // Drag to reorder categories.
  const sortable = $('[data-sortable]');
  if (sortable) {
    let dragging = null;
    sortable.addEventListener('dragstart', e => { dragging = e.target.closest('[data-id]'); if (dragging) dragging.classList.add('is-dragging'); });
    sortable.addEventListener('dragover', e => {
      e.preventDefault();
      const over = e.target.closest('[data-id]');
      if (!dragging || !over || over === dragging) return;
      const r = over.getBoundingClientRect();
      over.parentNode.insertBefore(dragging, e.clientY > r.top + r.height / 2 ? over.nextSibling : over);
    });
    sortable.addEventListener('dragend', async () => {
      if (!dragging) return;
      dragging.classList.remove('is-dragging');
      dragging = null;
      await S.api(sortable.dataset.sortable, { ids: $$('[data-id]', sortable).map(x => x.dataset.id) });
      S.toast(S.tr('js.saved'));
    });
  }

  // Option groups: list → edit sheet with option rows.
  const grpEdit = document.getElementById('group-edit');
  function optRow(box, o) {
    const tpl = $('[data-option-tpl]', grpEdit);
    const row = tpl.content.firstElementChild.cloneNode(true);
    const set = (k, v) => { $('[data-o="' + k + '"]', row).value = v || ''; };
    set('id', o && o.id);
    ['tr', 'en', 'ru', 'fa'].forEach(l => set(l, o && o.names[l]));
    set('price', o && o.price ? S.money(o.price).replace('₺', '') : '');
    $('[data-o-remove]', row).addEventListener('click', () => row.remove());
    box.appendChild(row);
  }
  function openGroup(g) {
    const f = $('form', grpEdit);
    f.reset();
    resetLangTabs(f);
    f.elements.id.value = g ? g.id : '';
    ['tr', 'en', 'ru', 'fa'].forEach(l => { f.elements['names[' + l + ']'].value = g && g.names[l] ? g.names[l] : ''; });
    $$('input[name=kind]', f).forEach(r => { r.checked = r.value === (g ? g.kind : 'single'); });
    f.elements.required.checked = !!(g && g.required);
    const box = $('[data-options]', f);
    box.innerHTML = '';
    (g ? g.options : [null, null]).forEach(o => optRow(box, o));
    const del = $('[data-group-delete]', f);
    del.hidden = !g;
    del.onclick = async () => {
      if (!g || !window.confirm(S.tr('js.confirm'))) return;
      await S.api('/menu/groups/' + g.id + '/delete', {});
      location.reload();
    };
    S.openSheet(grpEdit);
  }
  if (grpEdit) {
    $('[data-o-add]', grpEdit).addEventListener('click', () => optRow($('[data-options]', grpEdit), null));
    // Option rows are posted as options[i][...] in their current order.
    $('form', grpEdit).addEventListener('submit', () => {
      $$('.optrow', grpEdit).forEach((row, i) => {
        $$('[data-o]', row).forEach(inp => {
          const k = inp.dataset.o;
          inp.name = k === 'id' || k === 'price' ? 'options[' + i + '][' + k + ']' : 'options[' + i + '][names][' + k + ']';
        });
      });
    }, true);
  }
  document.addEventListener('click', e => {
    const g = e.target.closest('[data-group]');
    if (g) { openGroup(JSON.parse(g.dataset.group)); return; }
    if (e.target.closest('[data-group-new]')) openGroup(null);
  });
})();
