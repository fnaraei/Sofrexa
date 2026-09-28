<?php
/** Online ordering — Figma SE1 card 1 (45:2, desktop) and SE3 (45:490, phone). */
use Sofrexa\Core\Settings;
use Sofrexa\View\Ui;

$on = static fn(string $k): bool => (bool) Settings::get($k);
$fee = (int) Settings::get('online.delivery_fee', 0);
?>
<section class="section section--m-plain">
  <div class="section__head"><h2 class="section__title"><?= e(t('set.online.title')) ?></h2><p class="section__sub"><?= e(t('set.online.sub')) ?></p></div>
  <div class="tcard tcard--m">
    <div class="only-desktop"><?= Ui::toggleRow('s[online.enabled]', t('set.online.enabled'), t('set.online.enabled_sub'), $on('online.enabled'), ['data-mirror' => 'oen']) ?></div>
    <div class="only-mobile"><?= Ui::toggleRow('m_online_enabled', t('set.online.enabled'), null, $on('online.enabled'), ['data-mirror' => 'oen']) ?></div>
    <?= Ui::toggleRow('s[online.delivery]', t('set.online.delivery'), $fee ? money($fee) : t('set.online.free'), $on('online.delivery')) ?>
    <?= Ui::toggleRow('s[online.pickup]', t('set.online.pickup'), null, $on('online.pickup')) ?>
  </div>
  <div class="frow">
    <?= Ui::field('s[online.min_order]', ['label' => t('set.online.min'), 'icon' => 'tag', 'value' => money((int) Settings::get('online.min_order')), 'attrs' => ['inputmode' => 'decimal']]) ?>
    <div class="only-desktop"><?= Ui::field('s[online.delivery_fee]', ['label' => t('set.online.fee'), 'icon' => 'bike', 'value' => $fee ? money($fee) : '', 'placeholder' => t('set.online.free'), 'attrs' => ['inputmode' => 'decimal']]) ?></div>
    <div class="only-desktop"><?= Ui::field('s[online.eta_minutes]', ['label' => t('set.online.eta'), 'icon' => 'timer', 'value' => (string) Settings::get('online.eta_minutes'), 'suffix' => 'dk']) ?></div>
  </div>
  <div class="frow">
    <?= Ui::field('s[online.hours]', ['label' => t('set.online.hours'), 'icon' => 'clock', 'value' => (string) Settings::get('online.hours')]) ?>
    <?= Ui::field('s[online.area]', ['label' => t('set.online.area'), 'icon' => 'map-pin', 'value' => (string) Settings::get('online.area')]) ?>
  </div>
  <div class="only-desktop"><?= Ui::toggleRow('cod', t('set.online.cod'), t('set.online.cod_sub'), true, ['disabled' => true]) ?></div>
  <div class="only-mobile"><?= Ui::banner(t('set.online.cod_title'), t('set.online.cod_text'), 'info', 'info') ?></div>
</section>
