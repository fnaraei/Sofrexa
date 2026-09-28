<?php
/**
 * Order summary — Figma W3 (18:295, phone): new lines, lines in the kitchen, totals; pre-bill and send.
 * @var array $o
 */
use Sofrexa\Modules\Orders\Board;
use Sofrexa\View\OrderUi;
use Sofrexa\View\Ui;

$where = in_array($o['channel'], ['table', 'qr'], true) ? t('order.table', ['n' => digits($o['table_no'])]) : Board::title($o);
$appTitle = t('order.summary_title', ['where' => $where]);
$title = $appTitle;
$appSub = t('order.summary_sub', ['area' => Board::areaName($o) ?: Board::title($o), 'g' => digits(max(1, (int) $o['guests'])), 'waiter' => first_name($o['waiter_name'] ?? '—')]);
$back = '/orders/' . $o['id'];
$appActions = [];
if ($o['table_id']) {
    $appActions[] = Ui::ibtn('transfer', t('act.move'), ['class' => 'appbar__act', 'attrs' => ['data-load-sheet' => '/orders/' . $o['id'] . '/sheet/move']]);
    $appActions[] = Ui::ibtn('more', t('order.more'), ['class' => 'appbar__act', 'attrs' => ['data-load-sheet' => '/tables/' . $o['table_id'] . '/actions']]);
}
$new = array_values(array_filter($o['lines'], static fn(array $l): bool => $l['status'] === 'new'));
$rest = array_values(array_filter(OrderUi::sorted($o['lines']), static fn(array $l): bool => $l['status'] !== 'new'));
$newQty = \Sofrexa\Modules\Orders\Orders::qtyText((float) array_sum(array_column($new, 'qty')));
$firstSent = min(array_map(static fn(array $l): int => (int) ($l['sent_at'] ?: PHP_INT_MAX), $rest ?: [['sent_at' => 0]]));
$bottom = Ui::btn(t('order.prebill'), ['style' => 'secondary', 'size' => 'l', 'icon' => 'printer', 'class' => 'btn--hug', 'attrs' => ['data-prebill' => true]])
    . Ui::btn(t('order.send_n', ['n' => digits($newQty)]), ['size' => 'l', 'icon' => 'send', 'attrs' => ['data-send' => true, 'disabled' => !$new]]);
$ctxJson = json_encode(['order' => $o['id'], 'table' => $o['table_id'] ?? '', 'guests' => 0, 'channel' => $o['channel'], 'summary' => true]);
?>
<div class="col gap-16" data-take='<?= e($ctxJson) ?>'>
  <?php if ($new): ?>
    <section class="ocard">
      <div class="ocard__head"><span class="overline"><?= e(t('order.sec_new')) ?></span><?= Ui::badge(t('order.badge_items', ['n' => digits($newQty)]), 'accent', true) ?></div>
      <?php foreach ($new as $l): ?><?= OrderUi::line($l, true, ['data-line' => $l['id']]) ?><?php endforeach ?>
    </section>
  <?php endif ?>
  <?php if ($rest): ?>
    <section class="ocard">
      <div class="ocard__head"><span class="overline"><?= e(t('order.sec_sent')) ?></span><?= $firstSent > 0 && $firstSent < PHP_INT_MAX ? Ui::badge(digits(date('H:i', intdiv($firstSent, 1000))), 'info', true) : '' ?></div>
      <?php foreach ($rest as $l): ?><?= OrderUi::line($l, true, $l['status'] !== 'void' ? ['data-line' => $l['id']] : []) ?><?php endforeach ?>
    </section>
  <?php endif ?>
  <?php if (!$o['lines']): ?><div class="empty"><?= e(t('order.empty')) ?></div><?php endif ?>
  <section class="card ocard__totals">
    <?= OrderUi::totals($o, 'order.total_vat_m', 't-heading-m') ?>
  </section>
</div>
