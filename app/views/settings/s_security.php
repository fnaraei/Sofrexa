<?php
/** Security: PIN networks, idle sign-out, Turnstile status (SE section card). @var array $data */
use Sofrexa\Core\Settings;
use Sofrexa\View\Ui;
?>
<section class="section">
  <div class="section__head"><h2 class="section__title"><?= e(t('set.sec.title')) ?></h2><p class="section__sub"><?= e(t('set.sec.sub')) ?></p></div>
  <?= Ui::field('s[security.pin_networks]', ['label' => t('set.sec.networks'), 'icon' => 'wifi-off', 'value' => implode(', ', (array) Settings::get('security.pin_networks')), 'placeholder' => '192.168.1.0/24', 'help' => t('set.sec.networks_help') . ' ' . t('set.sec.your_ip', ['ip' => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '?'])]) ?>
  <div class="frow">
    <?= Ui::field('s[security.idle_lock_minutes]', ['label' => t('set.sec.idle'), 'icon' => 'timer', 'type' => 'number', 'value' => (string) (int) Settings::get('security.idle_lock_minutes'), 'help' => t('set.sec.idle_help'), 'attrs' => ['min' => 0, 'max' => 600]]) ?>
    <div class="field"><span class="field__label"><?= e(t('set.sec.turnstile')) ?></span><div class="row" style="height:48px"><?= $data['turnstile'] ? Ui::badge(t('set.sec.turnstile_on'), 'success', true) : Ui::badge(t('set.sec.turnstile_off'), 'warning', true) ?></div></div>
  </div>
</section>
