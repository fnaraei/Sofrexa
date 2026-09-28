<?php
/**
 * Profit and loss — Figma FI3 (99:287 desktop: KPIs, statement with the previous period and the change, where the income goes,
 * the note on purchases) and FI4 (99:714 phone: net profit card, short statement, the split bar).
 * @var array $p @var array $cur @var array $prev @var array $split
 */
use Sofrexa\Core\I18n;
use Sofrexa\Modules\Reports\Reports;
use Sofrexa\View\Ui;

$label = in_array($p['key'], ['month', 'last_month'], true) ? \Sofrexa\Modules\Customers\Customers::monthLabel(substr($p['first'], 0, 7)) : Reports::rangeLabel($p);
$prevLabel = in_array($p['key'], ['month', 'last_month'], true) ? t('date.m' . (int) date('n', intdiv($p['prev_from'], 1000))) : t('fin.prev');
$sub = t('fin.pl_sub', ['period' => $label, 'prev' => $prevLabel]);
$appSub = $label . ' · ' . t('fin.ex_vat');
$qs = http_build_query(array_filter(['p' => $p['key'], 'from' => $p['key'] === 'custom' ? $p['first'] : null, 'to' => $p['key'] === 'custom' ? $p['last'] : null]));
$headActions = \Sofrexa\Core\View::partial('reports/_period', ['p' => $p, 'keys' => ['month', 'last_month', 'year', 'custom'], 'base' => '/finance/pl', 'size' => '110'])
    . Ui::btn('PDF', ['style' => 'secondary', 'icon' => 'file-text', 'href' => '/finance/pl/pdf?' . $qs, 'attrs' => ['target' => '_blank']])
    . Ui::btn('Excel', ['style' => 'secondary', 'icon' => 'file-sheet', 'href' => '/finance/pl?' . $qs . '&x=xlsx']);
$appActions = [Ui::ibtn('file-text', 'PDF', ['class' => 'appbar__act', 'href' => '/finance/pl/pdf?' . $qs, 'attrs' => ['target' => '_blank']])];
$bodyClass = 'page-pl';
$m = static fn(int $v): string => ltrim(money($v), '₺');
$chg = static function (int $a, int $b, bool $cost) {
    $c = Reports::change($a, $b);
    if ($c === null) {
        return ['', ''];
    }
    if (abs($c) < 0.05) {
        return ['—', ''];
    }
    $up = $c > 0;
    return [($up ? '+' : '−') . '%' . digits(I18n::numAuto(abs($c), 1)), ($up xor $cost) ? 'c-success' : 'c-danger'];
};
$lines = [
    ['line', t('fin.l_gross'), $cur['gross_sales'], $prev['gross_sales'], false, true],
    ['line', t('fin.l_vat'), $cur['vat'], $prev['vat'], true, false],
    ['sub', t('fin.l_net'), $cur['net_sales'], $prev['net_sales'], false, true],
    ['line', t('fin.l_other'), $cur['other'], $prev['other'], false, false],
    ['line', t('fin.l_cogs'), $cur['cogs'], $prev['cogs'], true, true],
    ['line', t('fin.l_waste'), $cur['waste'], $prev['waste'], true, true],
    ['sub', t('fin.l_grossp'), $cur['gross'], $prev['gross'], false, true],
    ['line', t('fin.l_staff'), $cur['opex']['staff'], $prev['opex']['staff'], true, true],
    ['line', t('fin.l_rent'), $cur['opex']['rent'], $prev['opex']['rent'], true, true],
    ['line', t('fin.l_energy'), $cur['opex']['energy'], $prev['opex']['energy'], true, true],
    ['line', t('fin.l_marketing'), $cur['opex']['marketing'], $prev['opex']['marketing'], true, true],
    ['line', t('fin.l_maint'), $cur['opex']['maintenance'], $prev['opex']['maintenance'], true, true],
    ['line', t('fin.l_otherx'), $cur['opex']['other'], $prev['opex']['other'], true, true],
    ['sub', t('fin.l_opex'), $cur['opex_total'], $prev['opex_total'], true, true],
    ['total', t('fin.l_netp'), $cur['net'], $prev['net'], false, true],
];
$cNet = Reports::change($cur['net'], $prev['net']);
$cSales = Reports::change($cur['net_sales'], $prev['net_sales']);
$cOpex = Reports::change($cur['opex_total'], $prev['opex_total']);
$pctTxt = static fn(?float $v): string => $v === null ? '—' : ($v < 0 ? '−' : '') . '%' . digits(I18n::numAuto(abs($v), 1));
$segs = [['accent', 'fin.s_cogs', $split['cogs']], ['danger', 'fin.s_waste', $split['waste']], ['info', 'fin.s_staff', $split['staff']],
    ['attention', 'fin.s_rent', $split['rent']], ['warning', 'fin.s_other', $split['other']], ['success', 'fin.s_profit', $split['profit']]];
