<?php
/** Reports list: every report and the finance pages (the phone "Raporlar" tab; the desktop landing of the section). */
use Sofrexa\View\Ui;

$items = [
    ['dashboard', 'rep.hub.dashboard', 'rep.hub.dashboard_s', '/'],
    ['file-text', 'rep.eod', 'rep.hub.eod_s', '/reports/z'],
    ['chart', 'rep.sales_t', 'rep.hub.sales_s', '/reports/sales'],
    ['users', 'rep.staff_t', 'rep.hub.staff_s', '/reports/staff'],
    ['x-circle', 'rep.voids_t', 'rep.hub.voids_s', '/reports/voids'],
    ['box', 'rep.stock_t', 'rep.hub.stock_s', '/reports/stock'],
    ['send', 'rep.exp_t', 'rep.hub.exp_s', '/reports/export'],
];
if (can('finance.manage')) {
    $items[] = ['wallet', 'fin.title', 'rep.hub.fin_s', '/finance'];
    $items[] = ['trend-up', 'fin.pl_t', 'rep.hub.pl_s', '/finance/pl'];
}
$bodyClass = 'page-rephub';
?>
<div class="rephub only-desktop">
  <?php foreach ($items as [$ic, $t, $s, $href]): ?>
    <a class="card rephub__item" href="<?= e($href) ?>"><span class="rephub__ic"><?= icon($ic, 22) ?></span><span class="col" style="gap:2px;min-width:0"><span class="t-heading-s"><?= e(t($t)) ?></span><span class="t-body-s c-muted"><?= e(t($s)) ?></span></span></a>
  <?php endforeach ?>
</div>
<div class="list only-mobile">
  <?php foreach ($items as [$ic, $t, $s, $href]): ?><?= Ui::lrow(t($t), ['icon' => $ic, 'sub' => t($s), 'href' => $href]) ?><?php endforeach ?>
</div>
