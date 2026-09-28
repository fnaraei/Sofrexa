<?php
/**
 * Pay and bonus — Figma ST2 (42:312 desktop: KPIs, table, footnote). Rows can be selected for "Seçilenlere ödeme"
 * (a Checkbox column, as the design note suggests); a month picker chooses the period.
 * @var string $month @var array $rows @var array $tot
 */
use Sofrexa\Core\I18n;
use Sofrexa\Modules\Customers\Customers;
use Sofrexa\View\Ui;

[$y, $m] = array_map('intval', explode('-', $month));
$last = $month === date('Y-m') ? (int) date('j') : (int) date('t', (int) strtotime($month . '-01'));
$mName = t('date.m' . $m);
$sub = t('pay2.sub', ['month' => Customers::monthLabel($month), 'range' => digits('1–' . $last) . ' ' . $mName]);
$appSub = Customers::monthLabel($month);
$months = [];
for ($i = 0; $i < 12; $i++) {
    $k = date('Y-m', (int) strtotime(date('Y-m-01') . " -$i months"));
    $months[$k] = Customers::monthLabel($k);
}
$monthPick = '<label class="chip chip--select"><span>' . e(Customers::monthLabel($month)) . '</span>' . icon('chevron-down', 16)
    . '<select aria-label="' . e(t('cust.month_pick')) . '" data-go="/staff/pay?m=">';
foreach ($months as $k => $label) {
    $monthPick .= '<option value="' . e($k) . '"' . ($k === $month ? ' selected' : '') . '>' . e($label) . '</option>';
}
$monthPick .= '</select></label>';
$headActions = $monthPick
    . Ui::btn(t('pay2.excel'), ['style' => 'secondary', 'icon' => 'file-sheet', 'href' => '/staff/pay/export?m=' . $month])
    . Ui::btn(t('pay2.pay_selected'), ['icon' => 'wallet', 'attrs' => ['data-pay-selected' => '/staff/pay/sheet?m=' . $month, 'disabled' => true]]);
$appActions = [Ui::ibtn('file-sheet', t('pay2.excel'), ['class' => 'appbar__act', 'href' => '/staff/pay/export?m=' . $month])];
$bottom = Ui::btn(t('pay2.pay_selected'), ['size' => 'l', 'icon' => 'wallet', 'block' => true, 'attrs' => ['data-pay-selected' => '/staff/pay/sheet?m=' . $month, 'disabled' => true]]);
$bodyClass = 'page-payroll';
$pct = static fn(float $p): string => $p > 0 ? '%' . digits(I18n::numAuto($p)) : '—';
$salesText = static function (array $r): string {
    if ((int) $r['per_delivery'] > 0 && (float) $r['commission_pct'] <= 0) {
        return t('staff.today_del', ['n' => digits(I18n::num($r['deliveries']))]);
    }
    if ((float) $r['commission_pct'] <= 0) {
        return '—';
    }
    return money($r['sales']) . (in_array($r['pay_basis'], ['kitchen', 'bar', 'till', 'all'], true) ? ' (' . t('staff.basis_s.' . $r['pay_basis']) . ')' : '');
};
$leftClass = static fn(int $v): string => $v < 0 ? 'c-danger' : ($v > 0 ? 'c-success' : 'c-muted');
?>
<div class="stats stats--4">
  <?= Ui::stat(t('pay2.k_earned'), money($tot['earned']), ['brand' => true]) ?>
  <?= Ui::stat(t('pay2.k_paid'), money($tot['paid'])) ?>
  <?= Ui::stat(t('pay2.k_left'), money($tot['left'])) ?>
  <?= Ui::stat(t('pay2.k_rate'), $tot['waiter_rate'] !== null ? t('pay2.k_rate_v', ['p' => digits(I18n::numAuto($tot['waiter_rate']))]) : '—') ?>
</div>

<?php if (!$rows): ?>
  <div class="empty"><?= icon('wallet', 24) ?><div><?= e(t('pay2.none')) ?></div></div>
<?php else: ?>
<section class="card card--pad0 only-desktop" data-payroll>
  <div class="prow prow--head">
    <span><?= Ui::checkbox('all', false, ['data-pick-all' => true, 'aria-label' => t('ui.all')]) ?></span>
    <span><?= e(t('staff.c_person')) ?></span><span><?= e(t('staff.c_role')) ?></span><span><?= e(t('pay2.c_sales')) ?></span><span><?= e(t('pay2.c_rate')) ?></span>
    <span><?= e(t('pay2.c_fixed')) ?></span><span><?= e(t('pay2.c_earned')) ?></span><span><?= e(t('pay2.c_paid')) ?></span><span><?= e(t('pay2.c_left')) ?></span>
  </div>
  <?php foreach ($rows as $r): ?>
    <label class="prow">
      <span><?= Ui::checkbox('pick', false, ['value' => $r['id'], 'data-pick' => true]) ?></span>
      <span class="t-label-m ellipsis"><?= e($r['name']) ?></span>
      <span class="t-body-m c-secondary ellipsis"><?= e($r['role_label']) ?></span>
      <span class="t-body-m c-secondary ellipsis num"><?= e($salesText($r)) ?></span>
      <span class="t-body-m c-secondary num"><?= e($pct((float) $r['commission_pct'])) ?></span>
      <span class="t-body-m c-secondary num"><?= e((int) $r['base_salary'] > 0 ? money((int) $r['base_salary']) : '—') ?></span>
      <span class="t-label-m num"><?= e(money($r['earned'])) ?></span>
      <span class="t-body-m c-secondary num"><?= e($r['paid'] ? money($r['paid']) : '—') ?></span>
      <span class="t-label-m num <?= $leftClass($r['left']) ?>"><?= e(money($r['left'])) ?></span>
    </label>
  <?php endforeach ?>
</section>
<div class="list only-mobile" data-payroll>
  <?php foreach ($rows as $r): ?>
    <label class="lrow payrow">
      <?= Ui::checkbox('pick', false, ['value' => $r['id'], 'data-pick' => true]) ?>
      <span class="lrow__mid"><span class="lrow__title ellipsis"><?= e($r['name']) ?></span>
        <span class="lrow__sub ellipsis"><?= e($r['role_label'] . ' · ' . t('pay2.c_earned') . ' ' . money($r['earned']) . ($r['paid'] ? ' · ' . t('pay2.c_paid') . ' ' . money($r['paid']) : '')) ?></span></span>
      <span class="t-label-m num <?= $leftClass($r['left']) ?>"><?= e(money($r['left'])) ?></span>
    </label>
  <?php endforeach ?>
</div>
<?php endif ?>
<p class="t-body-s c-muted"><?= e(t('pay2.note')) ?></p>
