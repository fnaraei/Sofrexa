<?php
/**
 * QR ordering paused — Figma Q4 (47:185): the web copy has not heard from the restaurant's till for a while.
 * "Garson çağır" still records the call (it reaches the staff when the line is back); the menu stays readable.
 * @var array $table @var string $code
 */
use Sofrexa\Core\I18n;
use Sofrexa\Modules\QrOrder\GuestController;
use Sofrexa\Modules\QrOrder\QrOrders;
use Sofrexa\View\Ui;

$title = QrOrders::label($table);
$scripts = ['js/guest.js'];
$bodyClass = 'guest-blocked';
$foot = [];
foreach (array_keys(GuestController::languages()) as $l) {
    if ($l !== I18n::lang()) {
        $foot[] = '<bdi lang="' . e($l) . '"' . ($l === 'fa' ? ' dir="rtl"' : '') . '>' . e(I18n::t('qr.off_foot', [], $l)) . '</bdi>';
    }
}
$base = '/q/' . rawurlencode($code);
?>
<div class="gapp">
  <section class="gview">
    <?= \Sofrexa\Core\View::partial('qr/_head', ['table' => $table]) ?>
    <main class="gblock">
      <span class="gblock__disc"><?= icon('wifi-off', 44) ?></span>
      <h1 class="t-display-m"><?= e(t('qr.off_title')) ?></h1>
      <p class="t-body-m c-secondary"><?= e(t('qr.off_text')) ?></p>
      <?php if (\Sofrexa\Core\Settings::get('qr.call_waiter', true)): ?><?= Ui::btn(t('qr.call'), ['style' => 'accent', 'size' => 'l', 'block' => true, 'icon' => 'hand', 'attrs' => ['data-post' => $base . '/call']]) ?><?php endif ?>
      <?= Ui::btn(t('qr.view_menu'), ['style' => 'secondary', 'size' => 'l', 'block' => true, 'icon' => 'utensils', 'href' => $base . '?menu=1']) ?>
      <p class="t-body-s c-muted gblock__foot" dir="ltr"><?= implode(' · ', $foot) ?></p>
    </main>
  </section>
</div>
