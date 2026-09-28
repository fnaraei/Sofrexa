<?php
/** Merge this table's bill into another busy table (W7 › Birleştir). @var array $o @var array $targets */
use Sofrexa\Modules\Orders\Board;
use Sofrexa\View\Ui;
?>
<div class="scrim" data-dyn hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead(t('merge.title')) ?>
    <form class="sheet__body" method="post" action="/orders/<?= e($o['id']) ?>/merge" data-ajax data-toast="off">
      <?= csrf_field() ?>
      <p class="t-body-m c-muted"><?= e(t('merge.help')) ?></p>
      <?php if (!$targets): ?>
        <div class="empty"><?= e(t('merge.none')) ?></div>
      <?php else: ?>
        <div class="picklist">
          <?php foreach ($targets as $x): ?>
            <label class="pickrow"><input type="radio" name="into" value="<?= e($x['id']) ?>"><span class="grow col gap-2"><span class="t-label-l"><?= e(Board::title($x)) ?></span><span class="t-body-s c-muted"><?= e(first_name($x['waiter_name'] ?? '') . ' · ' . dur((int) $x['opened_at'])) ?></span></span><span class="t-label-l num"><?= e(money((int) $x['total'])) ?></span></label>
          <?php endforeach ?>
        </div>
      <?php endif ?>
      <div class="sheet__actions"><?= Ui::btn(t('ui.cancel'), ['style' => 'secondary', 'size' => 'l', 'attrs' => ['data-close' => true]]) ?><?= Ui::btn(t('merge.title'), ['type' => 'submit', 'size' => 'l', 'icon' => 'merge', 'attrs' => ['disabled' => !$targets]]) ?></div>
    </form>
  </div>
</div>
