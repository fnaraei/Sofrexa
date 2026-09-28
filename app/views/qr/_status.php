<?php
/**
 * Live part of Q3: status card with the timeline (OrderStatusTimeline), the table's bill (OrderSummaryCard) and the two requests.
 * @var array $table @var string $code @var array $st @var string $avail
 */
use Sofrexa\Core\Settings;
use Sofrexa\Modules\QrOrder\QrOrders;
use Sofrexa\View\Ui;

$o = $st['order'];
$head = [
    'pending' => ['qr.st_pending', 'hand'],
    'kitchen' => ['qr.st_kitchen', 'chef-hat'],
    'ready' => ['qr.st_ready', 'utensils'],
    'served' => ['qr.st_served', 'utensils'],
    'rejected' => ['qr.st_rejected', 'alert'],
][$st['state']];
$sub = $st['state'] === 'rejected' ? t('qr.rejected_text') : t('qr.eta', ['eta' => digits($st['eta']), 'no' => digits(sprintf('%04d', (int) $o['no']))]);
$base = '/q/' . rawurlencode($code);
?>
<section class="scard<?= $st['state'] === 'rejected' ? ' scard--rejected' : '' ?>" aria-live="polite">
  <div class="scard__head">
    <div class="grow col gap-2"><h1 class="t-display-m"><?= e(t($head[0])) ?></h1><span class="t-body-s c-muted"><?= e($sub) ?></span></div>
    <?= icon($head[1], 32) ?>
  </div>
  <?php foreach ($st['steps'] as [$state, $ic, $text, $at]): ?>
    <div class="sstep sstep--<?= $state ?>">
      <span class="sstep__dot"><?= icon($state === 'done' ? 'check' : $ic, 18) ?></span>
      <span class="sstep__txt <?= $state === 'current' ? 't-label-l' : 't-body-m' ?>"><?= e($text) ?></span>
      <?php if ($state === 'current'): ?><span class="t-label-s c-muted"><?= e(t('qr.now')) ?></span>
      <?php elseif ($at): ?><span class="t-label-s c-muted num"><?= e(digits(date('H:i', intdiv((int) $at, 1000)))) ?></span><?php endif ?>
    </div>
  <?php endforeach ?>
  <?php if ($st['state'] === 'rejected'): ?>
    <div class="sstep sstep--rejected"><span class="sstep__dot"><?= icon('close', 18) ?></span><span class="sstep__txt t-body-m"><?= e(t('qr.step_rejected')) ?></span></div>
  <?php endif ?>
</section>

<?php if ($st['tab']): ?>
<section class="osum">
  <div class="overline"><?= e(t('qr.summary')) ?></div>
  <?php foreach ($st['tab'] as $l):
      $photo = \Sofrexa\Modules\Menu\Menu::photoUrl($l['image'] ?? null, 400);
      $note = QrOrders::lineNote($l); ?>
    <div class="osum__line">
      <?php if ($photo): ?><img class="osum__img" src="<?= e($photo) ?>" alt="" loading="lazy"><?php else: ?><span class="osum__img"><?= icon('utensils', 18) ?></span><?php endif ?>
      <span class="grow t-label-m"><?= e(digits(\Sofrexa\Modules\Orders\Orders::qtyText((float) $l['qty'])) . '× ' . QrOrders::lineName($l) . ($note !== '' ? ' · ' . $note : '')) ?></span>
      <span class="t-label-m c-secondary num"><?= e(money((int) round((float) $l['qty'] * ((int) $l['unit_price'] + (int) $l['mods_price'])))) ?></span>
    </div>
  <?php endforeach ?>
  <div class="osum__foot"><span class="t-label-m c-secondary"><?= e(t('qr.tab')) ?></span><span class="t-heading-m num"><?= e(money($st['total'])) ?></span></div>
</section>
<?php endif ?>

<?php
$acts = [];
if (Settings::get('qr.call_waiter', true)) {
    $acts[] = Ui::btn(t('qr.call'), ['style' => 'secondary', 'size' => 'l', 'icon' => 'hand', 'attrs' => ['data-post' => $base . '/call']]);
}
if (Settings::get('qr.request_bill', true)) {
    $acts[] = Ui::btn(t('qr.bill'), ['style' => 'secondary', 'size' => 'l', 'icon' => 'receipt', 'attrs' => ['data-post' => $base . '/bill', 'disabled' => !$st['can_bill']]]);
}
?>
<?php if ($acts): ?><div class="gbtns<?= count($acts) === 1 ? ' gbtns--one' : '' ?>"><?= implode('', $acts) ?></div><?php endif ?>
