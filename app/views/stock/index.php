<?php
/**
 * Stock — Figma S1 (37:2 desktop: KPIs, filters, table) and S6 (37:386 phone: critical banner, quick actions, list).
 * @var array $all @var array $items @var array $kpi @var string $filter @var string $q
 */
use Sofrexa\Core\I18n;
use Sofrexa\Modules\Stock\Stock;
use Sofrexa\View\Ui;

$sub = t('stock.sub', ['n' => digits(I18n::num(count($all)))]);
$appSub = t('stock.sub_m', ['n' => digits(I18n::num(count($all))), 'value' => money($kpi['value'])]);
$headActions = Ui::ibtn('plus', t('stock.new_item'), ['style' => 'secondary', 'attrs' => ['data-load-sheet' => '/stock/items/new/sheet']])
    . Ui::btn(t('stock.waste_btn'), ['style' => 'secondary', 'icon' => 'trash', 'href' => '/stock/waste'])
    . Ui::btn(t('stock.count_btn'), ['style' => 'secondary', 'icon' => 'clipboard', 'href' => '/stock/count'])
    . Ui::btn(t('stock.shop_btn'), ['style' => 'secondary', 'icon' => 'list', 'href' => '/stock/shopping'])
    . Ui::btn(t('stock.purchase_btn'), ['icon' => 'box-in', 'href' => '/stock/purchase']);
$appActions = [
    Ui::ibtn('search', t('ui.search'), ['class' => 'appbar__act', 'attrs' => ['data-search-toggle' => true]]),
    Ui::ibtn('plus', t('stock.new_item'), ['class' => 'appbar__act', 'attrs' => ['data-load-sheet' => '/stock/items/new/sheet']]),
];
$bodyClass = 'page-stock';
$counts = ['critical' => 0, 'low' => 0];
$groups = [];
foreach ($all as $r) {
    if (isset($counts[$r['state']])) {
        $counts[$r['state']]++;
    }
    if ($r['category']) {
        $groups[$r['category']] = ($groups[$r['category']] ?? 0) + 1;
    }
}
arsort($groups);
$url = static fn(string $f): string => '/stock' . ($f !== '' ? '?f=' . rawurlencode($f) : '');
$tone = ['critical' => 'danger', 'low' => 'warning', 'ok' => 'success'];
$numClass = ['critical' => 'c-danger', 'low' => 'c-warning', 'ok' => 'c-primary'];
$delta = $kpi['yesterday'] > 0 ? (int) round(($kpi['today'] - $kpi['yesterday']) * 100 / $kpi['yesterday']) : null;
$critNames = implode(', ', array_map(static fn(array $r): string => $r['name'], array_slice($kpi['critical'], 0, 2)));
$crit = array_values(array_filter($all, static fn(array $r): bool => $r['state'] === 'critical' && $r['active']));
?>
<div class="stats stats--4 only-desktop">
  <?= Ui::stat(t('stock.k_value'), money($kpi['value']), ['brand' => true]) ?>
  <?= Ui::stat(t('stock.k_critical'), digits(count($kpi['critical'])), ['delta' => $critNames]) ?>
  <?= Ui::stat(t('stock.k_use'), money($kpi['today']), ['delta' => $delta !== null ? t('stock.k_use_d', ['p' => digits(abs($delta))]) : '', 'down' => $delta !== null && $delta < 0]) ?>
  <?= Ui::stat(t('stock.k_count'), $kpi['last_count'] ? when_label($kpi['last_count']) : t('stock.k_never'), ['delta' => $kpi['last_count'] ? t('stock.k_count_d', ['amount' => money($kpi['last_diff'])]) : '', 'down' => $kpi['last_diff'] < 0]) ?>
</div>

<?php if ($crit): ?>
<div class="only-mobile"><?= Ui::banner(t('stock.alert_t', ['n' => digits(count($crit))]), t('stock.alert', ['names' => implode(', ', array_map(static fn(array $r): string => $r['name'], array_slice($crit, 0, 3)))]), 'danger', 'alert') ?></div>
<?php endif ?>
<div class="row gap-8 only-mobile stock-quick">
  <?= Ui::btn(t('stock.purchase_btn'), ['style' => 'secondary', 'icon' => 'box-in', 'href' => '/stock/purchase', 'class' => 'grow']) ?>
  <?= Ui::btn(t('stock.count_btn'), ['style' => 'secondary', 'icon' => 'clipboard', 'href' => '/stock/count', 'class' => 'grow']) ?>
  <?= Ui::btn(t('stock.waste_btn_m'), ['style' => 'secondary', 'icon' => 'trash', 'href' => '/stock/waste', 'class' => 'grow']) ?>
