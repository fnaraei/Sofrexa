<?php
/**
 * Close the shift — Figma C6 (28:1008 desktop: KPIs, cash count table, summary) and C9 (29:1116 phone).
 * @var array $s the open shift @var array $d Till::shiftClose() @var array $currencies
 */
use Sofrexa\Core\{I18n, Money};
use Sofrexa\View\Ui;

$from = digits(date('H:i', intdiv((int) $s['opened_at'], 1000)));
$to = digits(date('H:i'));
$name = first_name($s['user_name'] ?? user()['name']);
$sub = t('shift.close_sub', ['date' => I18n::date((int) $s['opened_at'], 'short'), 'from' => $from, 'to' => $to, 'name' => $name]);
$appSub = t('shift.close_sub_m', ['from' => $from, 'to' => $to, 'name' => $name]);
$back = '/cashier';
$headActions = Ui::btn(t('shift.x'), ['style' => 'secondary', 'icon' => 'printer', 'attrs' => ['data-post' => '/cashier/shift/x']]);
$appActions = [Ui::ibtn('printer', t('shift.x'), ['class' => 'appbar__act', 'attrs' => ['data-post' => '/cashier/shift/x']])];
$bottom = Ui::btn(t('shift.close_btn_m'), ['size' => 'l', 'icon' => 'lock', 'type' => 'submit', 'attrs' => ['form' => 'shift-form']]);
$fmt = static fn(string $c, float $v): string => $c === 'TRY' ? money((int) $v) : Money::symbol($c) . digits(I18n::num($v, fmod($v, 1.0) > 0.001 ? 2 : 0));
$problems = [];
if ($d['open'] > 0) {
    $problems[] = t('shift.open_bills', ['n' => digits($d['open'])]);
}
if ($d['couriers_open'] > 0) {
    $problems[] = t('shift.couriers_open', ['n' => digits($d['couriers_open'])]);
}
?>
<div class="stats stats--4 only-desktop">
  <?= Ui::stat(t('shift.k_sales'), money($d['sales']), ['brand' => true]) ?>
  <?= Ui::stat(t('shift.k_cash'), money($d['cash'])) ?>
  <?= Ui::stat(t('shift.k_card'), money($d['card'])) ?>
  <?= Ui::stat(t('shift.k_void'), money($d['voids']) . ' / ' . money($d['discount'])) ?>
</div>
<div class="brandcard only-mobile">
  <div class="overline"><?= e(t('shift.k_sales')) ?></div>
  <div class="t-number-xl num c-on-brand"><?= e(money($d['sales'])) ?></div>
  <div class="t-body-s c-accent"><?= e(t('shift.brand_line', ['cash' => money($d['cash']), 'card' => money($d['card'])])) ?></div>
</div>

<form class="shiftwrap" id="shift-form" method="post" action="/cashier/shift/close" data-ajax data-shift>
  <?= csrf_field() ?>
  <section class="shiftcount">
    <div class="only-desktop col gap-2"><h2 class="t-heading-l"><?= e(t('shift.count')) ?></h2><p class="t-body-m c-muted"><?= e(t('shift.count_help')) ?></p></div>
    <div class="overline only-mobile"><?= e(t('shift.count')) ?></div>
    <div class="cnt cnt--head only-desktop"><span><?= e(t('shift.col_cur')) ?></span><span><?= e(t('shift.col_expected')) ?></span><span><?= e(t('shift.col_counted')) ?></span><span><?= e(t('shift.col_diff')) ?></span></div>
    <?php foreach ($currencies as $c):
        $exp = $c === 'TRY' ? (float) (int) ($d['expected']['TRY'] ?? 0) : (float) ($d['expected'][$c] ?? 0); ?>
      <div class="cnt" data-cur="<?= e($c) ?>" data-expected="<?= e((string) $exp) ?>">
        <span class="cnt__label"><span class="t-label-l"><?= e(Money::symbol($c) . ' ' . ($c === 'TRY' ? 'TL' : $c)) ?></span><span class="cnt__exp"><span class="only-mobile"><?= e(t('shift.expected_m', ['amount' => $fmt($c, $exp)])) ?></span><span class="only-desktop"><?= e($fmt($c, $exp)) ?></span></span></span>
        <span class="cnt__box"><input name="count_<?= e($c) ?>" inputmode="decimal" autocomplete="off" placeholder="<?= e($fmt($c, $exp)) ?>" aria-label="<?= e(t('shift.col_counted') . ' ' . $c) ?>"></span>
        <span class="cnt__diff t-label-l" data-diff></span>
      </div>
    <?php endforeach ?>
    <div data-diff-banner hidden>
      <div class="banner banner--warning" role="status"><?= icon('info', 20) ?><div class="col" style="gap:2px"><div class="banner__title" data-diff-title></div><div class="banner__text"><span class="only-desktop"><?= e(t('shift.diff')) ?></span><span class="only-mobile"><?= e(t('shift.diff_m')) ?></span></div></div></div>
    </div>
    <div data-diff-note hidden><?= Ui::field('note', ['label' => t('shift.note'), 'icon' => 'note']) ?></div>
  </section>
  <aside class="shiftsum only-desktop">
    <h2 class="t-heading-l"><?= e(t('shift.summary')) ?></h2>
    <div class="shiftsum__rows">
      <?php foreach ($d['rows'] as $k => $v): ?><div class="kv"><span><?= e(t($k)) ?></span><span class="num"><?= e(money($v)) ?></span></div><?php endforeach ?>
    </div>
    <?php if (!$problems): ?>
      <div class="okbox"><?= icon('check-circle', 20) ?><span class="t-label-m"><?= e(t('shift.ok')) ?></span></div>
    <?php else: ?>
      <div class="okbox okbox--warn"><?= icon('alert', 20) ?><span class="t-label-m"><?= e(implode(' · ', $problems)) ?></span></div>
    <?php endif ?>
    <div class="grow"></div>
    <?= Ui::btn(t('shift.close_btn'), ['type' => 'submit', 'size' => 'l', 'block' => true, 'icon' => 'lock']) ?>
  </aside>
</form>
