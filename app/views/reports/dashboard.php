<?php
/**
 * Manager overview — Figma R2 (43:166 desktop: period, five KPIs, hourly chart, channels, attention, best sellers,
 * payment split) and R1 (43:2 phone: four KPIs, hourly chart, alert banner, best sellers).
 * @var array $p @var array $k @var array $prev @var array $hours @var array $channels @var array $pay @var array $top
 * @var array $attention @var array $open @var array $critical @var array $voids @var array $sync
 */
use Sofrexa\Core\{I18n, Settings};
use Sofrexa\Modules\Reports\Reports;
use Sofrexa\View\Ui;

$n = static fn(int $v): string => digits(I18n::num($v));
$vs = t('rep.vs.' . $p['key']);
$delta = static function (?float $c, string $suffix = ''): string {
    if ($c === null) {
        return '';
    }
    return ($c < 0 ? '−' : '') . '%' . digits(I18n::numAuto(abs($c), abs($c) < 10 ? 1 : 0)) . ($suffix !== '' ? ' ' . $suffix : '');
};
$cSales = Reports::change($k['sales'], $prev['sales']);
$cBills = Reports::change($k['bills'], $prev['bills']);
$cAvg = Reports::change($k['avg'], $prev['avg']);
$cGuests = Reports::change($k['guests'], $prev['guests']);
$target = (int) Settings::get('report.food_cost_target', 30);
$isToday = $p['key'] === 'today';
$dateLong = I18n::date($p['from'], 'long');
$sub = ($isToday || $p['key'] === 'yesterday' ? $dateLong : Reports::rangeLabel($p)) . ($isToday ? ' · ' . t('rep.live') : '') . ($sync['configured'] ? ' · ' . ($sync['state'] === 'offline' ? t('rep.sync_off') : t('rep.sync_ok')) : '');
$appTitle = t('rep.p.' . $p['key']);
$appSub = $isToday ? $dateLong . ' · ' . digits(date('H:i')) : Reports::rangeLabel($p);
$appActions = [
    Ui::ibtn('calendar', t('rep.period'), ['class' => 'appbar__act', 'attrs' => ['data-sheet' => 'period-sheet']]),
    Ui::ibtn('refresh', t('rep.refresh'), ['class' => 'appbar__act', 'href' => '/' . ($p['key'] !== 'today' ? '?p=' . $p['key'] : '')]),
];
$segs = [];
foreach (['today', 'yesterday', '7d', 'month'] as $key) {
    $segs['/?p=' . $key] = t('rep.seg.' . $key);
}
$headActions = '<div class="segs segs--88">' . implode('', array_map(static fn(string $u, string $l): string => '<a class="seg' . ($u === '/?p=' . $p['key'] ? ' is-active' : '') . '" href="' . e($u) . '">' . e($l) . '</a>', array_keys($segs), $segs)) . '</div>'
    . Ui::btn(t('rep.eod'), ['style' => 'secondary', 'icon' => 'file-text', 'href' => '/reports/z']);
$bodyClass = 'page-dash';
$total = max(1, $k['sales']);
$share = static fn(int $v): string => '%' . digits((string) (int) round($v * 100 / $total));
$fxTotal = array_sum(array_column($pay['fx'], 'try'));
$paySum = max(1, $pay['cash_try'] + $fxTotal + $pay['card'] + $pay['account'] + $k['discount']);
$payRows = [
    ['brand', t('rep.pay_cash'), $pay['cash_try']],
    ['attention', t('rep.pay_fx'), $fxTotal],
    ['accent', t('rep.pay_card'), $pay['card']],
    ['info', t('rep.pay_account'), $pay['account']],
    ['warning', t('rep.pay_discount'), $k['discount']],
];
$topRow = static fn(array $r): string => '<div class="toprow2">' . ($r['photo'] ? '<img class="thumb36" src="' . e($r['photo']) . '" alt="" loading="lazy">' : '<span class="thumb36 thumb36--ph">' . icon('utensils', 18) . '</span>')
    . '<span class="grow col" style="gap:0;min-width:0"><span class="t-label-m ellipsis">' . e($r['name']) . '</span><span class="t-body-s c-muted">' . e(t('rep.qty', ['n' => digits(\Sofrexa\Modules\Orders\Orders::qtyText((float) $r['qty']))])) . '</span></span>'
    . '<span class="t-label-m num">' . e(money((int) $r['amount'])) . '</span></div>';
