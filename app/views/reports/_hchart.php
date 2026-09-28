<?php
/**
 * Hourly sales chart — NC1 (R1 phone, R2 desktop): brand bars, the peak hour in gold, hours underneath;
 * no grid, axis or labels on the bars (the amount is in the tooltip). @var array $hours hour => kuruş @var string $size m|l
 */
$max = max(1, ...array_values($hours ?: [0]));
$peak = $hours && max($hours) > 0 ? array_search(max($hours), $hours, true) : null;
?>
<div class="hchart hchart--<?= e($size ?? 'l') ?>" role="img" aria-label="<?= e(t('rep.hourly')) ?>">
  <?php foreach ($hours as $h => $v): ?>
    <div class="hchart__col<?= $h === $peak ? ' is-peak' : '' ?>" title="<?= e(digits(sprintf('%02d:00', $h)) . ' · ' . money($v)) ?>">
      <span class="hchart__bar" style="height:calc((100% - 20px) * <?= round(0.96 * $v / $max, 4) ?>)"></span>
      <span class="hchart__x"><?= e(digits((string) $h)) ?></span>
    </div>
  <?php endforeach ?>
</div>
