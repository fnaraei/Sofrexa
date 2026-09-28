<?php
/**
 * Sales by item, category, hour, channel and payment — no separate Figma frame: built from R2's KPI row,
 * hourly chart and metric lists and R6's table. @var array $p @var array $items @var array $k @var array $channels @var array $hours @var array $vat @var array $pay
 */
use Sofrexa\Core\I18n;
use Sofrexa\Modules\Reports\Reports;
use Sofrexa\View\Ui;

$n = static fn(float $v): string => digits(I18n::numAuto($v));
$sub = Reports::rangeLabel($p) . ' · ' . t('rep.vat_incl');
$appSub = Reports::rangeLabel($p);
$x = '/reports/sales?' . http_build_query(['p' => $p['key'], 'from' => $p['key'] === 'custom' ? $p['first'] : null, 'to' => $p['key'] === 'custom' ? $p['last'] : null, 'x' => 'xlsx']);
$headActions = \Sofrexa\Core\View::partial('reports/_period', ['p' => $p, 'keys' => ['today', 'yesterday', '7d', 'month', 'last_month', 'custom'], 'base' => '/reports/sales'])
    . Ui::btn('Excel', ['style' => 'secondary', 'icon' => 'file-sheet', 'href' => $x]);
$appActions = [Ui::ibtn('file-sheet', 'Excel', ['class' => 'appbar__act', 'href' => $x])];
$bodyClass = 'page-sales';
$cats = [];
foreach ($items as $r) {
    $c = tn(json_arr($r['cat'])) ?: '—';
    $cats[$c] = ($cats[$c] ?? 0) + (int) $r['amount'];
}
arsort($cats);
$total = max(1, $k['sales']);
$fx = array_sum(array_column($pay['fx'], 'try'));
?>
<div class="only-mobile"><?= \Sofrexa\Core\View::partial('reports/_period', ['p' => $p, 'keys' => ['today', '7d', 'month', 'last_month'], 'base' => '/reports/sales']) ?></div>
<div class="stats stats--4">
  <?= Ui::stat(t('rep.k_sales'), money($k['sales']), ['brand' => true, 'delta' => t('rep.bills_n', ['n' => digits(I18n::num($k['bills']))])]) ?>
  <?= Ui::stat(t('rep.k_net'), money($k['net']), ['delta' => t('rep.vat_amount', ['amount' => money($k['vat'])])]) ?>
  <?= Ui::stat(t('rep.k_avg'), money($k['avg']), ['delta' => t('rep.guests_n', ['n' => digits(I18n::num($k['guests']))])]) ?>
  <?= Ui::stat(t('rep.z_disc'), money($k['discount']), ['delta' => t('rep.voids_amount', ['amount' => money($k['voids'])])]) ?>
</div>
<div class="dash">
  <div class="dash__main">
    <section class="card chartcard">
      <div class="row gap-8"><h2 class="t-heading-s grow"><?= e(t('rep.hourly')) ?></h2><span class="t-body-s c-muted"><?= e(t('rep.hourly_unit')) ?></span></div>
      <?= \Sofrexa\Core\View::partial('reports/_hchart', ['hours' => $hours, 'size' => 'l']) ?>
    </section>
    <section class="card card--pad0">
      <div class="ledger__title"><h2 class="t-heading-s grow"><?= e(t('rep.by_item')) ?></h2></div>
      <div class="irow irow--head"><span><?= e(t('rep.c_item')) ?></span><span><?= e(t('rep.c_cat')) ?></span><span><?= e(t('rep.c_qty')) ?></span><span><?= e(t('rep.c_amount')) ?></span><span><?= e(t('rep.c_share')) ?></span></div>
      <?php foreach ($items as $r): ?>
        <div class="irow"><span class="t-label-m ellipsis"><?= e($r['name']) ?></span><span class="t-body-m c-secondary ellipsis"><?= e(tn(json_arr($r['cat'])) ?: '—') ?></span>
          <span class="t-body-m c-secondary num"><?= e($n((float) $r['qty'])) ?></span><span class="t-label-m num"><?= e(money((int) $r['amount'])) ?></span>
          <span class="t-body-s c-muted num"><?= e('%' . digits(I18n::numAuto((int) $r['amount'] * 100 / $total, 1))) ?></span></div>
      <?php endforeach ?>
      <?php if (!$items): ?><div class="empty"><?= e(t('rep.no_sales')) ?></div><?php endif ?>
    </section>
  </div>
  <div class="dash__side">
    <section class="card mcard">
      <h2 class="t-heading-s"><?= e(t('rep.by_cat')) ?></h2>
      <?php foreach (array_slice($cats, 0, 10, true) as $c => $v): ?>
        <div class="catbar"><div class="row gap-8"><span class="grow t-body-m c-secondary ellipsis"><?= e((string) $c) ?></span><span class="t-label-m num"><?= e(money($v)) ?></span></div>
          <span class="catbar__track"><span class="catbar__fill" style="width:<?= max(1.5, round($v * 100 / $total, 1)) ?>%"></span></span></div>
      <?php endforeach ?>
    </section>
    <section class="card mcard">
      <h2 class="t-heading-s"><?= e(t('rep.channels')) ?></h2>
      <?php foreach ([['grid', 'table'], ['qr', 'qr'], ['bag', 'takeaway'], ['bike', 'delivery'], ['globe', 'online']] as [$ic, $ch]): ?>
        <div class="mrow2"><?= icon($ic, 18) ?><span class="grow t-body-m c-secondary"><?= e(t('rep.ch.' . $ch)) ?></span><span class="t-label-m num"><?= e(money($channels[$ch])) ?></span></div>
      <?php endforeach ?>
    </section>
    <section class="card mcard">
      <h2 class="t-heading-s"><?= e(t('rep.z_payments')) ?></h2>
      <div class="mrow2"><span class="dot dot--brand"></span><span class="grow t-body-m c-secondary"><?= e(t('rep.pay_cash')) ?></span><span class="t-label-m num"><?= e(money($pay['cash_try'])) ?></span></div>
      <?php foreach ($pay['fx'] as $cur => $v): ?>
        <div class="mrow2"><span class="dot dot--attention"></span><span class="grow t-body-m c-secondary"><?= e(t('rep.pay_fx_one', ['sym' => \Sofrexa\Core\Money::symbol($cur), 'n' => digits(I18n::num($v['fx'], 2))])) ?></span><span class="t-label-m num"><?= e(money($v['try'])) ?></span></div>
      <?php endforeach ?>
      <div class="mrow2"><span class="dot dot--accent"></span><span class="grow t-body-m c-secondary"><?= e(t('rep.pay_card')) ?></span><span class="t-label-m num"><?= e(money($pay['card'])) ?></span></div>
      <div class="mrow2"><span class="dot dot--info"></span><span class="grow t-body-m c-secondary"><?= e(t('rep.pay_account')) ?></span><span class="t-label-m num"><?= e(money($pay['account'])) ?></span></div>
      <?php foreach ($vat as $rate => [$gross, $v]): if ((float) $rate <= 0) continue; ?>
        <div class="mrow2"><?= icon('percent', 18) ?><span class="grow t-body-m c-secondary"><?= e(t('rep.vat_line', ['r' => digits(I18n::numAuto((float) $rate))])) ?></span><span class="t-label-m num"><?= e(money($v)) ?></span></div>
      <?php endforeach ?>
    </section>
  </div>
</div>
