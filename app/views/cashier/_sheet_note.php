<?php
/** Receipt note (C2 › Fiş notu), printed under the receipt. @var array $o */
use Sofrexa\View\Ui;
?>
<div class="scrim" data-dyn hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead(t('note.title')) ?>
    <form class="sheet__body" method="post" action="/cashier/pay/<?= e($o['id']) ?>/note" data-ajax data-toast="off">
      <?= csrf_field() ?>
      <?= Ui::field('note', ['label' => t('note.title'), 'icon' => 'note', 'textarea' => true, 'value' => (string) $o['receipt_note'], 'help' => t('note.help'), 'attrs' => ['maxlength' => 200, 'autofocus' => true]]) ?>
      <div class="sheet__actions"><?= Ui::btn(t('ui.cancel'), ['style' => 'secondary', 'size' => 'l', 'attrs' => ['data-close' => true]]) ?><?= Ui::btn(t('ui.save'), ['type' => 'submit', 'size' => 'l', 'icon' => 'check']) ?></div>
    </form>
  </div>
</div>
