<?php
/**
 * Table map — Figma W1 (17:2 phone), W8 (21:1085 Persian), W9 (20:912 offline banner), W11 (23:1550 desktop with the table panel).
 * @var array $areas @var string $areaId @var string $filter @var array $counts @var array $all @var string $avg @var ?array $selected @var int $mineOpen
 */
use Sofrexa\View\OrderUi;
use Sofrexa\View\Ui;

$u = user();
$appSub = t('tables.sub_waiter', ['name' => first_name($u['name']), 'n' => digits($mineOpen)]);
$sub = t('tables.sub_desk', ['tables' => digits($all['all']), 'busy' => digits($all['busy']), 'avg' => $avg]);
$appActions = [
    Ui::ibtn('search', t('ui.search'), ['class' => 'appbar__act', 'attrs' => ['data-table-search' => true]]),
    Ui::ibtn('more', t('ui.more'), ['class' => 'appbar__act', 'attrs' => ['data-sheet' => 'tables-more']]),
];
$legend = '<div class="legend">' . Ui::badge(t('tables.lg_free'), 'neutral', true) . Ui::badge(t('tables.lg_busy'), 'accent', true) . Ui::badge(t('tables.lg_bill'), 'warning', true)
    . Ui::badge(t('tables.lg_qr'), 'attention', true) . Ui::badge(t('tables.lg_ready'), 'success', true) . Ui::badge(t('tables.lg_late'), 'danger', true) . '</div>';
$headActions = $legend . Ui::btn(t('tables.takeaway'), ['icon' => 'bag', 'href' => '/orders/new/takeaway']);
$bodyClass = 'page-tables';

$q = static fn(array $over): string => '/tables?' . http_build_query(array_filter($over + ['a' => $areaId, 'f' => $filter], static fn($v): bool => $v !== '' && $v !== null));
$current = null;
foreach ($areas as $a) {
    if ($a['id'] === $areaId) {
        $current = $a;
    }
}
$segs = [$q(['a' => 'all']) => t('ui.all')];
foreach ($areas as $a) {
    $segs[$q(['a' => $a['id']])] = tn(json_arr($a['names']) ?: $a['name']);
}
$activeSeg = $q(['a' => $areaId]);
$chip = static fn(string $key, string $label) => Ui::chip($label, $filter === $key, digits($counts[$key]), ['href' => $q(['f' => $filter === $key ? '' : $key])]);
$phoneTables = [];
foreach ($areas as $a) {
    if ($areaId === 'all' || $a['id'] === $areaId) {
        foreach ($a['tables'] as $t) {
            if (\Sofrexa\Modules\Orders\Board::matches($t, $filter)) {
                $phoneTables[] = $t;
            }
        }
    }
}
$summaryName = $current ? tn(json_arr($current['names']) ?: $current['name']) : t('ui.all');
$aside = '<div class="tpanel" data-table-panel>' . \Sofrexa\Core\View::partial('orders/_table_panel', ['t' => $selected]) . '</div>';
?>
<?php if (!\Sofrexa\Core\App::isWeb()): ?>
<div data-offline-banner<?= \Sofrexa\Sync\Status::get()['state'] === 'offline' ? '' : ' hidden' ?>><?= Ui::banner(t('tables.offline_t'), t('tables.offline'), 'warning', 'info') ?></div>
<?php endif ?>
<?php if (!$areas): ?>
  <div class="empty"><?= icon('grid', 24) ?><span><?= e(t('tables.no_areas')) ?></span></div>
<?php else: ?>
<div class="only-mobile col gap-14 tables-m">
  <?= Ui::segs($segs, $activeSeg) ?>
  <div class="chips chips--scroll"><?= $chip('mine', t('tables.f_mine')) . $chip('free', t('tables.f_free')) . $chip('busy', t('tables.f_busy')) . $chip('bill', t('tables.f_bill')) ?></div>
  <div class="row between">
    <span class="t-label-m c-secondary ellipsis"><?= e(t('tables.summary', ['area' => $summaryName, 'n' => digits($counts['all']), 'busy' => digits($counts['busy'])])) ?></span>
    <?= Ui::sync() ?>
  </div>
  <div class="tiles">
    <?php foreach ($phoneTables as $t): ?><?= OrderUi::tile($t, false, false, ['href' => '/tables/' . $t['id'] . '/order']) ?><?php endforeach ?>
  </div>
</div>

<div class="only-desktop col gap-18">
  <?php foreach ($areas as $a): ?>
    <section class="tarea">
      <div class="tarea__head"><?= icon($a['icon'], 20) ?><h2 class="t-heading-m"><?= e(tn(json_arr($a['names']) ?: $a['name'])) ?></h2><span class="t-body-s c-muted"><?= e(t('tables.area_count', ['n' => digits(count($a['tables'])), 'busy' => digits($a['busy'])])) ?></span></div>
      <div class="tiles tiles--desk">
        <?php foreach ($a['tables'] as $t): ?><?= OrderUi::tile($t, true, $selected && $selected['id'] === $t['id'], ['href' => '/tables?t=' . $t['id'], 'data-select' => true]) ?><?php endforeach ?>
      </div>
    </section>
  <?php endforeach ?>
</div>
<?php endif ?>

<div class="scrim" id="tables-more" hidden>
  <div class="sheet" role="dialog" aria-modal="true" aria-labelledby="tables-more-t">
    <?= Ui::sheetHead(t('tables.title')) ?>
    <div class="sheet__body">
      <div class="list list--flush">
        <?= Ui::lrow(t('tables.takeaway'), ['icon' => 'bag', 'href' => '/orders/new/takeaway']) ?>
        <?= Ui::lrow(t('notif.title'), ['icon' => 'bell', 'href' => '/my/notifications']) ?>
        <?php if (can('cash.pay')): ?><?= Ui::lrow(t('cash.title'), ['icon' => 'receipt', 'href' => '/cashier']) ?><?php endif ?>
      </div>
    </div>
  </div>
</div>
<div class="scrim" id="table-find" hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead(t('floor.table_no')) ?>
    <form class="sheet__body" data-table-find>
      <?= Ui::field('n', ['icon' => 'hash', 'type' => 'text', 'attrs' => ['inputmode' => 'numeric', 'autofocus' => true]]) ?>
      <div class="sheet__actions"><?= Ui::btn(t('ui.search'), ['type' => 'submit', 'size' => 'l', 'block' => true, 'icon' => 'search']) ?></div>
    </form>
  </div>
</div>
