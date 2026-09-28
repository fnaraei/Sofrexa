<?php
/**
 * Staff — Figma ST1 (42:2 desktop: filter chips, table with state on shift, pay model and today's figure).
 * The phone list is built from the ListRow component. @var array $rows @var array $counts @var string $filter @var int $inToday @var array $roles
 */
use Sofrexa\Core\I18n;
use Sofrexa\Modules\Staff\Staff;
use Sofrexa\View\Ui;

$n = static fn(int $v): string => digits(I18n::num($v));
$sub = t('staff.sub', ['n' => $n($counts['all']), 'a' => $n($counts['active']), 's' => $n($inToday)]);
$appSub = t('staff.sub_m', ['a' => $n($counts['active']), 's' => $n($counts['shift'])]);
$headActions = Ui::btn(t('staff.roles'), ['style' => 'secondary', 'icon' => 'key', 'href' => '/staff/roles'])
    . Ui::btn(t('pay2.title'), ['style' => 'secondary', 'icon' => 'wallet', 'href' => '/staff/pay'])
    . Ui::btn(t('staff.new'), ['icon' => 'user-plus', 'attrs' => ['data-user-new' => true]]);
$appActions = [
    Ui::ibtn('wallet', t('pay2.title'), ['class' => 'appbar__act', 'href' => '/staff/pay']),
    Ui::ibtn('user-plus', t('staff.new'), ['class' => 'appbar__act', 'attrs' => ['data-user-new' => true]]),
];
$bodyClass = 'page-staff';
$url = static fn(string $f): string => '/staff' . ($f !== 'active' ? '?f=' . rawurlencode($f) : '');
$roleNames = array_column($roles, 'label', 'code');
$state = static fn(array $u): string => match ($u['state']) {
    'shift' => Ui::badge(t('staff.st_shift', ['t' => digits(date('H:i', intdiv($u['on_since'], 1000)))]), 'success', true),
    'way' => Ui::badge(t('staff.st_way'), 'info', true),
    'left' => Ui::badge(t('staff.st_left'), 'neutral', true),
    default => Ui::badge(t('staff.st_off'), 'neutral', true),
};
$today = static function (array $u) use ($n): string {
    if ($u['role_code'] === 'courier') {
        $d = Staff::deliveries($u['id'], Staff::dayStart(), \Sofrexa\Core\Clock::ms() + 1);
        return $d ? t('staff.today_del', ['n' => $n($d)]) : '—';
    }
    $t = in_array($u['role_code'], ['waiter', 'manager', 'cashier'], true) ? Staff::tablesToday($u['id']) : 0;
    return $t ? t('staff.today_tables', ['n' => $n($t)]) : '—';
};
?>
<div class="chips chips--scroll">
  <?= Ui::chip(t('ui.active'), $filter === 'active', $n($counts['active']), ['href' => $url('active')]) ?>
  <?= Ui::chip(t('staff.f_shift'), $filter === 'shift', $n($counts['shift']), ['href' => $url('shift')]) ?>
  <?php foreach ($counts['roles'] as $code => $c): ?><?= Ui::chip($roleNames[$code] ?? $code, $filter === 'role:' . $code, $n($c), ['href' => $url('role:' . $code)]) ?><?php endforeach ?>
  <?= Ui::chip(t('staff.f_left'), $filter === 'left', $n($counts['left']), ['href' => $url('left')]) ?>
</div>

<?php if (!$rows): ?>
  <div class="empty"><?= icon('users', 24) ?><div><?= e(t('staff.empty')) ?></div></div>
<?php else: ?>
<section class="card card--pad0 only-desktop">
  <div class="strow strow--head"><span><?= e(t('staff.c_person')) ?></span><span><?= e(t('staff.c_role')) ?></span><span><?= e(t('staff.c_state')) ?></span><span><?= e(t('staff.c_pay')) ?></span><span><?= e(t('staff.c_today')) ?></span><span><?= e(t('staff.c_login')) ?></span></div>
  <?php foreach ($rows as $u): ?>
    <a class="strow" href="/staff/<?= e($u['id']) ?>">
      <span class="who"><?= Ui::avatar($u['name'], 's') ?><span class="t-label-m ellipsis"><?= e($u['name']) ?></span></span>
      <span class="t-body-m c-secondary ellipsis"><?= e($u['role_label']) ?></span>
      <span><?= $state($u) ?></span>
      <span class="t-body-m c-secondary ellipsis"><?= e(Staff::payModel($u)) ?></span>
      <span class="t-body-m c-secondary"><?= e($today($u)) ?></span>
      <span class="t-body-s c-muted"><?= e($u['remote_login'] && $u['email'] ? t('staff.login_mail') : t('staff.login_pin')) ?></span>
    </a>
  <?php endforeach ?>
</section>
<div class="list only-mobile">
  <?php foreach ($rows as $u): ?>
    <a class="lrow" href="/staff/<?= e($u['id']) ?>">
      <?= Ui::avatar($u['name']) ?>
      <span class="lrow__mid"><span class="lrow__title ellipsis"><?= e($u['name']) ?></span><span class="lrow__sub ellipsis"><?= e($u['role_label'] . ' · ' . Staff::payModel($u)) ?></span></span>
      <?= $state($u) ?>
    </a>
  <?php endforeach ?>
</div>
<?php endif ?>

<?= \Sofrexa\Core\View::partial('staff/_user_sheet', ['roles' => $roles]) ?>
