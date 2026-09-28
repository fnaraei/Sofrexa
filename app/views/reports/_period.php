<?php
/**
 * Period segments of a report page (links), and the date range of "Özel".
 * @var array $p @var array $keys @var string $base @var string $size (desktop width class)
 */
$link = static fn(string $k): string => $base . (str_contains($base, '?') ? '&' : '?') . 'p=' . $k;
$segs = '<div class="segs' . (isset($size) ? ' segs--' . $size : '') . '">';
foreach ($keys as $k) {
    $segs .= '<a class="seg' . ($k === $p['key'] ? ' is-active' : '') . '" href="' . e($link($k)) . '">' . e(t('rep.seg.' . $k)) . '</a>';
}
echo $segs . '</div>';
if ($p['key'] === 'custom'): ?>
  <form class="rangeform" method="get" action="<?= e(strtok($base, '?')) ?>">
    <input type="hidden" name="p" value="custom">
    <label class="field field--inline"><span class="field__label"><?= e(t('rep.from')) ?></span><span class="field__box"><?= icon('calendar', 18) ?><input type="date" name="from" value="<?= e($p['first']) ?>"></span></label>
    <label class="field field--inline"><span class="field__label"><?= e(t('rep.to')) ?></span><span class="field__box"><?= icon('calendar', 18) ?><input type="date" name="to" value="<?= e($p['last']) ?>"></span></label>
    <button class="btn btn--secondary" type="submit"><?= icon('check', 18) ?><span><?= e(t('rep.apply')) ?></span></button>
  </form>
<?php endif;
