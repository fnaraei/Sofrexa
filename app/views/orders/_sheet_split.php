<?php
/** Pick the lines paid separately (W7 › Hesabı böl, C2 › Ürüne böl). @var array $o */
use Sofrexa\Modules\Orders\Board;
use Sofrexa\View\Ui;
?>
<div class="scrim" data-dyn hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead(t('split.title')) ?>
    <form class="sheet__body" method="post" action="/orders/<?= e($o['id']) ?>/split" data-ajax data-toast="off">
      <?= csrf_field() ?>
      <p class="t-body-m c-muted"><?= e(t('split.help')) ?></p>
      <div class="picklist">
        <?php foreach ($o['lines'] as $l): if ($l['status'] === 'void') continue; ?>
          <label class="pickrow"><?= Ui::checkbox('lines[]', false, ['value' => $l['id']]) ?><span class="grow col gap-2"><span class="t-label-l"><?= e(Board::qty((float) $l['qty']) . ' ' . $l['name']) ?></span><?php if (($m = Board::lineMods($l)) !== ''): ?><span class="t-body-s c-muted"><?= e($m) ?></span><?php endif ?></span><span class="t-label-l num"><?= e(money(Board::lineTotal($l))) ?></span></label>
        <?php endforeach ?>
      </div>
      <div class="sheet__actions"><?= Ui::btn(t('ui.cancel'), ['style' => 'secondary', 'size' => 'l', 'attrs' => ['data-close' => true]]) ?><?= Ui::btn(t('split.do'), ['type' => 'submit', 'size' => 'l', 'icon' => 'split']) ?></div>
    </form>
  </div>
</div>
