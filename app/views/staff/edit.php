<?php
/**
 * Staff edit and permissions — Figma ST4 (42:569): role segments, commission rate and fixed salary, the
 * permission switches (on top of the role), PIN reset and save. What the commission is counted on and a fee per
 * delivery complete the pay model (same Input / Select components). @var array $u @var array $roles @var array $switches
 */
use Sofrexa\Core\I18n;
use Sofrexa\Modules\Customers\Customers;
use Sofrexa\Modules\Staff\Staff;
use Sofrexa\View\Ui;

$since = Staff::since($u);
$roleLabel = \Sofrexa\Modules\Staff\Users::roleLabel($u['role_code'], $u['role_name']);
$sub = $roleLabel . ($since ? ' · ' . t('staff.since', ['y' => digits($since), 'sfx' => Customers::trFrom($since)]) : '');
$noHead = true;
$back = '/staff';
$appActions = [Ui::ibtn('more', t('ui.more'), ['class' => 'appbar__act', 'attrs' => ['data-sheet' => 'staff-more']])];
$bodyClass = 'page-staffedit';
$manager = $u['role_code'] === 'manager';
$bottom = Ui::btn(t('staff.pin_reset'), ['style' => 'secondary', 'size' => 'l', 'icon' => 'key', 'attrs' => ['data-pin-reset' => '/staff/users/' . $u['id'] . '/pin', 'data-name' => $u['name']]])
    . Ui::btn(t('ui.save'), ['size' => 'l', 'icon' => 'check', 'class' => 'grow', 'type' => 'submit', 'attrs' => ['form' => 'staff-form']]);
$bases = [];
foreach (Staff::BASES as $b) {
    $bases[$b] = t('staff.basis.' . $b);
}
$segItems = '';
foreach ($roles as $r) {
    $segItems .= '<label class="seg"><input type="radio" name="role_id" value="' . e($r['id']) . '"' . ($r['id'] === $u['role_id'] ? ' checked' : '') . '>' . e($r['label']) . '</label>';
}
$userJson = json_encode(['id' => $u['id'], 'name' => $u['name'], 'phone' => $u['phone'], 'email' => $u['email'], 'role_id' => $u['role_id'], 'role' => $roleLabel,
    'remote_login' => (bool) $u['remote_login'], 'active' => (bool) $u['active'], 'lang' => $u['lang'], 'status' => $u['active'] ? 'active' : 'passive',
    'since' => $since, 'self' => $u['id'] === (user()['id'] ?? '')], JSON_UNESCAPED_UNICODE);
?>
<form class="staffedit" id="staff-form" method="post" action="/staff/<?= e($u['id']) ?>" data-ajax data-toast="off">
  <?= csrf_field() ?>
  <div class="page-head page-head--item only-desktop">
    <?= Ui::ibtn('arrow-left', t('ui.back'), ['style' => 'secondary', 'href' => '/staff', 'class' => 'ibtn--flip']) ?>
    <?= Ui::avatar($u['name']) ?>
    <div class="page-head__titles"><h1 class="t-heading-xl ellipsis"><?= e($u['name']) ?></h1><p class="t-body-m c-muted"><?= e($sub) ?></p></div>
    <?= Ui::btn(t('staff.login_info'), ['style' => 'ghost', 'icon' => 'user', 'attrs' => ['data-user' => $userJson, 'data-user-edit' => true]]) ?>
    <?= Ui::btn(t('staff.pin_reset'), ['style' => 'secondary', 'icon' => 'key', 'attrs' => ['data-pin-reset' => '/staff/users/' . $u['id'] . '/pin', 'data-name' => $u['name']]]) ?>
    <?= Ui::btn(t('ui.save'), ['icon' => 'check', 'type' => 'submit']) ?>
  </div>
  <div class="segs segs--scroll" role="radiogroup" aria-label="<?= e(t('staff.c_role')) ?>"><?= $segItems ?></div>
  <div class="grid2 grid2--keep">
    <?= Ui::field('commission_pct', ['label' => t('staff.f_rate'), 'icon' => 'percent', 'value' => '%' . I18n::numAuto((float) $u['commission_pct']), 'attrs' => ['inputmode' => 'decimal']]) ?>
    <?= Ui::field('base_salary', ['label' => t('staff.f_fixed'), 'icon' => 'wallet', 'value' => money((int) $u['base_salary']), 'attrs' => ['inputmode' => 'decimal']]) ?>
    <?= Ui::select('pay_basis', $bases, (string) ($u['pay_basis'] ?: 'own'), ['label' => t('staff.f_basis'), 'icon' => 'chart']) ?>
    <?= Ui::field('per_delivery', ['label' => t('staff.f_per_delivery'), 'icon' => 'bike', 'value' => (int) $u['per_delivery'] > 0 ? money((int) $u['per_delivery']) : '', 'placeholder' => '₺0', 'attrs' => ['inputmode' => 'decimal']]) ?>
  </div>
  <span class="overline"><?= e(t('staff.perms')) ?></span>
  <input type="hidden" name="perms[_]" value="1">
  <div class="card togglelist">
    <?php foreach (Staff::SWITCHES as $p): ?>
      <label class="trow"><span class="trow__text"><span class="t-body-m"><?= e(t('staff.perm.' . $p)) ?></span></span>
        <?= Ui::toggle('perms[' . $p . ']', $switches[$p], $manager ? ['disabled' => true] : []) ?></label>
    <?php endforeach ?>
  </div>
  <?php if ($manager): ?><p class="t-body-s c-muted"><?= e(t('staff.perms_manager')) ?></p><?php else: ?><p class="t-body-s c-muted"><?= e(t('staff.perms_note')) ?></p><?php endif ?>
</form>

<div class="scrim" id="staff-more" hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead($u['name']) ?>
    <div class="sheet__body">
      <div class="list list--flush">
        <?= Ui::lrow(t('staff.login_info'), ['icon' => 'user', 'attrs' => ['data-user' => $userJson, 'data-user-edit' => true, 'data-action' => 'user']]) ?>
        <?= Ui::lrow(t('pay2.title'), ['icon' => 'wallet', 'href' => '/staff/pay']) ?>
        <?= Ui::lrow(t('staff.roles'), ['icon' => 'key', 'href' => '/staff/roles']) ?>
      </div>
    </div>
  </div>
</div>
<?= \Sofrexa\Core\View::partial('staff/_user_sheet', ['roles' => $roles]) ?>
