<?php
/**
 * Promotion editor dialog — Figma PR2 (117:977): 880 wide, header without the grab handle, two columns,
 * live preview ("Margarita ₺650 → ₺520"), footer Sil · Vazgeç · Kaydet.
 * @var array $p @var array $categories @var array $items @var ?string $state
 */
use Sofrexa\View\Ui;
?>
<div class="scrim" data-dyn hidden>
  <form class="sheet sheet--promo" method="post" action="/menu/promotions/save" data-ajax data-promo-form role="dialog" aria-modal="true">
    <div class="sheet__head sheet__head--dialog"><h2 class="sheet__title"><?= e(t($p['id'] ? 'promo.edit' : 'promo.new')) ?></h2><button type="button" class="sheet__close" data-close aria-label="<?= e(t('ui.close')) ?>"><?= icon('close', 20) ?></button></div>
    <?= \Sofrexa\Core\View::partial('menu/_promo_form', ['p' => $p, 'categories' => $categories, 'items' => $items, 'mode' => 'desk', 'uid' => 'pd']) ?>
    <div class="promo__previewwrap">
      <div class="promo__preview" data-promo-preview><?= icon('percent', 22) ?><div class="col gap-2"><span class="t-label-l" data-preview-title>…</span><span class="t-body-s c-secondary" data-preview-sub></span></div></div>
    </div>
    <div class="promo__foot">
      <?php if ($p['id']): ?><?= Ui::btn(t('promo.delete'), ['style' => 'danger', 'icon' => 'trash', 'attrs' => ['data-post' => '/menu/promotions/' . $p['id'] . '/delete', 'data-confirm' => t('promo.delete_confirm')]]) ?><?php endif ?>
      <span class="grow"></span>
      <?= Ui::btn(t('ui.cancel'), ['style' => 'secondary', 'attrs' => ['data-close' => true]]) ?>
      <?= Ui::btn(t('ui.save'), ['type' => 'submit', 'icon' => 'check', 'attrs' => ['data-promo-save' => true]]) ?>
    </div>
  </form>
</div>
