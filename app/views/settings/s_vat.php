<?php
/** VAT rates: defaults and a rate per menu category (SE section card + editable cells, as in M7). @var array $data */
use Sofrexa\Core\Settings;
use Sofrexa\View\Ui;
?>
<section class="section">
  <div class="section__head"><h2 class="section__title"><?= e(t('set.vat.title')) ?></h2><p class="section__sub"><?= e(t('set.vat.sub')) ?></p></div>
  <div class="frow">
    <?= Ui::field('s[vat.food]', ['label' => t('set.vat.food'), 'icon' => 'utensils', 'value' => num((float) Settings::get('vat.food')), 'suffix' => '%', 'attrs' => ['inputmode' => 'decimal']]) ?>
    <?= Ui::field('s[vat.drinks]', ['label' => t('set.vat.drinks'), 'icon' => 'coffee', 'value' => num((float) Settings::get('vat.drinks')), 'suffix' => '%', 'attrs' => ['inputmode' => 'decimal']]) ?>
    <?= Ui::field('s[vat.alcohol]', ['label' => t('set.vat.alcohol'), 'icon' => 'drink', 'value' => num((float) Settings::get('vat.alcohol')), 'suffix' => '%', 'attrs' => ['inputmode' => 'decimal']]) ?>
  </div>
  <?php if ($data['categories']): ?>
  <div class="overline"><?= e(t('set.vat.categories')) ?></div>
  <div class="vatlist">
    <?php foreach ($data['categories'] as $c): ?>
      <label class="trow"><span class="trow__text"><span class="trow__title"><?= e(tn($c['names'])) ?></span></span>
        <span class="editable editable--s"><input type="text" inputmode="decimal" name="cat[<?= e($c['id']) ?>]" value="<?= e(num((float) $c['vat_rate'])) ?>"><span class="c-muted">%</span></span></label>
    <?php endforeach ?>
  </div>
  <?php endif ?>
</section>