// phone banner: critical stock and voids
$critNames = implode(', ', array_map(static fn(array $c): string => $c['name'], array_slice($critical, 0, 2)));
$voidTables = [];
foreach ($voids as $v) {
    if ($v['table_no'] !== null) {
        $voidTables[$v['table_no']] = ($voidTables[$v['table_no']] ?? 0) + 1;
    }
}
arsort($voidTables);
$bannerTitle = implode(' · ', array_filter([$critical ? t('rep.b_stock', ['n' => $n(count($critical))]) : '', $voids ? t('rep.b_voids', ['n' => $n(count($voids)), 'amount' => money($k['voids'])]) : '']));
$bannerText = trim(($critNames !== '' ? t('rep.b_stock_t', ['names' => $critNames]) : '') . ' ' . ($voidTables && reset($voidTables) > 1 ? t('rep.b_voids_t', ['n' => $n((int) reset($voidTables)), 't' => digits((string) array_key_first($voidTables))]) : ''));
?>
<div class="stats stats--5 only-desktop">
  <?= Ui::stat(t('rep.k_sales'), money($k['sales']), ['brand' => true, 'delta' => $delta($cSales, $vs), 'down' => $cSales !== null && $cSales < 0]) ?>
  <?= Ui::stat(t('rep.k_bills'), $n($k['bills']), ['delta' => $delta($cBills), 'down' => $cBills !== null && $cBills < 0]) ?>
  <?= Ui::stat(t('rep.k_avg'), money($k['avg']), ['delta' => $delta($cAvg), 'down' => $cAvg !== null && $cAvg < 0]) ?>
  <?= Ui::stat(t('rep.k_guests'), $n($k['guests']), ['delta' => $delta($cGuests), 'down' => $cGuests !== null && $cGuests < 0]) ?>
  <?= Ui::stat(t('rep.k_food'), $k['food_cost'] !== null ? '%' . digits((string) (int) round($k['food_cost'])) : '—', ['delta' => t('rep.target', ['p' => $n($target)]), 'down' => $k['food_cost'] !== null && $k['food_cost'] > $target]) ?>
</div>
<div class="stats only-mobile">
  <?= Ui::stat(t('rep.k_sales'), money($k['sales']), ['brand' => true, 'delta' => $delta($cSales, $vs), 'down' => $cSales !== null && $cSales < 0]) ?>
  <?= Ui::stat(t('rep.k_open'), $n($open['n']), ['delta' => $open['n'] ? money($open['amount']) : '']) ?>
  <?= Ui::stat(t('rep.k_avg'), money($k['avg']), ['delta' => $delta($cAvg), 'down' => $cAvg !== null && $cAvg < 0]) ?>
  <?= Ui::stat(t('rep.k_guests'), $n($k['guests']), ['delta' => $delta($cGuests), 'down' => $cGuests !== null && $cGuests < 0]) ?>
</div>

