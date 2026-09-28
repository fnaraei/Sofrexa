<?php
/** Live part of O4: StatusHero, StatusTimeline (no connectors), InfoRow with the payment. @var array $o @var array $t */
$d = $o['delivery'];
[$icon, $head, $detail] = $t['hero'];
$cash = ($d['pay_hint'] ?? 'cash') !== 'card';
$pay = t($t['pickup'] ? ($cash ? 'on.pay_cash_shop' : 'on.pay_card_shop') : ($cash ? 'on.pay_cash' : 'on.pay_card')) . ' · ' . money((int) $o['total'])
    . ($cash && !empty($d['cash_given']) ? ' (' . t('on.change_ready', ['amount' => money((int) $d['cash_given'])]) . ')' : '');
?>
<section class="ohero<?= $t['stage'] === 'cancelled' ? ' ohero--off' : '' ?>" aria-live="polite">
  <?= icon($icon, 44) ?>
  <h2 class="t-display-m"><?= e($head) ?></h2>
  <?php if ($detail !== ''): ?><p class="t-body-m c-secondary"><?= e(digits($detail)) ?></p><?php endif ?>
</section>
<section class="otl">
  <?php foreach ($t['steps'] as [$state, $ic, $label, $at]): ?>
    <div class="otl__step otl__step--<?= $state ?>">
      <span class="otl__dot"><?= icon($ic, 16) ?></span>
      <span class="grow <?= $state === 'current' ? 't-label-l' : 't-body-m' ?>"><?= e($label) ?></span>
      <?php if ($at): ?><span class="t-label-s c-muted num"><?= e(digits(date('H:i', intdiv((int) $at, 1000)))) ?></span><?php endif ?>
    </div>
  <?php endforeach ?>
</section>
<div class="oinfo"><?= icon($cash ? 'cash' : 'credit-card', 20) ?><span class="grow t-body-m c-secondary"><?= e($pay) ?></span></div>
