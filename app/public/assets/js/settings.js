/* Settings pages: mirrored phone/desktop toggles, logo upload, instant save on the backup page, restore confirmation (SE9). */
(function () {
  'use strict';
  const S = window.SOFREXA;

  // Phone and desktop variants of the same toggle stay in step (only the desktop one is posted).
  document.addEventListener('change', e => {
    const key = e.target.dataset && e.target.dataset.mirror;
    if (!key) return;
    document.querySelectorAll('[data-mirror="' + key + '"]').forEach(el => { if (el !== e.target) el.checked = e.target.checked; });
    const form = e.target.closest('form[data-autosave]') || document.querySelector('form[data-autosave]');
    if (form && e.target.type === 'checkbox') {
      S.api(form.action, new FormData(form)).then(r => S.toast(r.message)).catch(() => {});
    }
  });

  // Logo: the visible buttons open the hidden file input; choosing a file uploads it.
  const logoFile = document.querySelector('[data-logo-file]');
  document.querySelectorAll('[data-logo-pick]').forEach(b => b.addEventListener('click', () => logoFile && logoFile.click()));
  if (logoFile) logoFile.addEventListener('change', () => { if (logoFile.files.length) logoFile.form.requestSubmit(); });

  // Restore (SE9): fill the sheet from the chosen backup, enable the button only after typing the word.
  const sheet = document.getElementById('restore-sheet');
  document.addEventListener('click', e => {
    const b = e.target.closest('[data-restore]');
    if (!b || !sheet) return;
    e.preventDefault();
    const form = sheet.querySelector('form');
    form.reset();
    form.elements.file.value = b.dataset.restore;
    sheet.querySelector('[data-r-title]').textContent = form.dataset.banner.replace('{when}', b.dataset.when);
    sheet.querySelector('[data-r-text]').textContent = form.dataset.bannerText.replace('{n}', S.digits(b.dataset.later));
    sheet.querySelector('[data-r-go]').disabled = true;
    S.openSheet(sheet);
  });
  document.querySelectorAll('[data-restore-form]').forEach(form => {
    const input = form.querySelector('[data-confirm-word]');
    const go = form.querySelector('[data-r-go]');
    const word = input.dataset.confirmWord;
    const check = () => { go.disabled = input.value.trim().toLocaleUpperCase('tr') !== word; };
    input.addEventListener('input', check);
    form.addEventListener('ajax:done', e => { S.toast(e.detail.message); });
  });
})();
