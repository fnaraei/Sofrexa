<?php
/**
 * Staff performance — Figma R7 (90:683 desktop: KPIs, "Salon ve kasa" table with share bars, kitchen card, attention callout)
 * and R8 (90:1177 phone: person cards). @var array $p @var array $rows @var array $k @var array $prev @var array $kitchen
 */
use Sofrexa\Core\I18n;
use Sofrexa\Modules\Reports\Reports;
use Sofrexa\View\Ui;

$n = static fn(int $v): string => digits(I18n::num($v));
$sub = t('rep.staff_sub', ['range' => Reports::rangeLabel($p)]);
$appSub = t('rep.p.' . $p['key']) . ' · ' . Reports::rangeLabel($p);
$x = '/reports/staff?' . http_build_query(['p' => $p['key'], 'from' => $p['key'] === 'custom' ? $p['first'] : null, 'to' => $p['key'] === 'custom' ? $p['last'] : null, 'x' => 'xlsx']);
ob_start();
echo \Sofrexa\Core\View::partial('reports/_period', ['p' => $p, 'keys' => ['today', '7d', 'month', 'custom'], 'base' => '/reports/staff', 'size' => '110']);
$headActions = ob_get_clean() . Ui::btn('Excel', ['style' => 'secondary', 'icon' => 'file-sheet', 'href' => $x]);
$appActions = [Ui::ibtn('file-sheet', 'Excel', ['class' => 'appbar__act', 'href' => $x])];
$bodyClass = 'page-perf';
$cS = Reports::change($k['sales'], $prev['sales']);
$cB = Reports::change($k['bills'], $prev['bills']);
$cA = Reports::change($k['avg'], $prev['avg']);
$avgVoids = $rows ? array_sum(array_map(static fn(array $r): int => $r['voids']['n'], $rows)) / count($rows) : 0;
$flag = null;
foreach ($rows as $r) {
    if ($r['voids']['n'] >= 3 && $avgVoids > 0 && $r['voids']['n'] >= 2 * $avgVoids) {
        $flag = $r;
        break;
    }
}
$vs = t('rep.vs.' . $p['key']);
$pct = static fn(?float $c, string $suffix = ''): string => Reports::pct($c) . ($c !== null && $suffix !== '' ? ' ' . $suffix : '');
?>
<div class="only-mobile"><?= \Sofrexa\Core\View::partial('reports/_period', ['p' => $p, 'keys' => ['today', '7d', 'month'], 'base' => '/reports/staff']) ?></div>
<div class="stats stats--4 only-desktop">
  <?= Ui::stat(t('rep.k_total_sales'), money($k['sales']), ['brand' => true, 'delta' => $pct($cS, $vs), 'down' => $cS !== null && $cS < 0]) ?>
  <?= Ui::stat(t('rep.k_bills'), $n($k['bills']), ['delta' => $pct($cB), 'down' => $cB !== null && $cB < 0]) ?>
  <?= Ui::stat(t('rep.k_avg'), money($k['avg']), ['delta' => $pct($cA), 'down' => $cA !== null && $cA < 0]) ?>
  <?= Ui::stat(t('rep.k_prep'), $kitchen['avg'] !== null ? t('rep.min', ['n' => $n($kitchen['avg'])]) : '—', ['delta' => t('rep.prep_target', ['n' => $n($kitchen['target'])]), 'down' => $kitchen['avg'] !== null && $kitchen['avg'] > $kitchen['target']]) ?>
