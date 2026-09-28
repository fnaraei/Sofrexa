<?php
/** Hand the table to another waiter (W7 › Garson). @var array $o @var array $staff */
use Sofrexa\View\Ui;
?>
<div class="scrim" data-dyn hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead(t('waiter.title')) ?>
    <form class="sheet__body" method="post" action="/orders/<?= e($o['id']) ?>/waiter" data-ajax data-reload data-toast="off">
      <?= csrf_field() ?>
      <div class="picklist">
        <?php foreach ($staff as $s): ?>
          <label class="pickrow"><input type="radio" name="user_id" value="<?= e($s['id']) ?>"<?= $s['id'] === $o['waiter_id'] ? ' checked' : '' ?>><?= Ui::avatar($s['name'], 's') ?><span class="grow t-label-l"><?= e($s['name']) ?></span><?= icon('check', 20, 'pickrow__check') ?></label>
        <?php endforeach ?>
      </div>
      <div class="sheet__actions"><?= Ui::btn(t('ui.cancel'), ['style' => 'secondary', 'size' => 'l', 'attrs' => ['data-close' => true]]) ?><?= Ui::btn(t('ui.save'), ['type' => 'submit', 'size' => 'l', 'icon' => 'check']) ?></div>
    </form>
  </div>
</div>