<div class="dash">
  <div class="dash__main">
    <section class="card chartcard">
      <div class="row gap-8"><h2 class="t-heading-s grow"><?= e(t('rep.hourly')) ?></h2><span class="t-body-s c-muted"><?= e(t('rep.hourly_unit')) ?></span></div>
      <div class="only-desktop"><?= \Sofrexa\Core\View::partial('reports/_hchart', ['hours' => $hours, 'size' => 'l']) ?></div>
      <div class="only-mobile"><?= \Sofrexa\Core\View::partial('reports/_hchart', ['hours' => $hours, 'size' => 'm']) ?></div>
    </section>
    <?php if ($bannerTitle !== ''): ?>
      <div class="only-mobile"><?= Ui::banner($bannerTitle, $bannerText, 'danger', 'alert') ?></div>
    <?php endif ?>
    <div class="dash__pair only-desktop">
      <section class="card mcard">
        <h2 class="t-heading-s"><?= e(t('rep.channels')) ?></h2>
        <?php foreach ([['grid', 'table'], ['qr', 'qr'], ['bag', 'takeaway'], ['bike', 'delivery'], ['globe', 'online']] as [$ic, $ch]): ?>
          <div class="mrow2"><?= icon($ic, 18) ?><span class="grow t-body-m c-secondary"><?= e(t('rep.ch.' . $ch)) ?></span>
            <span class="t-body-s c-muted"><?= e($ch === 'qr' ? t('rep.inside') : $share($channels[$ch])) ?></span><span class="t-label-m num"><?= e(money($channels[$ch])) ?></span></div>
        <?php endforeach ?>
      </section>
      <section class="card mcard">
        <h2 class="t-heading-s"><?= e(t('rep.attention')) ?></h2>
        <?php foreach ($attention as [$ic, $tone, $text]): ?>
          <div class="mrow2 mrow2--<?= e($tone) ?>"><?= icon($ic, 18) ?><span class="grow t-body-m c-secondary"><?= e($text) ?></span></div>
        <?php endforeach ?>
        <?php if (!$attention): ?><div class="mrow2 mrow2--success"><?= icon('check-circle', 18) ?><span class="grow t-body-m c-secondary"><?= e(t('rep.all_good')) ?></span></div><?php endif ?>
      </section>
    </div>
  </div>
  <div class="dash__side">
    <section class="card mcard">
      <h2 class="t-heading-s"><?= e(t('rep.top_' . $p['key'])) ?></h2>
      <?php foreach ($top as $i => $r): ?><div class="<?= $i >= 3 ? 'only-desktop' : '' ?>"><?= $topRow($r) ?></div><?php endforeach ?>
      <?php if (!$top): ?><div class="empty"><?= e(t('rep.no_sales')) ?></div><?php endif ?>
    </section>
    <section class="card mcard only-desktop">
      <h2 class="t-heading-s"><?= e(t('rep.pay_split')) ?></h2>
      <div class="stackbar" aria-hidden="true"><?php foreach ($payRows as [$tone, , $v]): if ($v <= 0) continue; ?><span class="stackbar__seg stackbar__seg--<?= $tone ?>" style="flex:<?= round($v * 100 / $paySum, 3) ?>"></span><?php endforeach ?></div>
      <?php foreach ($payRows as [$tone, $label, $v]): ?>
        <div class="mrow2"><span class="dot dot--<?= $tone ?>"></span><span class="grow t-body-m c-secondary"><?= e($label) ?></span>
          <span class="t-body-s c-muted"><?= e('%' . digits((string) (int) round($v * 100 / $paySum))) ?></span><span class="t-label-m num"><?= e(money($v)) ?></span></div>
      <?php endforeach ?>
    </section>
  </div>
</div>

<div class="scrim" id="period-sheet" hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead(t('rep.period')) ?>
    <div class="sheet__body">
      <div class="list list--flush">
        <?php foreach (['today', 'yesterday', '7d', 'month'] as $key): ?>
          <?= Ui::lrow(t('rep.p.' . $key), ['icon' => $key === $p['key'] ? 'check' : 'calendar', 'href' => '/' . ($key !== 'today' ? '?p=' . $key : '')]) ?>
        <?php endforeach ?>
        <?= Ui::lrow(t('rep.eod'), ['icon' => 'file-text', 'href' => '/reports/z']) ?>
      </div>
    </div>
  </div>
</div>
