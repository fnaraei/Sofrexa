<?php
/**
 * Shopping list — Figma S7 (39:625, phone): what is under or near its minimum, grouped by supplier with a call
 * button; tick what was ordered; share the list (WhatsApp or the phone's share sheet). @var array $list @var array $suppliers
 */
use Sofrexa\Modules\Stock\Stock;
use Sofrexa\View\Ui;

$back = '/stock';
$title = t('shop.title');
$sub = t('shop.sub');
$bodyClass = 'page-shop';
$lines = [t('shop.text_head', ['date' => date('d.m.Y')])];
foreach ($list as $sup => $items) {
    $lines[] = '';
    $lines[] = '*' . ($sup !== '' ? $sup : t('shop.no_supplier')) . '*';
    foreach ($items as $r) {
        $lines[] = '- ' . $r['name'] . ': ' . Stock::qty((float) $r['suggest']) . ' ' . Stock::unitLabel($r['unit']);
    }
}
$text = implode("\n", $lines);
$appActions = [Ui::ibtn('share', t('shop.share'), ['class' => 'appbar__act', 'attrs' => ['data-share' => $text]]), Ui::ibtn('plus', t('stock.new_item'), ['class' => 'appbar__act', 'attrs' => ['data-load-sheet' => '/stock/items/new/sheet']])];
$headActions = Ui::btn(t('shop.share'), ['icon' => 'share', 'attrs' => ['data-share' => $text]]);
$bottom = $list ? Ui::btn(t('shop.share'), ['size' => 'l', 'block' => true, 'icon' => 'share', 'attrs' => ['data-share' => $text]]) : '';
?>
<div class="shop" data-shop>
  <?php if (!$list): ?><div class="empty"><?= icon('check-circle', 24) ?><div><?= e(t('shop.empty')) ?></div></div><?php endif ?>
  <?php foreach ($list as $sup => $items):
      $phone = $sup !== '' ? (string) ($suppliers[$sup]['phone'] ?? '') : ''; ?>
    <section class="card supcard">
      <div class="supcard__head"><?= icon('store', 18) ?><span class="t-label-l grow"><?= e($sup !== '' ? $sup : t('shop.no_supplier')) ?></span>
        <?php if ($phone !== ''): ?><?= Ui::btn(t('shop.call'), ['style' => 'ghost', 'size' => 's', 'icon' => 'phone', 'href' => 'tel:' . preg_replace('/[^\d+]/', '', $phone)]) ?><?php endif ?></div>
      <?php foreach ($items as $r): ?>
        <label class="checkrow checkrow--shop"><?= Ui::checkbox('got[]', false, ['value' => $r['id']]) ?><span class="grow col" style="gap:0"><span class="t-body-l"><?= e($r['name']) ?></span><?php if ($r['state'] === 'critical'): ?><span class="t-label-s c-danger"><?= e(t('shop.critical')) ?></span><?php endif ?></span><span class="t-label-m c-secondary num"><?= e(Stock::qty((float) $r['suggest']) . ' ' . Stock::unitLabel($r['unit'])) ?></span></label>
      <?php endforeach ?>
    </section>
  <?php endforeach ?>
</div>
