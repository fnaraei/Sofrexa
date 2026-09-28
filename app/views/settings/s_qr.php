<?php
/** QR table ordering — built from the SE section card and Toggle rows (no dedicated Figma frame). */
use Sofrexa\Core\Settings;
use Sofrexa\View\Ui;

$on = static fn(string $k): bool => (bool) Settings::get($k);
?>
<section class="section">
  <div class="section__head"><h2 class="section__title"><?= e(t('set.qr.title')) ?></h2><p class="section__sub"><?= e(t('set.qr.sub')) ?></p></div>
  <div class="tcard">
    <?= Ui::toggleRow('s[qr.enabled]', t('set.qr.enabled'), t('set.qr.enabled_sub'), $on('qr.enabled')) ?>
    <?= Ui::toggleRow('s[qr.require_first_approval]', t('set.qr.approval'), t('set.qr.approval_sub'), $on('qr.require_first_approval')) ?>
    <?= Ui::toggleRow('s[qr.call_waiter]', t('set.qr.call'), null, $on('qr.call_waiter')) ?>
    <?= Ui::toggleRow('s[qr.request_bill]', t('set.qr.bill'), null, $on('qr.request_bill')) ?>
  </div>
  <div class="note"><?= icon('info', 20) ?><span><?= e(t('set.qr.offline')) ?></span></div>
</section>
