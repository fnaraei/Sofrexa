<?php
/**
 * Users and sign-in — Figma ST8 (104:836, desktop table) and ST9 (104:1339, mobile edit sheet).
 * @var array $users @var array $counts @var string $filter @var array $roles
 */
use Sofrexa\View\Ui;

$me = user();
$toneRole = static fn(array $u): string => $u['role_code'] === 'manager' ? 'solid' : 'neutral';
$status = static fn(array $u): string => match ($u['status']) {
    'locked' => Ui::badge(t('ui.locked'), 'warning', true),
    'passive' => Ui::badge(t('ui.passive'), 'neutral', true),
    default => Ui::badge(t('ui.active'), 'success', true),
};
$json = static fn(array $u): string => json_encode([
    'id' => $u['id'], 'name' => $u['name'], 'phone' => $u['phone'], 'email' => $u['email'], 'role_id' => $u['role_id'],
    'role' => $u['role_label'], 'remote_login' => (bool) $u['remote_login'], 'active' => (bool) $u['active'], 'lang' => $u['lang'],
    'status' => $u['status'], 'since' => $u['created_at'] ? date('Y', intdiv((int) $u['created_at'], 1000)) : null, 'self' => $u['id'] === $me['id'],
], JSON_UNESCAPED_UNICODE);
$headActions = Ui::btn(t('users.add'), ['icon' => 'user-plus', 'attrs' => ['data-user-new' => true]]);
$appActions = [Ui::ibtn('user-plus', t('users.add'), ['class' => 'appbar__act', 'attrs' => ['data-user-new' => true]])];
?>
<div class="chips">
  <?= Ui::chip(t('ui.all'), $filter === 'all', digits($counts['all']), ['href' => url('/staff/users')]) ?>
  <?= Ui::chip(t('ui.active'), $filter === 'active', digits($counts['active']), ['href' => url('/staff/users', ['f' => 'active'])]) ?>
  <?= Ui::chip(t('ui.passive'), $filter === 'passive', digits($counts['passive']), ['href' => url('/staff/users', ['f' => 'passive'])]) ?>
</div>

<?php if (!$users): ?>
  <div class="empty"><?= e(t('users.empty')) ?></div>
<?php else: ?>
<div class="dtable dtable--dense only-desktop">
  <table>
    <thead><tr>
      <th><?= e(t('users.col_user')) ?></th>
      <th class="right"><?= e(t('users.col_role')) ?></th>
      <th style="width:170px"><?= e(t('users.col_login')) ?></th>
      <th style="width:110px"><?= e(t('users.col_status')) ?></th>
      <th style="width:110px"><?= e(t('users.col_last')) ?></th>
      <th style="width:250px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr data-user='<?= e($json($u)) ?>'>
        <td><?= Ui::who($u['name'], $u['role_code'] === 'manager' && $u['email'] ? $u['email'] : ($u['phone'] ?: $u['email'])) ?></td>
        <td class="right"><?= Ui::badge($u['role_label'], $toneRole($u)) ?></td>
        <td><?= e($u['remote'] ? t('users.login_remote') : t('users.login_pin')) ?></td>
        <td><?= $status($u) ?></td>
        <td class="nowrap"><?= e(when_label($u['last_login_at'])) ?></td>
        <td><div class="acts">
          <?= Ui::btn(t('ui.edit'), ['style' => 'ghost', 'size' => 's', 'icon' => 'pencil', 'attrs' => ['data-user-edit' => true]]) ?>
          <?= Ui::btn(t('users.reset'), ['style' => 'ghost', 'size' => 's', 'icon' => 'key', 'attrs' => ['data-user-pin' => true]]) ?>
          <?= Ui::ibtn('trash', t('ui.delete'), ['size' => 's', 'attrs' => ['data-user-delete' => true, 'disabled' => $u['id'] === $me['id'] || !$u['active']]]) ?>
        </div></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
</div>

<div class="list only-mobile">
  <?php foreach ($users as $u): ?>
    <button type="button" class="lrow" data-user='<?= e($json($u)) ?>' data-user-edit>
      <?= Ui::avatar($u['name']) ?>
      <span class="lrow__mid"><span class="lrow__title ellipsis"><?= e($u['name']) ?></span><span class="lrow__sub"><?= e($u['role_label'] . ' · ' . ($u['remote'] ? t('users.login_remote') : t('users.login_pin'))) ?></span></span>
      <?= $status($u) ?>
      <span class="lrow__chev"><?= icon('chevron-right', 20) ?></span>
    </button>
  <?php endforeach ?>
</div>
<?php endif ?>

<div class="note"><?= icon('info', 20) ?><span><?= e(t('users.note')) ?></span></div>

<?= \Sofrexa\Core\View::partial('staff/_user_sheet', ['roles' => $roles]) ?>
