<?php
/**
 * Waste — Figma S4 (38:606, phone): pick an item, quantity with its value, a reason and a note; the stock goes
 * down and it shows in reports. @var array $recent
 */
use Sofrexa\Modules\Stock\Stock;
use Sofrexa\View\Ui;

$back = '/stock';
$title = t('waste.title');
$sub = t('waste.sub');
$appActions = [Ui::ibtn('history', t('stock.moves'), ['class' => 'appbar__act', 'attrs' => ['data-sheet' => 'waste-history']])];
$bodyClass = 'page-waste';
$bottom = Ui::btn(t('waste.save'), ['style' => 'danger', 'size' => 'l', 'block' => true, 'icon' => 'trash', 'type' => 'submit', 'attrs' => ['form' => 'waste-form']]);
$headActions = Ui::btn(t('waste.save'), ['style' => 'danger', 'icon' => 'trash', 'type' => 'submit', 'attrs' => ['form' => 'waste-form']]);
$str = ['stock' => t('waste.stock', ['q' => '{q}', 'unit' => '{unit}', 'price' => '{price}']), 'qty' => t('waste.qty', ['unit' => '{unit}']), 'worth' => t('waste.worth', ['amount' => '{amount}'])];
?>
<?php
// S4b: cooked dishes cancelled at a table are waste too — they are handled on the till's "Hazır iptaller" page
$wasteN = can('cash.pay') ? \Sofrexa\Modules\Orders\Voids::wasteCount() : 0;
$pendingN = can('cash.pay') ? \Sofrexa\Modules\Orders\Voids::pendingCount() : 0;
?>
<?php if ($wasteN || $pendingN): ?>
  <a class="selcard selcard--link voidcard" href="/cashier/voids"><?= icon('x-circle', 22) ?><span class="grow col gap-2"><span class="t-label-l"><?= e(t('voids.card')) ?></span>
    <span class="t-body-s c-muted"><?= e(t('voids.card_sub', ['w' => digits($wasteN), 'p' => digits($pendingN)])) ?></span></span><?= icon('chevron-right', 20) ?></a>
<?php endif ?>
<form class="waste" id="waste-form" method="post" action="/stock/waste" data-ajax data-toast="off" data-waste data-str='<?= e(json_encode($str, JSON_UNESCAPED_UNICODE)) ?>' autocomplete="off">
  <?= csrf_field() ?>
  <input type="hidden" name="stock_item_id" value="">
  <div class="puradd">
    <div class="field field--search"><div class="field__box"><?= icon('search', 20) ?><input type="search" placeholder="<?= e(t('waste.search')) ?>" data-add-search aria-label="<?= e(t('waste.search')) ?>"></div></div>
    <div class="picklist puradd__results" data-add-results hidden></div>
  </div>
  <div class="selcard" data-picked hidden><?= icon('box', 22) ?><span class="grow col gap-2"><span class="t-label-l" data-picked-name></span><span class="t-body-s c-muted" data-picked-sub></span></span></div>
  <div class="overline overline--keep" data-qty-label><?= e(t('waste.qty', ['unit' => 'kg'])) ?></div>
  <div class="row gap-12">
    <div class="qty qty--l" data-stepper><button type="button" data-step="-0.1" aria-label="−"><?= icon('minus', 20) ?></button><span class="qty__n num" data-stepper-n><?= e(digits('0')) ?></span><button type="button" data-step="0.1" aria-label="+"><?= icon('plus', 20) ?></button><input type="hidden" name="qty" value="0" data-stepper-v data-min="0" data-max="9999"></div>
    <span class="t-body-m c-secondary grow" data-worth></span>
  </div>
  <div class="overline"><?= e(t('waste.reason')) ?></div>
  <div class="chips chips--wrap">
    <?php foreach (['waste.r_spoiled', 'waste.r_dropped', 'waste.r_return', 'waste.r_staff', 'waste.r_other'] as $i => $k): ?><?= Ui::chipRadio('reason', t($k), t($k), $i === 0) ?><?php endforeach ?>
  </div>
  <?= Ui::field('note', ['label' => t('waste.note'), 'icon' => 'note']) ?>
</form>

<div class="scrim" id="waste-history" hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead(t('stock.moves')) ?>
    <div class="sheet__body">
      <div class="picklist">
        <?php foreach ($recent as $m): ?>
          <div class="pickrow"><span class="grow col gap-2"><span class="t-label-m"><?= e($m['name']) ?></span><span class="t-body-s c-muted"><?= e(when_label((int) $m['at']) . ' · ' . (explode(':', (string) $m['reason'], 2)[1] ?? '') . ($m['user_name'] ? ' · ' . first_name($m['user_name']) : '')) ?></span></span><span class="t-label-m num c-danger"><?= e('−' . Stock::qty(abs((float) $m['qty'])) . ' ' . Stock::unitLabel($m['unit'])) ?></span></div>
        <?php endforeach ?>
        <?php if (!$recent): ?><div class="empty"><?= e(t('stock.empty')) ?></div><?php endif ?>
      </div>
    </div>
  </div>
</div>
