<?php
/** Discount on a bill (C2 › İndirim ekle): percent or amount, with a reason; logged. @var array $o @var string $discountText */
use Sofrexa\View\Ui;
?>
<div class="scrim" data-dyn hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead(t('disc.title')) ?>
    <form class="sheet__body" method="post" action="/cashier/pay/<?= e($o['id']) ?>/discount" data-ajax data-toast="off">
      <?= csrf_field() ?>
      <?= Ui::segs(['pct' => t('disc.pct'), 'amount' => t('disc.amount')], 'pct', 'kind') ?>
      <?= Ui::field('value', ['label' => t('disc.value'), 'icon' => 'percent', 'attrs' => ['inputmode' => 'decimal', 'autofocus' => true]]) ?>
      <div class="chips chips--wrap" data-fill="reason">
        <?php foreach (['disc.r_regular', 'disc.r_staff', 'disc.r_treat', 'disc.r_complaint'] as $k): ?><?= Ui::chip(t($k), false, null, ['data-value' => t($k)]) ?><?php endforeach ?>
      </div>
      <?= Ui::field('reason', ['label' => t('disc.reason'), 'icon' => 'note']) ?>
      <div class="sheet__actions">
        <?php if ((int) $o['discount'] > 0): ?><?= Ui::btn(t('disc.remove'), ['style' => 'secondary', 'size' => 'l', 'attrs' => ['data-post' => '/cashier/pay/' . $o['id'] . '/discount', 'data-body' => '{"clear":1}']]) ?><?php else: ?><?= Ui::btn(t('ui.cancel'), ['style' => 'secondary', 'size' => 'l', 'attrs' => ['data-close' => true]]) ?><?php endif ?>
        <?= Ui::btn(t('disc.apply'), ['type' => 'submit', 'size' => 'l', 'icon' => 'check']) ?>
      </div>
    </form>
  </div>
</div>
