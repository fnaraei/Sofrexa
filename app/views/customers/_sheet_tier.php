<?php
/**
 * Customer tier — Figma CU7 (103:743): who, the tiers as option tiles (discount · earn), automatic update,
 * note (goes to the activity log). @var array $c @var ?array $tier @var array $tiers @var int $spend @var int $points
 */
use Sofrexa\Core\I18n;
use Sofrexa\View\Ui;

$pct = static fn(float $p): string => '%' . digits(I18n::numAuto($p));
?>
<div class="scrim" data-dyn hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead(t('cust.tier_t')) ?>
    <form class="sheet__body" method="post" action="/customers/<?= e($c['id']) ?>/tier" data-ajax data-toast="off">
      <?= csrf_field() ?>
      <?= Ui::who($c['name'], t('cust.tier_who', ['m' => digits((int) \Sofrexa\Core\Settings::get('loyalty.tier_window_months', 12)), 'amount' => money($spend), 'n' => digits(I18n::num($points))])) ?>
      <span class="overline"><?= e(t('cust.tier_level')) ?></span>
      <div class="opts opts--3 opts--star">
        <?php foreach ($tiers as $t): ?>
          <?= Ui::opt($t['name'], t('cust.tier_opt', ['d' => $pct((float) $t['discount_pct']), 'e' => $pct((float) $t['earn_pct'])]), 'star', ($tier['id'] ?? '') === $t['id'], 'tier_id', $t['id']) ?>
        <?php endforeach ?>
      </div>
      <div class="trow--2"><?= Ui::toggleRow('auto', t('cust.tier_autoupd'), t('cust.tier_autoupd_s'), !(int) $c['tier_manual']) ?></div>
      <?= Ui::field('note', ['label' => t('cust.f_note'), 'icon' => 'note', 'value' => (string) ($c['tier_note'] ?? '')]) ?>
      <p class="t-body-s c-muted"><?= e(t('cust.tier_logged')) ?></p>
      <div class="sheet__actions">
        <?= Ui::btn(t('ui.cancel'), ['style' => 'secondary', 'size' => 'l', 'attrs' => ['data-close' => true]]) ?>
        <?= Ui::btn(t('ui.save'), ['size' => 'l', 'icon' => 'check', 'type' => 'submit']) ?>
      </div>
    </form>
  </div>
</div>