$bar = '<div class="stackbar stackbar--gap">';
foreach ($segs as [$tone, , $v]) {
    if ($v > 0) {
        $bar .= '<span class="stackbar__seg stackbar__seg--' . $tone . '" style="flex:' . round($v, 3) . '"></span>';
    }
}
$bar .= '</div>';
?>
<div class="only-mobile"><?= \Sofrexa\Core\View::partial('reports/_period', ['p' => $p, 'keys' => ['month', 'last_month', 'year'], 'base' => '/finance/pl']) ?></div>
<div class="stats stats--4 only-desktop">
  <?= Ui::stat(t('fin.k_net_sales'), money($cur['net_sales']), ['delta' => $cSales !== null ? Reports::pct($cSales) . ' ' . t('fin.vs_prev', ['prev' => $prevLabel]) : '', 'down' => $cSales !== null && $cSales < 0]) ?>
  <?= Ui::stat(t('fin.k_gross'), money($cur['gross']), ['delta' => t('fin.margin', ['p' => $pctTxt($cur['gross_margin'])])]) ?>
  <?= Ui::stat(t('fin.k_opex'), money($cur['opex_total']), ['delta' => Reports::pct($cOpex), 'down' => $cOpex !== null && $cOpex > 0]) ?>
  <?= Ui::stat(t('fin.k_net'), money($cur['net']), ['brand' => true, 'delta' => trim(Reports::pct($cNet) . ' · ' . t('fin.margin', ['p' => $pctTxt($cur['margin'])]), ' ·'), 'down' => $cur['net'] < 0 || ($cNet !== null && $cNet < 0)]) ?>
</div>
<div class="pl only-desktop">
  <section class="card card--pad0 pl__table">
    <div class="plrow plrow--head"><span><?= e(t('fin.c_item')) ?></span><span><?= e(mb_strtoupper($label, 'UTF-8')) ?></span><span><?= e(mb_strtoupper($prevLabel, 'UTF-8')) ?></span><span><?= e(t('fin.c_change')) ?></span></div>
    <?php foreach ($lines as [$type, $text, $a, $b, $cost, $show]):
        [$c, $cls] = $show ? $chg($a, $b, $cost) : ['', '']; ?>
      <div class="plrow plrow--<?= $type ?>"><span><?= e($text) ?></span><span class="num"><?= e($type === 'total' ? money($a) : $m($a)) ?></span><span class="num"><?= e($type === 'total' ? money($b) : $m($b)) ?></span><span class="num <?= $type === 'total' ? 'c-accent' : $cls ?>"><?= e($c) ?></span></div>
    <?php endforeach ?>
    <?php $mc = $cur['margin'] !== null && $prev['margin'] !== null ? $cur['margin'] - $prev['margin'] : null; ?>
    <div class="plrow plrow--line"><span><?= e(t('fin.l_margin')) ?></span><span class="num"><?= e($pctTxt($cur['margin'])) ?></span><span class="num"><?= e($pctTxt($prev['margin'])) ?></span>
      <span class="num <?= $mc !== null ? ($mc >= 0 ? 'c-success' : 'c-danger') : '' ?>"><?= e($mc !== null ? ($mc >= 0 ? '+' : '−') . t('fin.points', ['n' => digits(I18n::numAuto(abs($mc), 1))]) : '') ?></span></div>
  </section>
  <div class="pl__side">
    <section class="card splitcard">
      <h2 class="t-heading-s"><?= e(t('fin.where')) ?></h2>
      <p class="t-body-s c-muted"><?= e(t('fin.where_s', ['amount' => money($cur['income'])])) ?></p>
      <?= $bar ?>
      <?php foreach ($segs as [$tone, $key, $v]): ?>
        <div class="mrow2"><span class="dot dot--<?= $tone ?> dot--10"></span><span class="grow t-body-m c-secondary"><?= e(t($key)) ?></span><span class="t-label-m num"><?= e($pctTxt($v)) ?></span></div>
      <?php endforeach ?>
    </section>
    <div class="note"><?= icon('info', 20) ?><span class="grow"><?= e(t('fin.purchases_note', ['p' => money($cur['purchases']), 'c' => money($cur['cogs']), 'w' => money($cur['waste'])])) ?></span></div>
  </div>
