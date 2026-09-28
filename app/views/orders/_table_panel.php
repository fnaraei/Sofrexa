<?php
/**
 * Desktop table panel — Figma W11 (23:1550) right column: the table's lines, total and actions.
 * @var ?array $t table from Board::areas() with 'area' and 'order' (full order when busy)
 */
use Sofrexa\Modules\Orders\Board;
use Sofrexa\View\OrderUi;
use Sofrexa\View\Ui;

if (!$t): ?>
  <div class="tpanel__empty"><?= icon('grid', 24) ?><div class="t-label-l"><?= e(t('tables.pick')) ?></div><div class="t-body-s c-muted"><?= e(t('tables.pick_sub')) ?></div></div>
<?php return; endif;
$o = $t['order'];
$area = tn(json_arr($t['area']['names']) ?: $t['area']['name']);
?>
<div class="row gap-8">
  <div class="grow col gap-2">
    <h2 class="t-heading-l"><?= e(t('order.table', ['n' => digits($t['number'])])) ?></h2>
    <div class="t-body-s c-muted ellipsis"><?= e($o ? t('tables.panel_sub', ['area' => $area, 'g' => digits(max(1, (int) $o['guests'])), 'waiter' => first_name($o['waiter_name'] ?? '—'), 't' => dur((int) $o['opened_at'])]) : $area . ' · ' . t('tables.empty_table')) ?></div>
  </div>
  <?= $o ? Ui::badge(t('tables.busy'), 'accent', true) : Ui::badge(t('tables.lg_free'), 'neutral', true) ?>
</div>
<?php if ($o): ?>
  <div class="tpanel__lines">
    <?php foreach (OrderUi::sorted($o['lines']) as $l): ?><?= OrderUi::line($l, false) ?><?php endforeach ?>
  </div>
  <div class="row between tpanel__total t-heading-m"><span><?= e(t('tables.total')) ?></span><span class="num"><?= e(money((int) $o['total'])) ?></span></div>
  <div class="grow"></div>
  <?= Ui::btn(t('tables.add_order'), ['style' => 'secondary', 'size' => 'l', 'block' => true, 'icon' => 'plus', 'href' => '/orders/' . $o['id']]) ?>
  <?= Ui::btn(t('tables.print_bill'), ['style' => 'secondary', 'size' => 'l', 'block' => true, 'icon' => 'printer', 'attrs' => ['data-post' => '/orders/' . $o['id'] . '/prebill']]) ?>
  <?= Ui::btn(t('tables.move_merge'), ['style' => 'secondary', 'size' => 'l', 'block' => true, 'icon' => 'transfer', 'attrs' => ['data-load-sheet' => '/tables/' . $t['id'] . '/actions']]) ?>
  <?php if (can('cash.pay')): ?><?= Ui::btn(t('tables.pay', ['amount' => money((int) $o['total'] - (int) $o['paid'])]), ['style' => 'accent', 'size' => 'l', 'block' => true, 'icon' => 'cash', 'href' => '/cashier/pay/' . $o['id']]) ?><?php endif ?>
<?php else: ?>
  <div class="grow"></div>
  <?= Ui::btn(t('tables.start'), ['size' => 'l', 'block' => true, 'icon' => 'plus', 'href' => '/tables/' . $t['id'] . '/order']) ?>
<?php endif ?>
