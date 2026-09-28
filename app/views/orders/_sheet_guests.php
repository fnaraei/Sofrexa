<?php
/** Guest count (W7 › Kişi sayısı). @var array $o */
use Sofrexa\View\Ui;

$g = max(1, (int) $o['guests']);
?>
<div class="scrim" data-dyn hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead(t('guests.title')) ?>
    <form class="sheet__body" method="post" action="/orders/<?= e($o['id']) ?>/guests" data-ajax data-reload data-toast="off">
      <?= csrf_field() ?>
      <div class="row center">
        <div class="qty qty--l" data-stepper><button type="button" data-step="-1" aria-label="−"><?= icon('minus', 20) ?></button><span class="qty__n num" data-stepper-n><?= e(digits($g)) ?></span><button type="button" data-step="1" aria-label="+"><?= icon('plus', 20) ?></button><input type="hidden" name="guests" value="<?= $g ?>" data-stepper-v data-min="1" data-max="99"></div>
      </div>
      <div class="sheet__actions"><?= Ui::btn(t('ui.cancel'), ['style' => 'secondary', 'size' => 'l', 'attrs' => ['data-close' => true]]) ?><?= Ui::btn(t('ui.save'), ['type' => 'submit', 'size' => 'l', 'icon' => 'check']) ?></div>
    </form>
  </div>
</div>
