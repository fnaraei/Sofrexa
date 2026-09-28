<?php
/**
 * Exchange rates — Figma C7 (27:832): one card per accepted currency, entered by hand every morning.
 * @var array $rates @var string $backTo
 */
use Sofrexa\Core\{Db, Money};
use Sofrexa\View\Ui;

$sub = t('rates.sub');
$back = $backTo;
$history = Db::rows('SELECT f.currency, f.rate, f.at, u.name FROM fx_rates f LEFT JOIN users u ON u.id = f.user_id ORDER BY f.at DESC LIMIT 20');
$appActions = [Ui::ibtn('history', t('rates.history'), ['class' => 'appbar__act', 'attrs' => ['data-sheet' => 'rates-history']])];
$headActions = Ui::btn(t('rates.history'), ['style' => 'secondary', 'icon' => 'history', 'attrs' => ['data-sheet' => 'rates-history']])
    . Ui::btn(t('rates.save'), ['icon' => 'check', 'type' => 'submit', 'attrs' => ['form' => 'rates-form']]);
$bottom = Ui::btn(t('rates.save'), ['size' => 'l', 'icon' => 'check', 'type' => 'submit', 'attrs' => ['form' => 'rates-form']]);
$today = date('Y-m-d');
$stale = [];
$accepted = array_filter($rates, static fn(array $r): bool => $r['accepted']);
$when = static function (?int $at): string {
    if (!$at) {
        return t('rates.never');
    }
    $time = digits(date('H:i', intdiv($at, 1000)));
    $d = date('Y-m-d', intdiv($at, 1000));
    return $d === date('Y-m-d') ? t('rates.today', ['time' => $time]) : ($d === date('Y-m-d', strtotime('-1 day')) ? t('rates.yesterday', ['time' => $time]) : digits(date('d.m', intdiv($at, 1000))) . ' ' . $time);
};
?>
<form class="rates" id="rates-form" method="post" action="/cashier/rates" data-ajax>
  <?= csrf_field() ?>
  <input type="hidden" name="back" value="<?= e($backTo) ?>">
  <?= Ui::banner(t('rates.banner_t'), t('rates.banner'), 'info', 'info') ?>
  <?php if (!$accepted): ?><div class="empty"><?= e(t('rates.not_accepted')) ?></div><?php endif ?>
  <?php foreach ($accepted as $c => $r):
      $isStale = $r['at'] && date('Y-m-d', intdiv((int) $r['at'], 1000)) !== $today;
      if ($isStale) {
          $stale[] = t('rates.name.' . $c);
      } ?>
    <label class="ratecard">
      <span class="ratecard__sym"><?= e(Money::symbol($c)) ?></span>
      <span class="grow col gap-2"><span class="t-label-m"><?= e(t('rates.' . $c)) ?></span><span class="t-body-s <?= $isStale || !$r['rate'] ? 'c-warning' : 'c-muted' ?>"><?= e(t('rates.meta', ['when' => $when($r['at'] ? (int) $r['at'] : null), 'who' => first_name($r['by'] ?? '—')])) ?></span></span>
      <span class="ratecard__box"><input name="rate_<?= e($c) ?>" inputmode="decimal" autocomplete="off" value="<?= $r['rate'] ? e(number_format($r['rate'], 2, ',', '')) : '' ?>" placeholder="0,00"><span class="t-label-m c-muted">₺</span></span>
    </label>
  <?php endforeach ?>
  <?php foreach ($stale as $name): ?><p class="t-body-s c-warning"><?= e(t('rates.stale', ['name' => $name])) ?></p><?php endforeach ?>
</form>

<div class="scrim" id="rates-history" hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead(t('rates.history')) ?>
    <div class="sheet__body">
      <div class="list list--flush">
        <?php foreach ($history as $h): ?>
          <?= Ui::lrow(Money::symbol($h['currency']) . ' 1 = ' . digits(number_format((float) $h['rate'], 2, ',', '.')) . ' ₺', ['sub' => when_label((int) $h['at']) . ' · ' . ($h['name'] ?? '—'), 'lead' => '<span class="lrow__lead">' . icon('currency', 20) . '</span>']) ?>
        <?php endforeach ?>
        <?php if (!$history): ?><div class="empty"><?= e(t('rates.never')) ?></div><?php endif ?>
      </div>
    </div>
  </div>
</div>
