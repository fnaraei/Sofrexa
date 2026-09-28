<?php
/**
 * Four-language inputs behind a TR/EN/RU/FA segment (as on M2/M4). One field per language stays in the form;
 * the segment only shows one language at a time.
 * @var array $fields [[field name, label key, icon, values by language, textarea?], ...]
 */
use Sofrexa\Modules\Menu\Menu;
use Sofrexa\View\Ui;

$uid = 'lt' . substr(md5(json_encode($fields) . random_int(0, PHP_INT_MAX)), 0, 6);
?>
<div class="langtabs" data-langtabs>
  <div class="segs" role="tablist">
    <?php foreach (Menu::LANGS as $i => $l): ?>
      <button type="button" class="seg<?= $i === 0 ? ' is-active' : '' ?>" data-lang-tab="<?= $l ?>"><?= strtoupper($l) ?></button>
    <?php endforeach ?>
  </div>
  <?php foreach ($fields as [$fname, $labelKey, $icon, $values, $area]): ?>
    <?php foreach (Menu::LANGS as $i => $l): ?>
      <div class="langtabs__pane" data-lang-pane="<?= $l ?>"<?= $i === 0 ? '' : ' hidden' ?><?= $l === 'fa' ? ' dir="rtl"' : '' ?>>
        <?= Ui::field($fname . '[' . $l . ']', ['label' => t($labelKey, ['lang' => strtoupper($l)]), 'icon' => $icon, 'value' => (string) ($values[$l] ?? ''), 'textarea' => $area, 'id' => $uid . '-' . $fname . '-' . $l, 'attrs' => ['maxlength' => $area ? 1000 : 120, 'lang' => $l, 'required' => $l === 'tr' && !$area]]) ?>
      </div>
    <?php endforeach ?>
  <?php endforeach ?>
</div>
