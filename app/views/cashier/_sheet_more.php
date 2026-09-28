<?php
/** More actions of the phone payment screen (C3): pre-bill, split by guest or item, discount, note. @var array $o */
use Sofrexa\View\Ui;

?>
<div class="scrim" data-dyn hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead(t('ui.more')) ?>
    <div class="sheet__body">
      <div class="list list--flush">
        <?= Ui::lrow(t('order.prebill'), ['icon' => 'printer', 'attrs' => ['data-post' => '/orders/' . $o['id'] . '/prebill', 'data-action' => 'prebill']]) ?>
        <?= Ui::lrow(t('pay.split_person'), ['icon' => 'users', 'href' => '/cashier/pay/' . $o['id'] . '?persons=' . max(2, (int) $o['guests'])]) ?>
        <?= Ui::lrow(t('pay.split_item'), ['icon' => 'split', 'attrs' => ['data-load-sheet' => '/orders/' . $o['id'] . '/sheet/split', 'data-action' => 'split']]) ?>
        <?php if (can('orders.discount')): ?><?= Ui::lrow(t('pay.add_discount'), ['icon' => 'percent', 'attrs' => ['data-load-sheet' => '/cashier/pay/' . $o['id'] . '/sheet/discount', 'data-action' => 'discount']]) ?><?php endif ?>
        <?= Ui::lrow(t('pay.receipt_note'), ['icon' => 'note', 'attrs' => ['data-load-sheet' => '/cashier/pay/' . $o['id'] . '/sheet/note', 'data-action' => 'note']]) ?>
      </div>
    </div>
  </div>
</div>
