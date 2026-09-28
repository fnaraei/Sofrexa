<?php
/**
 * Desktop order panel — Figma W10 (22:1214) right column: head, lines, totals on surface-2, actions.
 * Re-rendered after every change (OrderController::state). @var ?array $o
 */
use Sofrexa\View\OrderUi;
use Sofrexa\View\Ui;

$new = $o ? count(array_filter($o['lines'], static fn(array $l): bool => $l['status'] === 'new')) : 0;
$lines = $o ? OrderUi::sorted($o['lines']) : [];
?>
<div class="opanel__head">
  <h2 class="t-heading-l grow"><?= e(t('order.panel')) ?></h2>
  <?php if ($new): ?><?= Ui::badge(t('order.badge_new', ['n' => digits($new)]), 'accent', true) ?><?php endif ?>
  <?php if ($o && $o['table_id']): ?><?= Ui::ibtn('more', t('order.more'), ['size' => 's', 'attrs' => ['data-load-sheet' => '/tables/' . $o['table_id'] . '/actions']]) ?><?php endif ?>
</div>
<div class="opanel__lines">
  <?php if (!$lines): ?>
    <div class="empty"><?= e(t('order.empty')) ?></div>
  <?php endif ?>
  <?php foreach ($lines as $l): ?><?= OrderUi::line($l, true, $l['status'] !== 'void' ? ['data-line' => $l['id']] : []) ?><?php endforeach ?>
</div>
<div class="opanel__totals">
  <?= OrderUi::totals($o ?? ['subtotal' => 0, 'discount' => 0, 'total' => 0]) ?>
</div>
<div class="opanel__actions">
  <div class="row gap-10">
    <?= Ui::btn(t('order.prebill'), ['style' => 'secondary', 'size' => 'l', 'icon' => 'printer', 'class' => 'grow', 'attrs' => ['data-prebill' => true, 'disabled' => !$o || !$o['lines']]]) ?>
    <?= Ui::btn(t('order.send_n', ['n' => digits($new)]), ['size' => 'l', 'icon' => 'send', 'class' => 'grow', 'attrs' => ['data-send' => true, 'disabled' => !$new]]) ?>
  </div>
  <?php if (can('cash.pay')): ?>
    <?= Ui::btn(t('order.pay', ['amount' => money($o ? (int) $o['total'] - (int) $o['paid'] : 0)]), ['style' => 'accent', 'size' => 'l', 'block' => true, 'icon' => 'cash', 'href' => $o ? '/cashier/pay/' . $o['id'] : null, 'attrs' => ['aria-disabled' => !$o ? 'true' : null, 'data-pay' => true]]) ?>
  <?php endif ?>
</div>
