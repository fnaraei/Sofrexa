/* Reports and finance: Z / period pickers, the accountant package (download, share, e-mail), the expense form. */
(function () {
  'use strict';
  const S = window.SOFREXA;

  S.$$('select[data-go]').forEach(sel => sel.addEventListener('change', () => { location.href = sel.dataset.go + sel.value; }));

  /* R4 / R5: download the zip (or share it from a phone), or e-mail it */
  const form = S.$('[data-export]');
  if (form) {
    form.addEventListener('submit', async e => {
      const canShare = navigator.canShare && window.matchMedia('(max-width: 1023px)').matches;
      if (!canShare) return; // desktop: the browser downloads the zip
      e.preventDefault();
      try {
        const res = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'X-CSRF': S.csrf }, credentials: 'same-origin' });
        if (!res.ok) throw new Error((await res.json().catch(() => ({}))).message || res.statusText);
        const name = (res.headers.get('Content-Disposition') || '').match(/filename="([^"]+)"/);
        const file = new File([await res.blob()], name ? name[1] : 'muhasebe.zip', { type: 'application/zip' });
        if (navigator.canShare({ files: [file] })) await navigator.share({ files: [file], title: file.name });
        else {
          const a = document.createElement('a');
          a.href = URL.createObjectURL(file);
          a.download = file.name;
          a.click();
        }
      } catch (err) {
        if (err && err.name !== 'AbortError') S.toast(err.message || String(err), 'error');
      }
    });
    const send = S.$('[data-mail-send]');
    if (send) send.addEventListener('click', async () => {
      const fd = new FormData(form);
      fd.set('action', 'mail');
      fd.set('email', S.$('[data-mail-to]').value.trim());
      send.classList.add('is-busy');
      try {
        const r = await S.api(form.action, fd);
        S.toast(r.message);
        S.closeSheet(send.closest('.scrim'));
      } catch (err) { /* toast shown */ } finally { send.classList.remove('is-busy'); }
    });
  }

  /* FI2: the currency symbol in the amount, the chosen receipt's name */
  const amount = S.$('[data-amount]');
  if (amount) {
    const box = amount.closest('form');
    box.addEventListener('change', e => {
      if (e.target.name === 'currency') amount.placeholder = (e.target.dataset.sym || '₺') + '0';
    });
    const file = S.$('[data-file]', box);
    if (file) file.addEventListener('change', () => {
      const out = S.$('[data-file-name]', box);
      if (file.files[0]) out.textContent = file.files[0].name;
    });
  }
})();
