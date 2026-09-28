<?php
/**
 * Cash moves — Figma C10 (85:1082): drawer stats, the shift's moves (append-only, reverse entries), foreign cash box.
 * @var array $s @var array $sum @var array $moves @var ?array $open @var array $cashSales @var array $reversed @var array $rates @var array $currencies
 */
use Sofrexa\Core\{I18n, Money};
use Sofrexa\View\Ui;

$name = first_name($s['user_name'] ?? user()['name']);
$sub = t('moves.sub', ['name' => $name, 'time' => digits(date('H:i', intdiv((int) $s['opened_at'], 1000))), 'device' => $s['device'] ?: config('device_name', 'Kasa')]);
$appSub = $name . ' · ' . digits(date('H:i', intdiv((int) $s['opened_at'], 1000)));
$back = '/cashier';
$headActions = (can('cash.nosale') ? Ui::btn(t('moves.drawer'), ['style' => 'ghost', 'icon' => 'lock', 'attrs' => ['data-post' => '/cashier/nosale']]) : '')
    . Ui::btn(t('moves.out'), ['style' => 'secondary', 'icon' => 'arrow-up', 'attrs' => ['data-load-sheet' => '/cashier/moves/sheet?kind=out']])
    . Ui::btn(t('moves.in'), ['style' => 'accent', 'icon' => 'arrow-down', 'attrs' => ['data-load-sheet' => '/cashier/moves/sheet?kind=in']]);
$appActions = [
    Ui::ibtn('arrow-up', t('moves.out'), ['class' => 'appbar__act', 'attrs' => ['data-load-sheet' => '/cashier/moves/sheet?kind=out']]),
    Ui::ibtn('arrow-down', t('moves.in'), ['class' => 'appbar__act', 'attrs' => ['data-load-sheet' => '/cashier/moves/sheet?kind=in']]),
];
$in = array_filter($moves, static fn(array $m): bool => $m['kind'] === 'in');
$out = array_filter($moves, static fn(array $m): bool => $m['kind'] === 'out');
$inSum = array_sum(array_map(static fn(array $m): int => (int) $m['amount'], $in));
$outSum = array_sum(array_map(static fn(array $m): int => (int) $m['amount'], $out));
$kinds = ['in' => 'success', 'out' => 'danger', 'open' => 'info', 'nosale' => 'warning', 'reverse' => 'neutral'];
$fxRows = [];
$fxTotal = 0;
foreach ($sum['cash'] as $cur => $v) {
    if ($cur !== 'TRY' && isset($rates[$cur]) && (abs((float) $v) > 0.001 || $rates[$cur]['accepted'])) {
        $try = $rates[$cur]['rate'] ? Money::toTry((float) $v, (float) $rates[$cur]['rate']) : 0;
        $fxRows[] = [$cur, (float) $v, $try];
        $fxTotal += $try;
    }
}
$lastRate = null;
foreach ($rates as $r) {
    if ($r['at'] && (!$lastRate || $r['at'] > $lastRate['at'])) {
        $lastRate = $r;
    }
}
?>
<div class="stats stats--4">
  <?= Ui::stat(t('moves.k_cash'), money((int) $sum['cash']['TRY']), ['brand' => true, 'delta' => t('moves.k_cash_d')]) ?>
  <?= Ui::stat(t('moves.k_open'), money((int) $s['opening_cash']), ['delta' => $open ? digits(date('H:i', intdiv((int) $open['at'], 1000))) . ' · ' . first_name($open['name'] ?? '') : '']) ?>
  <?= Ui::stat(t('moves.k_sales'), money((int) $cashSales['amount']), ['delta' => t('moves.k_sales_d', ['n' => digits((int) $cashSales['n'])])]) ?>
  <?= Ui::stat(t('moves.k_inout'), money($inSum, true) . ' / ' . money($outSum), ['delta' => t('moves.k_inout_d', ['i' => digits(count($in)), 'o' => digits(count($out))])]) ?>
</div>