</div>

<form class="toolbar stock-filter" method="get" action="/stock" data-msearch-box>
  <?= Ui::field('q', ['type' => 'search', 'icon' => 'search', 'placeholder' => t('stock.search'), 'value' => $q, 'class' => 'toolbar__search toolbar__search--280']) ?>
  <?php if ($filter !== ''): ?><input type="hidden" name="f" value="<?= e($filter) ?>"><?php endif ?>
  <div class="chips chips--scroll only-desktop">
    <?= Ui::chip(t('ui.all'), $filter === '', digits(count($all)), ['href' => $url('')]) ?>
    <?= Ui::chip(t('stock.f_critical'), $filter === 'critical', digits($counts['critical']), ['href' => $url('critical')]) ?>
    <?= Ui::chip(t('stock.f_low'), $filter === 'low', digits($counts['low']), ['href' => $url('low')]) ?>
    <?php foreach (array_slice($groups, 0, 5, true) as $g => $n): ?><?= Ui::chip((string) $g, $filter === $g, digits($n), ['href' => $url((string) $g)]) ?><?php endforeach ?>
  </div>
</form>

<?php if (!$all): ?>
  <div class="empty"><?= icon('box', 24) ?><div><?= e(t('stock.none')) ?></div></div>
<?php else: ?>
<section class="card card--pad0 stocktable only-desktop">
  <div class="srow srow--head"><span><?= e(t('stock.c_item')) ?></span><span><?= e(t('stock.c_group')) ?></span><span><?= e(t('stock.c_unit')) ?></span><span><?= e(t('stock.c_stock')) ?></span><span><?= e(t('stock.c_min')) ?></span><span><?= e(t('stock.c_value')) ?></span><span><?= e(t('stock.c_state')) ?></span></div>
  <?php if (!$items): ?><div class="empty"><?= e(t('stock.empty')) ?></div><?php endif ?>
  <?php foreach ($items as $r): ?>
    <button type="button" class="srow" data-load-sheet="/stock/items/<?= e($r['id']) ?>/sheet">
      <span class="t-label-m ellipsis"><?= e($r['name']) ?><?php if ($r['kind'] === 'semi'): ?> <?= icon('layers', 14, 'c-accent') ?><?php endif ?></span>
      <span class="t-body-s c-secondary ellipsis"><?= e((string) $r['category']) ?></span>
      <span class="t-body-s c-secondary"><?= e(Stock::unitLabel($r['unit'])) ?></span>
      <span class="t-label-m num <?= $numClass[$r['state']] ?>"><?= e(Stock::qty($r['on_hand'])) ?></span>
      <span class="t-body-s c-muted num"><?= e(Stock::qty((float) $r['min_qty'])) ?></span>
      <span class="t-body-m c-secondary num"><?= e(money($r['value'])) ?></span>
      <span><?= Ui::badge(t('stock.s.' . $r['state']), $tone[$r['state']], true) ?></span>
    </button>
  <?php endforeach ?>
</section>
<div class="list stocklist only-mobile">
  <?php if (!$items): ?><div class="empty"><?= e(t('stock.empty')) ?></div><?php endif ?>
  <?php foreach ($items as $r):
      $alert = $r['state'] !== 'ok'; ?>
    <?= Ui::lrow($r['name'], [
        'lead' => '<span class="lrow__lead' . ($r['state'] === 'low' ? ' lrow__lead--warn' : '') . '">' . icon($alert ? 'alert' : 'box', 20) . '</span>',
        'sub' => t('stock.min_line', ['group' => (string) $r['category'], 'min' => Stock::qty((float) $r['min_qty']), 'unit' => Stock::unitLabel($r['unit'])]),
        'trail' => Stock::qty($r['on_hand']) . ' ' . Stock::unitLabel($r['unit']), 'trailClass' => $numClass[$r['state']] . ' num', 'chevron' => true,
        'attrs' => ['data-load-sheet' => '/stock/items/' . $r['id'] . '/sheet', 'data-action' => 'item'],
    ]) ?>
  <?php endforeach ?>
</div>
<?php endif ?>
