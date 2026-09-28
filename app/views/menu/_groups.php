<?php
/** Option groups (e.g. "Acılık", single choice; "Çıkar", multiple): list sheet and edit sheet with options. @var array $groups */
use Sofrexa\View\Ui;

$gjson = static fn(array $g): string => json_encode(['id' => $g['id'], 'names' => json_arr($g['names']), 'kind' => $g['kind'], 'required' => (int) $g['min_sel'] > 0,
    'options' => array_map(static fn(array $o): array => ['id' => $o['id'], 'names' => json_arr($o['names']), 'price' => (int) $o['price']], $g['options'])], JSON_UNESCAPED_UNICODE);
?>
<div class="scrim" id="group-list" hidden>
  <div class="sheet sheet--wide">
    <?= Ui::sheetHead(t('menu.groups')) ?>
    <div class="sheet__body">
      <?php if (!$groups): ?><div class="empty"><?= e(t('menu.groups_empty')) ?></div><?php endif ?>
      <div class="list list--flush">
        <?php foreach ($groups as $g): ?>
          <button type="button" class="lrow" data-group='<?= e($gjson($g)) ?>'>
            <span class="lrow__lead"><?= icon('list', 20) ?></span>
            <span class="lrow__mid"><span class="lrow__title ellipsis"><?= e(tn($g['names'])) ?> <span class="c-muted t-body-s">(<?= e(t($g['kind'] === 'multi' ? 'menu.multi' : 'menu.single')) ?>)</span></span><span class="lrow__sub ellipsis"><?= e(implode(' · ', array_map(static fn(array $o): string => tn($o['names']), $g['options']))) ?></span></span>
            <span class="lrow__chev"><?= icon('chevron-right', 20) ?></span>
          </button>
        <?php endforeach ?>
      </div>
      <div class="sheet__actions"><?= Ui::btn(t('menu.add_group'), ['size' => 'l', 'icon' => 'plus', 'attrs' => ['data-group-new' => true]]) ?></div>
    </div>
  </div>
</div>

<div class="scrim" id="group-edit" hidden>
  <form class="sheet sheet--wide" method="post" action="/menu/groups/save" data-ajax data-reload>
    <?= Ui::sheetHead(t('menu.group_title')) ?>
    <div class="sheet__body">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="">
      <?= \Sofrexa\Core\View::partial('menu/_langtabs', ['fields' => [['names', 'menu.group_name', 'list', [], false]]]) ?>
      <div class="frow">
        <div class="field"><span class="field__label"><?= e(t('menu.group_kind')) ?></span><?= Ui::segs(['single' => t('menu.single'), 'multi' => t('menu.multi')], 'single', 'kind') ?></div>
        <?= Ui::toggleRow('required', t('menu.group_required'), null, false) ?>
      </div>
      <div class="overline"><?= e(t('menu.options')) ?></div>
      <div class="optrows" data-options></div>
      <template data-option-tpl>
        <div class="optrow">
          <input type="hidden" data-o="id">
          <div class="field__box grow"><?= icon('tag', 20) ?><input type="text" data-o="tr" placeholder="<?= e(t('menu.option')) ?> (TR)" maxlength="80"></div>
          <div class="field__box optrow__lang"><input type="text" data-o="en" placeholder="EN" maxlength="80"></div>
          <div class="field__box optrow__lang"><input type="text" data-o="ru" placeholder="RU" maxlength="80"></div>
          <div class="field__box optrow__lang" dir="rtl"><input type="text" data-o="fa" placeholder="FA" maxlength="80"></div>
          <div class="field__box optrow__price"><input type="text" data-o="price" placeholder="+₺0" inputmode="decimal"></div>
          <?= Ui::ibtn('trash', t('ui.delete'), ['size' => 's', 'attrs' => ['data-o-remove' => true]]) ?>
        </div>
      </template>
      <div><?= Ui::btn(t('menu.add_option'), ['style' => 'ghost', 'size' => 's', 'icon' => 'plus', 'attrs' => ['data-o-add' => true]]) ?></div>
      <div class="sheet__actions">
        <?= Ui::ibtn('trash', t('ui.delete'), ['style' => 'secondary', 'size' => 'l', 'attrs' => ['data-group-delete' => true]]) ?>
        <?= Ui::btn(t('ui.cancel'), ['style' => 'secondary', 'size' => 'l', 'attrs' => ['data-close' => true]]) ?>
        <?= Ui::btn(t('ui.save'), ['size' => 'l', 'icon' => 'check', 'type' => 'submit']) ?>
      </div>
    </div>
  </form>
</div>
