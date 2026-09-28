<?php
/**
 * Voided items (audit) — Figma R6 (54:536): KPIs of the week, filter chips (this week, today, a person, after the kitchen)
 * and every item removed after it was sent, with the stage it had reached, who did it, who approved and why.
 * @var array $rows @var array $p @var array $f @var array $week @var array $staff
 */
use Sofrexa\Modules\Reports\ReportsController;
use Sofrexa\View\Ui;

$sub = t('rep.voids_sub');
$q = static fn(array $over): string => '/reports/voids?' . http_build_query(array_filter($over + ['p' => $f['p'] === 'today' ? 'today' : null, 'who' => $f['who'] ?: null, 'after' => $f['after_kitchen'] ? 1 : null]));
$headActions = Ui::btn('Excel', ['style' => 'secondary', 'icon' => 'file-sheet', 'href' => $q(['x' => 'xlsx'])]);
$appActions = [Ui::ibtn('file-sheet', 'Excel', ['class' => 'appbar__act', 'href' => $q(['x' => 'xlsx'])])];
$bodyClass = 'page-voids';
$wTotal = array_sum(array_column($week, 'amount'));
$after = array_filter($week, static fn(array $r): bool => $r['stage'] !== 'sent');
$by = [];
$why = [];
foreach ($week as $r) {
    $by[(string) $r['by_name']] = ($by[(string) $r['by_name']] ?? 0) + 1;
    $k = trim((string) $r['void_reason']) ?: '—';
    $why[$k] = ($why[$k] ?? 0) + 1;
}
arsort($by);
arsort($why);
$tone = ['sent' => 'info', 'cooking' => 'warning', 'ready' => 'danger', 'served' => 'danger'];
?>
<div class="stats stats--4">
  <?= Ui::stat(t('rep.v_week'), money($wTotal), ['brand' => true, 'delta' => t('rep.items_n', ['n' => digits(count($week))])]) ?>
  <?= Ui::stat(t('rep.v_after'), money(array_sum(array_column($after, 'amount'))), ['delta' => $after ? t('rep.v_waste') : '']) ?>
  <?= Ui::stat(t('rep.v_most'), $by ? first_name((string) array_key_first($by)) : '—', ['delta' => $by ? t('rep.v_n', ['n' => digits(reset($by))]) : '']) ?>
  <?= Ui::stat(t('rep.v_reason'), $why ? (string) array_key_first($why) : '—', ['delta' => $why ? '%' . digits((string) (int) round(reset($why) * 100 / max(1, count($week)))) : '']) ?>
</div>
<div class="chips chips--scroll">
  <?= Ui::chip(t('rep.v_this_week'), $f['p'] !== 'today' && !$f['who'] && !$f['after_kitchen'], null, ['href' => '/reports/voids']) ?>
  <?= Ui::chip(t('rep.seg.today'), $f['p'] === 'today', null, ['href' => $q(['p' => $f['p'] === 'today' ? null : 'today'])]) ?>
  <?php foreach ($staff as $s): ?><?= Ui::chip(first_name($s['name']), $f['who'] === $s['id'], null, ['href' => $q(['who' => $f['who'] === $s['id'] ? null : $s['id']])]) ?><?php endforeach ?>
  <?= Ui::chip(t('rep.v_after'), (bool) $f['after_kitchen'], null, ['href' => $q(['after' => $f['after_kitchen'] ? null : 1])]) ?>
</div>
<section class="card card--pad0 grow only-desktop">
  <div class="vrow vrow--head"><span><?= e(t('rep.c_time')) ?></span><span><?= e(t('rep.c_bill')) ?></span><span><?= e(t('rep.c_item')) ?></span><span><?= e(t('rep.c_amount')) ?></span><span><?= e(t('rep.c_stage')) ?></span><span><?= e(t('rep.c_who')) ?></span><span><?= e(t('rep.c_ok')) ?></span><span><?= e(t('rep.c_reason')) ?></span></div>
  <?php foreach ($rows as $r): ?>
    <div class="vrow">
      <span class="t-body-m c-secondary num"><?= e(digits(date('d.m H:i', intdiv((int) $r['void_at'], 1000)))) ?></span>
      <span class="t-body-m c-secondary ellipsis"><?= e(ReportsController::where($r)) ?></span>
      <span class="t-label-m ellipsis"><?= e(digits(\Sofrexa\Modules\Orders\Orders::qtyText((float) $r['qty'])) . '× ' . $r['name']) ?></span>
      <span class="t-body-m c-danger num"><?= e(money($r['amount'])) ?></span>
      <span><?= Ui::badge(t('rep.stage.' . $r['stage']), $tone[$r['stage']], true) ?></span>
      <span class="t-body-m c-secondary ellipsis"><?= e(first_name((string) $r['by_name']) ?: '—') ?></span>
      <span class="t-body-m c-secondary ellipsis"><?= e($r['approver'] ? first_name((string) $r['approver']) : '—') ?></span>
      <span class="t-body-m c-secondary ellipsis"><?= e((string) $r['void_reason'] ?: '—') ?></span>
    </div>
  <?php endforeach ?>
  <?php if (!$rows): ?><div class="empty"><?= e(t('rep.v_none')) ?></div><?php endif ?>
</section>
<div class="list only-mobile">
  <?php foreach ($rows as $r): ?>
    <div class="lrow">
      <span class="lrow__mid"><span class="lrow__title ellipsis"><?= e(digits(\Sofrexa\Modules\Orders\Orders::qtyText((float) $r['qty'])) . '× ' . $r['name']) ?></span>
        <span class="lrow__sub"><?= e(digits(date('d.m H:i', intdiv((int) $r['void_at'], 1000))) . ' · ' . ReportsController::where($r) . ' · ' . (first_name((string) $r['by_name']) ?: '—') . ' · ' . ((string) $r['void_reason'] ?: '—')) ?></span></span>
      <span class="col" style="gap:4px;align-items:flex-end"><span class="t-label-m c-danger num"><?= e(money($r['amount'])) ?></span><?= Ui::badge(t('rep.stage.' . $r['stage']), $tone[$r['stage']], true) ?></span>
    </div>
  <?php endforeach ?>
  <?php if (!$rows): ?><div class="empty"><?= e(t('rep.v_none')) ?></div><?php endif ?>
</div>
