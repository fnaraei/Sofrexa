<?php
/** Categories: list sheet (drag to reorder) and edit sheet (names in four languages, station, menu tab, VAT, visible). @var array $categories */
use Sofrexa\View\Ui;

$cjson = static fn(array $c): string => json_encode(['id' => $c['id'], 'names' => json_arr($c['names']), 'station' => $c['station'], 'section' => $c['section'] ?: 'food',
    'vat' => (float) $c['vat_rate'], 'active' => (bool) $c['active'], 'count' => (int) $c['item_count']], JSON_UNESCAPED_UNICODE);
?>
<div class="scrim" id="cat-list" hidden>
  <div class="sheet sheet--wide">
    <?= Ui::sheetHead(t('menu.cat_title')) ?>
    <div class="sheet__body">
      <div class="list list--flush" data-sortable="/menu/categories/sort">
        <?php foreach ($categories as $c): ?>
          <div class="lrow" draggable="true" data-id="<?= e($c['id']) ?>">
            <span class="lrow__lead lrow__grip" data-grip><?= icon('grip', 20) ?></span>
            <button type="button" class="lrow__mid" style="text-align:start" data-cat='<?= e($cjson($c)) ?>'>
              <span class="lrow__title ellipsis"><?= e(tn($c['names'])) ?></span>
              <span class="lrow__sub"><?= e(t('menu.station.' . $c['station']) . ' · ' . t('menu.section.' . ($c['section'] ?: 'food')) . ' · %' . num((float) $c['vat_rate'])) ?></span>
            </button>
            <?= Ui::badge(digits($c['item_count'])) ?>
          </div>
        <?php endforeach ?>
      </div>
      <div class="sheet__actions"><?= Ui::btn(t('menu.cat_new'), ['size' => 'l', 'icon' => 'plus', 'attrs' => ['data-cat-new' => true]]) ?></div>
    </div>
  </div>
</div>

<div class="scrim" id="cat-edit" hidden>
  <form class="sheet" method="post" action="/menu/categories/save" data-ajax data-reload>
    <?= Ui::sheetHead(t('menu.cat_title')) ?>
    <div class="sheet__body">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="">
      <?= \Sofrexa\Core\View::partial('menu/_langtabs', ['fields' => [['names', 'menu.cat_name', 'layers', [], false]]]) ?>
      <div class="field"><span class="field__label"><?= e(t('menu.station')) ?></span><?= Ui::segs(['kitchen' => t('menu.station.kitchen'), 'bar' => t('menu.station.bar')], 'kitchen', 'station') ?></div>
      <div class="field"><span class="field__label"><?= e(t('menu.section')) ?></span><?= Ui::segs(['food' => t('menu.section.food'), 'drinks' => t('menu.section.drinks')], 'food', 'section') ?></div>
      <?= Ui::field('vat_rate', ['label' => t('menu.vat'), 'icon' => 'percent', 'suffix' => '%', 'attrs' => ['inputmode' => 'decimal']]) ?>
      <?= Ui::toggleRow('active', t('menu.cat_active'), null, true) ?>
      <div class="sheet__actions">
        <?= Ui::ibtn('trash', t('ui.delete'), ['style' => 'secondary', 'size' => 'l', 'attrs' => ['data-cat-delete' => true]]) ?>
        <?= Ui::btn(t('ui.cancel'), ['style' => 'secondary', 'size' => 'l', 'attrs' => ['data-close' => true]]) ?>
        <?= Ui::btn(t('ui.save'), ['size' => 'l', 'icon' => 'check', 'type' => 'submit']) ?>
      </div>
    </div>
  </form>
</div>
