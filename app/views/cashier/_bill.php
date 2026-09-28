<?php
/**
 * The bill side of the payment screen — Figma C2 (26:232) left panel: lines, discount and note, totals.
 * Also the body of the phone "bill" sheet. @var array $o @var string $discountText @var array $vat @var bool $sheet
 */
use Sofrexa\View\OrderUi;
use Sofrexa\View\Ui;

$sheet ??= false;
$vat = array_filter($vat, static fn(array $v, string|int $rate): bool => (float) $rate > 0 && $v[1] > 0, ARRAY_FILTER_USE_BOTH);
$vatText = '';
$vatSum = array_sum(array_column($vat, 1));
if (count($vat) === 1) {
    $vatText = t('pay.vat_in', ['r' => digits(\Sofrexa\Core\I18n::num((float) array_key_first($vat)))]);
} elseif ($vat) {
    $vatText = t('pay.vat_in_mixed');
}
?>
<div class="bill__lines">
  <?php foreach (OrderUi::sorted($o['lines']) as $l): ?><?= OrderUi::line($l, true) ?><?php endforeach ?>
</div>
<div class="row gap-8">
  <?php if (can('orders.discount')): ?><?= Ui::btn(t('pay.add_discount'), ['style' => 'ghost', 'size' => 's', 'icon' => 'percent', 'attrs' => ['data-load-sheet' => '/cashier/pay/' . $o['id'] . '/sheet/discount']]) ?><?php endif ?>
  <?= Ui::btn(t('pay.receipt_note'), ['style' => 'ghost', 'size' => 's', 'icon' => 'note', 'attrs' => ['data-load-sheet' => '/cashier/pay/' . $o['id'] . '/sheet/note']]) ?>
</div>
<div class="bill__totals">
  <div class="kv"><span><?= e(t('order.subtotal')) ?></span><span class="num"><?= e(money((int) $o['subtotal'])) ?></span></div>
  <?php if ((int) $o['discount'] > 0): ?>
    <div class="kv"><span><?= e(t('pay.discount_line', ['desc' => $discountText])) ?></span><span class="num c-success"><?= e(money(-(int) $o['discount'])) ?></span></div>
  <?php endif ?>
  <?php if ((int) $o['paid'] > 0): ?>
    <div class="kv"><span><?= e(t('pay.paid_before')) ?></span><span class="num c-success"><?= e(money(-(int) $o['paid'])) ?></span></div>
  <?php endif ?>
  <div class="kv kv--xl"><span><?= e(t('pay.total')) ?></span><span class="num"><?= e(money((int) $o['total'] - (int) $o['paid'])) ?></span></div>
  <?php if ($vatText !== ''): ?><div class="kv kv--s"><span><?= e($vatText) ?></span><span class="num"><?= e(money($vatSum)) ?></span></div><?php endif ?>
  <?php if (!empty($o['receipt_note'])): ?><div class="t-body-s c-muted"><?= icon('note', 14) ?> <?= e($o['receipt_note']) ?></div><?php endif ?>
</div>
