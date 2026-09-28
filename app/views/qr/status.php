<?php
/**
 * QR order status — Figma Q3 (47:108): the latest order of this phone with its timeline, the table's bill,
 * "Garson çağır" / "Hesap iste" and "Sipariş ekle". The live part refreshes itself (guest.js polls ?partial=1).
 * @var array $table @var string $code @var array $st @var string $avail
 */
use Sofrexa\Modules\QrOrder\QrOrders;

$title = QrOrders::label($table);
$scripts = ['js/guest.js'];
$bodyClass = 'guest-status';
?>
<div class="gapp">
  <section class="gview">
    <?= \Sofrexa\Core\View::partial('qr/_head', ['table' => $table]) ?>
    <main class="gbody gbody--status" data-status="/q/<?= e(rawurlencode($code)) ?>/status?partial=1">
      <?= \Sofrexa\Core\View::partial('qr/_status', ['table' => $table, 'code' => $code, 'st' => $st, 'avail' => $avail]) ?>
    </main>
    <?php if ($avail === 'open'): ?>
      <div class="gbar"><a class="btn btn--accent btn--l btn--block" href="/q/<?= e(rawurlencode($code)) ?>"><?= icon('plus', 20) ?><span><?= e(t('qr.add_more')) ?></span></a></div>
    <?php endif ?>
  </section>
</div>
