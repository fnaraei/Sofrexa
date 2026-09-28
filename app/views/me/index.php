<?php
/** Own profile: who, language (segmented control as on L1), own PIN, device name. Built from the design-system components. */
use Sofrexa\Core\I18n;
use Sofrexa\Core\Settings;
use Sofrexa\View\Ui;

$u = user();
$langs = array_intersect_key(I18n::LANGS, array_flip((array) Settings::get('lang.staff', array_keys(I18n::LANGS))));
?>
<div class="card" style="flex-direction:row;align-items:center;gap:12px">
  <?= Ui::avatar($u['name'], 'l') ?>
  <div class="col grow" style="gap:2px"><span class="t-heading-m"><?= e($u['name']) ?></span><span class="t-body-s c-muted"><?= e(t('role.' . $u['role_code'])) ?></span></div>
</div>

<section class="section">
  <h2 class="section__title"><?= e(t('me.lang')) ?></h2>
  <div class="segs" role="tablist">
    <?php foreach ($langs as $code => $label): ?>
      <button type="button" class="seg<?= $code === I18n::lang() ? ' is-active' : '' ?>" data-post="/my/lang" data-body='<?= e(json_encode(['lang' => $code])) ?>'><?= e(t('lang.' . $code)) ?></button>
    <?php endforeach ?>
  </div>
</section>

<form class="section" method="post" action="/my/pin" data-ajax data-reload>
  <h2 class="section__title"><?= e(t('me.pin')) ?></h2>
  <?= csrf_field() ?>
  <?= Ui::field('current', ['label' => t('me.pin_current'), 'icon' => 'lock', 'type' => 'password', 'autocomplete' => 'current-password', 'attrs' => ['inputmode' => 'numeric', 'maxlength' => 4, 'required' => true, 'pattern' => '\d{4}']]) ?>
  <div class="frow">
    <?= Ui::field('new', ['label' => t('me.pin_new'), 'icon' => 'key', 'type' => 'password', 'autocomplete' => 'new-password', 'attrs' => ['inputmode' => 'numeric', 'maxlength' => 4, 'required' => true, 'pattern' => '\d{4}']]) ?>
    <?= Ui::field('repeat', ['label' => t('me.pin_repeat'), 'icon' => 'key', 'type' => 'password', 'autocomplete' => 'new-password', 'attrs' => ['inputmode' => 'numeric', 'maxlength' => 4, 'required' => true, 'pattern' => '\d{4}']]) ?>
  </div>
  <div><?= Ui::btn(t('ui.save'), ['type' => 'submit', 'icon' => 'check']) ?></div>
</form>

<form class="section" method="post" action="/my/device" data-ajax>
  <?= csrf_field() ?>
  <?= Ui::field('device', ['label' => t('me.device'), 'icon' => 'monitor', 'value' => $_COOKIE['sofrexa_device'] ?? '', 'help' => t('me.device_help'), 'attrs' => ['maxlength' => 40]]) ?>
  <div><?= Ui::btn(t('ui.save'), ['type' => 'submit', 'icon' => 'check', 'style' => 'secondary']) ?></div>
</form>

<form method="post" action="/logout"><?= csrf_field() ?><?= Ui::btn(t('ui.logout'), ['type' => 'submit', 'style' => 'secondary', 'icon' => 'logout', 'block' => true, 'size' => 'l']) ?></form>
