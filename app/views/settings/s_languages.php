<?php
/** Languages for the staff app and for customer pages (SE section card with toggles). */
use Sofrexa\Core\I18n;
use Sofrexa\Core\Settings;
use Sofrexa\View\Ui;
?>
<section class="section">
  <div class="section__head"><h2 class="section__title"><?= e(t('set.lang.title')) ?></h2><p class="section__sub"><?= e(t('set.lang.sub')) ?></p></div>
  <?php foreach (['staff' => 'lang.staff', 'customer' => 'lang.customer'] as $k => $key): $on = (array) Settings::get($key); ?>
    <div class="overline"><?= e(t('set.lang.' . $k)) ?></div>
    <div class="tcard">
      <?php foreach (array_keys(I18n::LANGS) as $l): ?>
        <?= Ui::toggleRow('s[' . $key . '][]', t('lang.' . $l), I18n::LANGS[$l], in_array($l, $on, true), ['value' => $l]) ?>
      <?php endforeach ?>
    </div>
  <?php endforeach ?>
</section>
