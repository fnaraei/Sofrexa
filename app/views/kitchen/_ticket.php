<?php
/**
 * Kitchen ticket — Figma KDS ticket card: KDS/TicketHeader (state band with timer, title, area, meta),
 * KDS/ItemRow per line (tap to mark plated), one big action button. @var array $t ticket from Kitchen::tickets()
 */
use Sofrexa\Modules\Orders\Board;
use Sofrexa\Modules\Orders\Orders;

$state = $t['state'];
$meta = array_filter($t['meta'], static fn($m): bool => (string) $m !== '');
if ($state === 'ready') {
    $meta[] = t('kds.waiting');
}
$btn = match ($state) {
    'new' => ['primary', 'zap', t('kds.start'), 'start'],
    'ready' => ['secondary', 'bell', t('kds.call'), 'call'],
    default => ['accent', 'check', t('kds.ready'), 'ready'],
};
$data = ' data-order="' . e($t['order_id']) . '" data-round="' . (int) $t['round'] . '" data-station="' . e($t['station']) . '"';
?>
<article class="kt kt--<?= e($state) ?>" data-key="<?= e($t['key']) ?>"<?= $data ?>>
  <header class="kt__head">
    <div class="kt__band"><span class="overline"><?= e(t('kds.band.' . $state)) ?></span><span class="kt__timer"><?= icon('timer', 18) ?><span class="num" data-timer="<?= (int) $t['seconds'] ?>"<?= $state === 'ready' ? ' data-frozen' : '' ?>><?= e(sprintf('%02d:%02d', intdiv($t['seconds'], 60), $t['seconds'] % 60)) ?></span></span></div>
    <div class="kt__who">
      <div class="kt__titles"><span class="kt__title"><?= e($t['title']) ?></span><?php if ($t['area'] !== ''): ?><span class="kt__area"><?= e($t['area']) ?></span><?php endif ?></div>
      <?php if ($meta): ?><div class="kt__meta"><?= icon('user', 16) ?><span><?= e(implode(' · ', $meta)) ?></span></div><?php endif ?>
    </div>
  </header>
  <?php foreach ($t['lines'] as $l):
      $done = $l['status'] === 'ready';
      $mods = implode(' · ', array_map(static fn(array $m): string => (string) $m['name'], json_arr($l['mods']))); ?>
    <button type="button" class="kitem<?= $done ? ' is-done' : '' ?>" data-kds-line="<?= e($l['id']) ?>" aria-pressed="<?= $done ? 'true' : 'false' ?>">
      <span class="kitem__qty num"><?= e(Board::qty((float) $l['qty'])) ?></span>
      <span class="kitem__col"><span class="kitem__name"><?= e($l['name']) ?></span><?php if ($mods !== ''): ?><span class="kitem__mods"><?= e($mods) ?></span><?php endif ?><?php if (!empty($l['note'])): ?><span class="kitem__note"><?= e(t('kds.note', ['text' => $l['note']])) ?></span><?php endif ?></span>
      <?php if ($done): ?><?= icon('check-circle', 28, 'kitem__check') ?><?php endif ?>
    </button>
  <?php endforeach ?>
  <div class="kt__act"><button type="button" class="btn btn--<?= $btn[0] ?> btn--l btn--block" data-kds-act="<?= $btn[3] ?>"><?= icon($btn[1], 20) ?><span><?= e($btn[2]) ?></span></button></div>
</article>
