<?php
/** A tier's name and badge colour, or its removal (the badge of a CU5 tier row). @var array $t @var int $count */
use Sofrexa\Modules\Customers\Loyalty;
use Sofrexa\View\Ui;

?>
<div class="scrim" data-dyn hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead(t('loy.tier_edit')) ?>
    <form class="sheet__body" method="post" action="/customers/loyalty/tier/<?= e($t['id']) ?>" data-ajax data-toast="off">
      <?= csrf_field() ?>
      <?= Ui::field('name', ['label' => t('loy.tier_name'), 'icon' => 'star', 'value' => $t['name'], 'attrs' => ['required' => true, 'maxlength' => 40]]) ?>
      <span class="overline"><?= e(t('loy.tier_color')) ?></span>
      <div class="chips chips--wrap">
        <?php foreach (Loyalty::TONES as $tone): ?>
          <label class="tonepick"><input type="radio" name="tone" value="<?= e($tone) ?>"<?= $tone === $t['tone'] ? ' checked' : '' ?>><?= Ui::badge($t['name'], $tone) ?></label>
        <?php endforeach ?>
      </div>
      <div class="sheet__actions">
        <?= Ui::btn(t('ui.cancel'), ['style' => 'secondary', 'size' => 'l', 'attrs' => ['data-close' => true]]) ?>
        <?= Ui::btn(t('ui.save'), ['size' => 'l', 'icon' => 'check', 'type' => 'submit']) ?>
      </div>
      <?php if ($count > 1): ?>
        <button type="button" class="btn btn--ghost btn--s c-danger sheet__del" data-post="/customers/loyalty/tier/<?= e($t['id']) ?>/delete" data-confirm="<?= e(t('loy.tier_delete_q', ['name' => $t['name']])) ?>"><?= icon('trash', 16) ?><span><?= e(t('loy.tier_delete')) ?></span></button>
      <?php endif ?>
    </form>
  </div>
</div>