</div>
<div class="col gap-10 only-mobile">
  <div class="brandcard">
    <span class="overline"><?= e(t('fin.k_net')) ?></span>
    <span class="t-number-xl num"><?= e(money($cur['net'])) ?></span>
    <span class="t-body-s"><?= e(t('fin.margin', ['p' => $pctTxt($cur['margin'])]) . ($cNet !== null ? ' · ' . t('fin.vs_prev', ['prev' => $prevLabel]) . ' ' . Reports::pct($cNet) : '')) ?></span>
  </div>
  <section class="card plm">
    <div class="kv"><span><?= e(t('fin.l_net')) ?></span><span class="t-label-m num"><?= e(money($cur['net_sales'])) ?></span></div>
    <div class="kv"><span><?= e(t('fin.m_other')) ?></span><span class="t-label-m num c-success"><?= e('+' . money($cur['other'])) ?></span></div>
    <div class="kv"><span><?= e(t('fin.m_cogs')) ?></span><span class="t-label-m num"><?= e('−' . money($cur['cogs'])) ?></span></div>
    <div class="kv"><span><?= e(t('fin.s_waste')) ?></span><span class="t-label-m num"><?= e('−' . money($cur['waste'])) ?></span></div>
    <div class="kv kv--sum"><span><?= e(t('fin.l_grossp')) ?></span><span class="t-heading-s num"><?= e(money($cur['gross'])) ?></span></div>
    <div class="kv"><span><?= e(t('fin.s_staff')) ?></span><span class="t-label-m num"><?= e('−' . money($cur['opex']['staff'])) ?></span></div>
    <div class="kv"><span><?= e(t('fin.s_rent')) ?></span><span class="t-label-m num"><?= e('−' . money($cur['opex']['rent'])) ?></span></div>
    <div class="kv"><span><?= e(t('fin.s_other')) ?></span><span class="t-label-m num"><?= e('−' . money($cur['opex_total'] - $cur['opex']['staff'] - $cur['opex']['rent'])) ?></span></div>
    <div class="kv kv--sum"><span><?= e(t('fin.l_netp_m')) ?></span><span class="t-heading-s num <?= $cur['net'] >= 0 ? 'c-success' : 'c-danger' ?>"><?= e(money($cur['net'])) ?></span></div>
  </section>
  <section class="card splitcard">
    <h2 class="t-label-l"><?= e(t('fin.split_m')) ?></h2>
    <?= $bar ?>
    <div class="legend"><?php foreach ($segs as [$tone, $key, $v]): ?><span><span class="dot dot--<?= $tone ?>"></span><?= e(t($key) . ' ' . $pctTxt($v)) ?></span><?php endforeach ?></div>
  </section>
</div>