<div class="movewrap">
  <section class="card card--pad0 grow movecard">
    <div class="movecard__head">
      <h2 class="t-heading-s grow"><?= e(t('moves.list')) ?></h2>
      <div class="chips" data-move-filter>
        <?= Ui::chip(t('ui.all'), true, null, ['data-kind' => '']) ?><?= Ui::chip(t('moves.f_in'), false, null, ['data-kind' => 'in']) ?><?= Ui::chip(t('moves.f_out'), false, null, ['data-kind' => 'out']) ?><?= Ui::chip(t('moves.f_nosale'), false, null, ['data-kind' => 'nosale']) ?>
      </div>
    </div>
    <div class="mrow mrow--head only-desktop"><span><?= e(t('moves.c_time')) ?></span><span><?= e(t('moves.c_kind')) ?></span><span><?= e(t('moves.c_desc')) ?></span><span><?= e(t('moves.c_by')) ?></span><span class="right"><?= e(t('moves.c_amount')) ?></span></div>
    <?php if (!$moves): ?><div class="empty"><?= e(t('moves.empty')) ?></div><?php endif ?>
    <?php foreach ($moves as $m):
        $desc = match ($m['kind']) {
            'nosale' => t('moves.nosale_desc'),
            'open' => t('moves.opening_desc'),
            default => implode(' · ', array_filter([(string) $m['reason'], (string) $m['note']])),
        };
        $amount = $m['kind'] === 'nosale' ? '—' : ($m['currency'] === 'TRY' ? money((int) $m['amount'], true) : ((float) $m['amount_fx'] < 0 ? '−' : '+') . Money::symbol($m['currency']) . digits(I18n::num(abs((float) $m['amount_fx']), 2)));
        $tone = $m['kind'] === 'nosale' ? 'c-secondary' : ((int) $m['amount'] < 0 ? 'c-danger' : 'c-success'); ?>
      <div class="mrow" data-kind="<?= e($m['kind']) ?>">
        <span class="t-label-m num"><?= e(digits(date('H:i', intdiv((int) $m['at'], 1000)))) ?></span>
        <span><?= Ui::badge(t('moves.k.' . $m['kind']), $kinds[$m['kind']] ?? 'neutral', true) ?></span>
        <span class="t-body-m c-secondary mrow__desc"><?= e($desc) ?><?php if ($m['photo']): ?> · <a class="c-accent" href="/cashier/moves/<?= e($m['id']) ?>/photo" target="_blank" rel="noopener"><?= e(t('moves.photo')) ?></a><?php endif ?></span>
        <span class="t-body-m c-secondary ellipsis"><?= e(first_name($m['user_name'] ?? '')) ?></span>
        <span class="mrow__amount right">
          <span class="<?= $m['kind'] === 'nosale' ? 't-body-m' : 't-label-m' ?> num <?= $tone ?>"><?= e($amount) ?></span>
          <?php if (in_array($m['kind'], ['in', 'out'], true) && !isset($reversed[$m['id']])): ?><?= Ui::ibtn('undo', t('moves.reverse'), ['size' => 's', 'attrs' => ['data-post' => '/cashier/moves/' . $m['id'] . '/reverse', 'data-confirm' => t('moves.reverse_confirm')]]) ?><?php endif ?>
        </span>
      </div>
    <?php endforeach ?>
  </section>
  <div class="moveside">
    <section class="card fxcard">
      <h2 class="t-heading-s"><?= e(t('moves.fx')) ?></h2>
      <?php foreach ($fxRows as [$cur, $v, $try]): ?>
        <div class="kv"><span class="t-label-l c-primary"><?= e(Money::symbol($cur) . ' ' . digits(I18n::num($v, fmod($v, 1.0) > 0.001 ? 2 : 0))) ?></span><span class="t-body-m c-secondary num">≈ <?= e(money($try)) ?></span></div>
      <?php endforeach ?>
      <div class="kv kv--total"><span class="t-body-m c-secondary"><?= e(t('moves.fx_total')) ?></span><span class="fxcard__total num">≈ <?= e(money($fxTotal)) ?></span></div>
      <?php if ($lastRate): ?><p class="t-body-s c-muted"><?= e(t('moves.fx_rates', ['when' => mb_strtolower(when_label((int) $lastRate['at']), 'UTF-8'), 'who' => first_name($lastRate['by'] ?? '')])) ?></p><?php endif ?>
    </section>
    <div class="rulebox"><?= icon('lock', 20) ?><span class="t-body-s c-secondary"><?= e(t('moves.rule')) ?></span></div>
  </div>
</div>
