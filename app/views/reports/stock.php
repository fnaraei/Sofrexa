<?php
/**
 * Stock movements of a period — no separate Figma frame (KPI row + R6 table style): per item what came in,
 * what sales and waste used, count differences, the quantity and value at the end. @var array $p @var array $rows @var array $tot
 */
use Sofrexa\Modules\Reports\Reports;
use Sofrexa\Modules\Stock\Stock;
use Sofrexa\View\Ui;

$sub = Reports::rangeLabel($p) . ' · ' . t('rep.stock_sub');
$appSub = Reports::rangeLabel($p);
$x = '/reports/stock?' . http_build_query(['p' => $p['key'], 'from' => $p['key'] === 'custom' ? $p['first'] : null, 'to' => $p['key'] === 'custom' ? $p['last'] : null, 'x' => 'xlsx']);
$headActions = \Sofrexa\Core\View::partial('reports/_period', ['p' => $p, 'keys' => ['7d', 'month', 'last_month', 'custom'], 'base' => '/reports/stock'])
    . Ui::btn('Excel', ['style' => 'secondary', 'icon' => 'file-sheet', 'href' => $x]);
$appActions = [Ui::ibtn('file-sheet', 'Excel', ['class' => 'appbar__act', 'href' => $x])];
$bodyClass = 'page-stockrep';
$q = static fn(float $v): string => $v == 0.0 ? '—' : Stock::qty($v);
?>
<div class="only-mobile"><?= \Sofrexa\Core\View::partial('reports/_period', ['p' => $p, 'keys' => ['7d', 'month', 'last_month'], 'base' => '/reports/stock']) ?></div>
<div class="stats stats--4">
  <?= Ui::stat(t('rep.s_value'), money($tot['value']), ['brand' => true, 'delta' => t('rep.s_value_d', ['date' => digits(date('d.m', (int) strtotime($p['last'])))])]) ?>
  <?= Ui::stat(t('rep.s_purchases'), money($tot['purchases']), ['delta' => t('rep.s_invoices', ['n' => digits($tot['purchases_n'])])]) ?>
  <?= Ui::stat(t('rep.s_cost'), money($tot['cost']), ['delta' => t('rep.s_cost_d')]) ?>
  <?= Ui::stat(t('rep.s_waste'), money($tot['waste']), ['delta' => $tot['count'] ? t('rep.s_count', ['amount' => money($tot['count'])]) : '', 'down' => $tot['count'] < 0]) ?>
</div>
<section class="card card--pad0 grow">
  <div class="srow2 srow2--head"><span><?= e(t('rep.c_item')) ?></span><span><?= e(t('rep.s_start')) ?></span><span><?= e(t('rep.s_in')) ?></span><span><?= e(t('rep.s_sale')) ?></span><span><?= e(t('rep.s_waste_c')) ?></span><span><?= e(t('rep.s_count_c')) ?></span><span><?= e(t('rep.s_end')) ?></span><span><?= e(t('rep.c_value')) ?></span></div>
  <?php foreach ($rows as $r): ?>
    <div class="srow2">
      <span class="t-label-m ellipsis"><?= e($r['name']) ?> <span class="t-body-s c-muted"><?= e($r['unit']) ?></span></span>
      <span class="t-body-m c-secondary num"><?= e($q($r['start'])) ?></span>
      <span class="t-body-m c-success num"><?= e($r['in'] ? '+' . Stock::qty($r['in']) : '—') ?></span>
      <span class="t-body-m c-secondary num"><?= e($r['sale'] ? '−' . Stock::qty($r['sale']) : '—') ?></span>
      <span class="t-body-m <?= $r['waste'] ? 'c-danger' : 'c-secondary' ?> num"><?= e($r['waste'] ? '−' . Stock::qty($r['waste']) : '—') ?></span>
      <span class="t-body-m <?= $r['count'] < 0 ? 'c-danger' : 'c-secondary' ?> num"><?= e($r['count'] ? ($r['count'] > 0 ? '+' : '−') . Stock::qty(abs($r['count'])) : '—') ?></span>
      <span class="t-label-m num"><?= e(Stock::qty($r['end'])) ?></span>
      <span class="t-body-m c-secondary num"><?= e(money($r['value'])) ?></span>
    </div>
  <?php endforeach ?>
  <?php if (!$rows): ?><div class="empty"><?= e(t('rep.s_none')) ?></div><?php endif ?>
</section>
