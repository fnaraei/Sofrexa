<?php
/** Courier settlement ("Hesaplaş"): their delivered orders and the cash they bring back. @var array $c */
use Sofrexa\Modules\Orders\Delivery;
use Sofrexa\View\Ui;
?>
<div class="scrim" data-dyn hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead(t('deliv.settle_t', ['name' => first_name($c['name'])])) ?>
    <div class="sheet__body">
      <p class="t-body-m c-muted"><?= e(t('deliv.settle_help')) ?></p>
      <div class="picklist">
        <?php foreach ($c['orders'] as $o):
            $hint = $o['delivery']['pay_hint'] ?? 'cash'; ?>
          <div class="pickrow"><?= icon($hint === 'card' ? 'credit-card' : ($hint === 'account' ? 'wallet' : 'cash'), 20) ?><span class="grow col gap-2"><span class="t-label-l">#<?= e(digits(sprintf('%04d', (int) $o['no']))) ?> · <?= e(\Sofrexa\Modules\Orders\Till::shortName($o['customer_name'] ?? '')) ?></span><span class="t-body-s c-muted"><?= e(t($hint === 'card' ? 'deliv.pay_card' : ($hint === 'account' ? 'deliv.pay_account' : 'deliv.pay_cash'))) ?></span></span><span class="t-label-l num"><?= e(money(max(0, (int) $o['total'] - (int) $o['paid']))) ?></span></div>
        <?php endforeach ?>
      </div>
      <div class="row between t-heading-m"><span><?= e(t('deliv.c_cash')) ?></span><span class="num"><?= e(Delivery::cashText($c['cash'])) ?></span></div>
      <div class="sheet__actions"><?= Ui::btn(t('ui.cancel'), ['style' => 'secondary', 'size' => 'l', 'attrs' => ['data-close' => true]]) ?><?= Ui::btn(t('deliv.settle_do'), ['size' => 'l', 'icon' => 'check', 'attrs' => ['data-post' => '/delivery/couriers/' . $c['id'] . '/settle', 'data-reload' => true]]) ?></div>
    </div>
  </div>
</div>