</div>
<div class="perf only-desktop">
  <section class="card card--pad0 perf__table">
    <div class="ledger__title"><h2 class="t-heading-s grow"><?= e(t('rep.perf_floor')) ?></h2></div>
    <div class="perow perow--head"><span><?= e(t('staff.c_person')) ?></span><span><?= e(t('pay2.c_sales')) ?></span><span><?= e(t('rep.k_bills')) ?></span><span><?= e(t('rep.c_avg')) ?></span><span><?= e(t('rep.c_voids')) ?></span><span><?= e(t('rep.c_disc')) ?></span><span><?= e(t('rep.c_share')) ?></span></div>
    <?php foreach ($rows as $r): $warn = $flag && $flag['id'] === $r['id']; ?>
      <div class="perow">
        <?= Ui::who($r['name'], $r['role_label'] . ($r['hours'] ? ' · ' . t('rep.hours', ['n' => $n($r['hours'])]) : '')) ?>
        <span class="t-label-l num"><?= e(money($r['sales'])) ?></span>
        <span class="t-body-m c-secondary num"><?= e($n($r['bills'])) ?></span>
        <span class="t-body-m c-secondary num"><?= e(money($r['avg'])) ?></span>
        <span class="num"><?= $warn ? Ui::badge(digits($r['voids']['n']) . ' · ' . money($r['voids']['amount']), 'warning', true) : '<span class="t-body-m c-secondary">' . e($r['voids']['n'] ? digits($r['voids']['n']) . ' · ' . money($r['voids']['amount']) : '0') . '</span>' ?></span>
        <span class="t-body-m c-secondary num"><?= e(money($r['discount'])) ?></span>
        <span class="sharebar"><span class="sharebar__track"><span class="sharebar__fill" style="width:<?= round(min(100, $r['share']), 1) ?>%"></span></span><span class="t-label-m c-secondary"><?= e('%' . digits((string) (int) round($r['share']))) ?></span></span>
      </div>
    <?php endforeach ?>
    <?php if (!$rows): ?><div class="empty"><?= e(t('rep.no_sales')) ?></div><?php endif ?>
  </section>
  <div class="perf__side">
    <section class="card kvcard3">
      <h2 class="t-heading-s"><?= e(t('rep.kitchen')) ?></h2>
      <div class="kv"><span><?= e(t('rep.k_prep_avg')) ?></span><span class="t-label-l"><?= e($kitchen['avg'] !== null ? t('rep.min', ['n' => $n($kitchen['avg'])]) : '—') ?></span></div>
      <div class="kv"><span><?= e(t('rep.k_late', ['n' => $n($kitchen['late_min'])])) ?></span><span class="t-label-l"><?= e($kitchen['late_pct'] !== null ? '%' . digits((string) (int) round($kitchen['late_pct'])) . ' · ' . t('rep.orders_n', ['n' => $n($kitchen['late_orders'])]) : '—') ?></span></div>
      <div class="kv"><span><?= e(t('rep.k_slowest')) ?></span><span class="t-label-m ellipsis"><?= e($kitchen['slowest'] ? $kitchen['slowest']['name'] . ' · ' . t('rep.min', ['n' => $n($kitchen['slowest']['min'])]) : '—') ?></span></div>
    </section>
    <?php if ($flag): ?>
      <section class="callout">
        <div class="row gap-8"><?= icon('alert', 20) ?><span class="t-label-l"><?= e(t('rep.attention')) ?></span></div>
        <p class="t-body-s c-secondary"><?= e(t('rep.flag_voids', ['name' => first_name($flag['name']), 'x' => digits((string) round($flag['voids']['n'] / max(0.1, $avgVoids))), 'n' => $n($flag['voids']['n']), 'avg' => digits((string) round($avgVoids))])) ?></p>
        <?php if (can('audit.view')): ?><?= Ui::btn(t('rep.open_audit'), ['style' => 'secondary', 'size' => 's', 'icon' => 'history', 'href' => '/staff/audit?f=void']) ?><?php endif ?>
      </section>
    <?php endif ?>
  </div>
</div>
<div class="col gap-12 only-mobile">
  <?php foreach ($rows as $r): $warn = $flag && $flag['id'] === $r['id']; ?>
    <section class="card personcard">
      <div class="row gap-10"><span class="grow"><?= Ui::who($r['name'], $r['role_label'] . ($r['hours'] ? ' · ' . t('rep.hours', ['n' => $n($r['hours'])]) : '')) ?></span><?= Ui::badge(t('rep.share_badge', ['p' => digits((string) (int) round($r['share']))])) ?></div>
      <div class="row gap-8 end-a"><span class="t-number-m num grow"><?= e(money($r['sales'])) ?></span><?php if ($warn): ?><?= Ui::badge(t('rep.v_n', ['n' => digits($r['voids']['n'])]), 'warning', true) ?><?php endif ?></div>
      <span class="t-body-s c-muted ellipsis"><?= e(t('rep.person_sum', ['b' => $n($r['bills']), 'avg' => money($r['avg']), 'v' => $n($r['voids']['n']), 'd' => money($r['discount'])])) ?></span>
    </section>
  <?php endforeach ?>
  <?php if (!$rows): ?><div class="empty"><?= e(t('rep.no_sales')) ?></div><?php endif ?>
</div>
