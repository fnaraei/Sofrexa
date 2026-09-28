/* Users and sign-in (ST8/ST9): open the edit sheet for a row or a new user, reset PIN, send password link, deactivate. */
(function () {
  'use strict';
  const S = window.SOFREXA;
  const scrim = document.getElementById('user-sheet');
  if (!scrim) return;
  const form = scrim.querySelector('[data-user-form]');
  const $ = sel => form.querySelector(sel);
  const pinSheet = document.getElementById('pin-sheet');
  let current = null;
  const L = {
    edit: scrim.querySelector('.sheet__title').textContent,
  };
  const statusBadge = {
    locked: ['badge--warning', 'ui.locked'],
    passive: ['', 'ui.passive'],
  };

  function initials(name) {
    return name.trim().split(/\s+/).slice(0, 2).map(p => p.charAt(0).toLocaleUpperCase('tr')).join('') || '?';
  }

  function open(u) {
    current = u;
    form.reset();
    form.querySelectorAll('.field.is-error').forEach(f => f.classList.remove('is-error'));
    const isNew = !u;
    scrim.querySelector('.sheet__title').textContent = isNew ? form.dataset.newTitle : L.edit;
    form.elements.id.value = u ? u.id : '';
    form.elements.name.value = u ? u.name : '';
    form.elements.phone.value = u && u.phone ? u.phone : '';
    form.elements.email.value = u && u.email ? u.email : '';
    form.elements.remote_login.checked = !!(u && u.remote_login);
    form.elements.active.checked = !u || u.active;
    const roleInput = u ? form.querySelector('input[name=role_id][value="' + u.role_id + '"]') : form.querySelector('input[name=role_id]');
    if (roleInput) roleInput.checked = true;

    $('[data-u-who]').hidden = isNew;
    if (u) {
      $('[data-u-initials]').textContent = initials(u.name);
      $('[data-u-name]').textContent = u.name;
      $('[data-u-since]').textContent = u.since ? form.dataset.since.replace('{role}', u.role).replace('{year}', S.digits(u.since)) : u.role;
      const st = statusBadge[u.status];
      $('[data-u-status]').innerHTML = st ? '<span class="badge ' + st[0] + '"><i class="badge__dot"></i>' + S.esc(form.dataset[st[1] === 'ui.locked' ? 'locked' : 'passive']) + '</span>' : '';
    }
    $('[data-u-security]').hidden = isNew;
    $('[data-u-activebox]').hidden = isNew || u.active;
    $('[data-u-delete]').hidden = isNew || u.self || !u.active;
    syncRemote();
    S.openSheet(scrim);
  }

  function syncRemote() {
    const on = form.elements.remote_login.checked;
    $('[data-u-email]').hidden = !on && !(current && current.email);
    $('[data-u-reset-pw]').hidden = !current || !on || !current.email;
  }

  function showPin(name, pin) {
    pinSheet.querySelector('[data-pin-title]').textContent = form.dataset.pinTitle.replace('{name}', name);
    pinSheet.querySelector('[data-pin-digits]').innerHTML = pin.split('').map(d => '<b>' + S.digits(d) + '</b>').join('');
    S.closeSheet(scrim);
    S.openSheet(pinSheet);
  }

  async function resetPin(u) {
    if (!window.confirm(form.dataset.pinConfirm.replace('{name}', u.name))) return;
    const r = await S.api('/staff/users/' + u.id + '/pin', {});
    showPin(u.name, r.pin);
  }

  async function remove(u) {
    if (!window.confirm(form.dataset.deleteConfirm.replace('{name}', u.name))) return;
    const r = await S.api('/staff/users/' + u.id + '/delete', {});
    S.toast(r.message);
    setTimeout(() => location.reload(), 400);
  }

  const rowUser = el => JSON.parse(el.closest('[data-user]').dataset.user);

  document.addEventListener('click', e => {
    if (e.target.closest('[data-user-new]')) { e.preventDefault(); open(null); return; }
    const t = e.target.closest('[data-user-edit], [data-user-pin], [data-user-delete]');
    if (!t || t.disabled) return;
    const u = rowUser(t);
    if (t.hasAttribute('data-user-edit')) open(u);
    else if (t.hasAttribute('data-user-pin')) resetPin(u).catch(() => {});
    else remove(u).catch(() => {});
  });

  form.elements.remote_login.addEventListener('change', syncRemote);
  $('[data-u-reset-pin]').addEventListener('click', () => current && resetPin(current).catch(() => {}));
  $('[data-u-delete]').addEventListener('click', () => current && remove(current).catch(() => {}));
  $('[data-u-reset-pw]').addEventListener('click', async () => {
    if (!current) return;
    try { const r = await S.api('/staff/users/' + current.id + '/password-link', {}); S.toast(r.message); } catch (e) { /* toast shown */ }
  });

  form.addEventListener('ajax:done', e => {
    const r = e.detail;
    if (r.pin) { showPin(form.elements.name.value, r.pin); return; }
    S.toast(r.message);
    S.closeSheet(scrim);
    setTimeout(() => location.reload(), 400);
  });
  form.addEventListener('ajax:fail', e => {
    const errs = (e.detail.data && e.detail.data.errors) || {};
    Object.keys(errs).forEach(k => {
      const input = form.elements[k];
      const field = input && (input.closest ? input.closest('.field') : null);
      if (field) field.classList.add('is-error');
    });
  });
  pinSheet.addEventListener('sheet:close', () => location.reload());
})();
