<?php
/**
 * Use loyalty points — Figma C12 (85:1758): customer with tier, usable points, how much (all / half / keep),
 * the summary (bill, point discount, to pay, points this bill earns), apply.
 * @var array $o @var array $customer @var ?array $tier @var int $pv @var int $used @var int $base @var int $avail @var int $usable @var int $min @var float $earnPct
 */
use Sofrexa\Core\I18n;
use Sofrexa\View\Ui;

$n = static fn(int $v): string => digits(I18n::num($v));
$half = intdiv($usable, 2);
$mode = $used <= 0 ? ($usable > 0 ? 'all' : 'none') : ($used === $half && $half !== $usable ? 'half' : 'all');
$cfg = ['base' => $base, 'pv' => $pv, 'earn' => $earnPct, 'lang' => I18n::lang(),
    'str' => ['apply' => t('pay.pts_apply', ['amount' => '{amount}']), 'keep' => t('pay.pts_keep_btn'), 'points' => t('loy.points_n', ['n' => '{n}'])]];
$tile = static function (string $value, string $label, string $sub, string $icon, int $points, bool $on, bool $disabled = false) use ($pv): string {
    return '<label class="opt' . ($disabled ? ' is-disabled' : '') . '"><input type="radio" name="mode" value="' . e($value) . '" data-points="' . $points . '" data-amount="' . ($points * $pv) . '"'
        . ($on ? ' checked' : '') . ($disabled ? ' disabled' : '') . '>' . icon($icon, 24) . '<span class="opt__label">' . e($label) . '</span><span class="opt__sub">' . e($sub) . '</span></label>';
};
?>
<div class="scrim" data-dyn hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead(t('pay.pts_t')) ?>
    <form class="sheet__body" method="post" action="/cashier/pay/<?= e($o['id']) ?>/points" data-ajax data-toast="off" data-redeem='<?= e(json_encode($cfg, JSON_UNESCAPED_UNICODE)) ?>'>
      <?= csrf_field() ?>
      <div class="row gap-10">
        <span class="grow"><?= Ui::who($customer['name'], (string) $customer['phone']) ?></span>
        <?php if ($tier): ?><?= Ui::badge($tier['name'] . ' · %' . digits(I18n::numAuto((float) $tier['earn_pct'])), $tier['tone']) ?><?php endif ?>
      </div>
      <div class="brandcard">
        <span class="overline"><?= e(t('pay.pts_avail')) ?></span>
        <span class="t-number-l num"><?= e(t('loy.points_n', ['n' => $n($avail)])) ?></span>
        <span class="t-body-s"><?= e($usable > 0 ? t('pay.pts_worth', ['amount' => money($usable * $pv)]) : ($avail < $min ? t('pay.pts_min', ['n' => $n($min)]) : t('pay.pts_none'))) ?></span>
      </div>
      <span class="overline"><?= e(t('pay.pts_how')) ?></span>
      <div class="opts opts--3">
        <?= $tile('all', t('pay.pts_all'), money($usable * $pv), 'sparkles', $usable, $mode === 'all', $usable <= 0) ?>
        <?= $tile('half', t('pay.pts_half'), money($half * $pv), 'percent', $half, $mode === 'half', $half <= 0) ?>
        <?= $tile('none', t('pay.pts_keep'), t('pay.pts_keep_s'), 'close', 0, $mode === 'none') ?>
      </div>
      <div class="card kvcard">
        <div class="kv"><span><?= e(t('pay.pts_bill')) ?></span><span class="t-label-l num"><?= e(money($base)) ?></span></div>
        <div class="kv"><span><?= e(t('pay.pts_disc')) ?></span><span class="t-label-l num c-success" data-r-disc></span></div>
        <div class="kv kv--total"><span class="t-label-l"><?= e(t('pay.pts_pay')) ?></span><span class="t-heading-m num" data-r-pay></span></div>
        <div class="kv"><span class="t-body-s c-muted"><?= e(t('pay.pts_earn')) ?></span><span class="t-label-m c-accent num" data-r-earn></span></div>
      </div>
      <div class="sheet__actions">
        <?= Ui::btn(t('ui.cancel'), ['style' => 'secondary', 'size' => 'l', 'attrs' => ['data-close' => true]]) ?>
        <?= Ui::btn(t('pay.pts_keep_btn'), ['style' => 'accent', 'size' => 'l', 'icon' => 'check', 'type' => 'submit', 'attrs' => ['data-r-btn' => true]]) ?>
      </div>
    </form>
  </div>
</div>
