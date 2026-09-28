<?php
/**
 * Order tracking — Figma O4 (48:302): StatusHero, StatusTimeline and the payment line; "Restoranı ara" at the bottom.
 * Desktop (not in Figma): the same column under the SiteHeader. The live part refreshes itself (?partial=1).
 * @var array $o @var array $t @var array $a
 */
use Sofrexa\Core\Settings;

$d = $o['delivery'];
$title = t('on.order_no', ['no' => sprintf('%04d', (int) $o['no'])]);
$scripts = ['js/online.js'];
$bodyClass = 'online-track';
$district = trim(explode(',', (string) ($d['address'] ?? ''))[0]);
$sub = $t['pickup'] ? t('on.type_pickup') : trim(t('on.type_delivery') . ($district !== '' ? ' · ' . $district : ''));
$phone = (string) Settings::get('profile.phone', '');
?>
<?= \Sofrexa\Core\View::partial('online/_site', ['acc' => $a]) ?>
<?= \Sofrexa\Core\View::partial('online/_sub', ['back' => '/online', 'title' => t('on.order_no', ['no' => digits(sprintf('%04d', (int) $o['no']))]), 'sub' => $sub]) ?>
<div class="otrack">
  <h1 class="t-display-m only-desktop"><?= e(t('on.order_no', ['no' => digits(sprintf('%04d', (int) $o['no']))]) . ' · ' . $sub) ?></h1>
  <main class="gbody gbody--status" data-track="/online/siparis/<?= e($o['id']) ?>?partial=1">
    <?= \Sofrexa\Core\View::partial('online/_track', ['o' => $o, 't' => $t]) ?>
  </main>
  <?php if ($phone !== ''): ?>
    <div class="gbar"><a class="btn btn--secondary btn--l btn--block" href="tel:<?= e(preg_replace('/[^\d+]/', '', $phone)) ?>"><?= icon('phone', 20) ?><span><?= e(t('on.call')) ?></span></a></div>
  <?php endif ?>
</div>
