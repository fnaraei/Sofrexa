<?php
/**
 * End of day — Figma R3 (44:599): KPIs (turnover, VAT, voids, discounts) and the three summary cards
 * (payments with the currencies at their rates, channels, till count). A picker chooses another Z report.
 * @var ?array $z @var array $shifts
 */
use Sofrexa\Core\{I18n, Money};
use Sofrexa\View\Ui;

$bodyClass = 'page-z';
if (!$z): ?>
  <div class="empty"><?= icon('file-text', 24) ?><div><?= e(t('rep.z_none')) ?></div></div>
<?php return; endif;
$s = $z['s'];
$no = digits(str_pad((string) $s['z_no'], 4, '0', STR_PAD_LEFT));
$from = intdiv((int) $s['opened_at'], 1000);
$to = intdiv((int) ($s['closed_at'] ?: \Sofrexa\Core\Clock::ms()), 1000);
$title = t('rep.z_title', ['date' => I18n::date((int) $s['opened_at'], 'full')]);
$sub = ($z['closed'] ? t('rep.z_no', ['no' => $no]) : t('rep.z_open')) . ' · ' . digits(date('H:i', $from) . '–' . date('H:i', $to)) . ' · ' . t('rep.z_by', ['name' => first_name((string) $s['name'])]);
$appTitle = t('rep.eod');
$appSub = I18n::date((int) $s['opened_at'], 'short') . ' · ' . ($z['closed'] ? t('rep.z_no', ['no' => $no]) : t('rep.z_open'));
$picker = '<label class="chip chip--select"><span>' . e($z['closed'] ? 'Z #' . $no : t('rep.z_open')) . '</span>' . icon('chevron-down', 16) . '<select aria-label="' . e(t('rep.z_pick')) . '" data-go="/reports/z?id=">';
foreach ($shifts as $sh) {
    $picker .= '<option value="' . e($sh['id']) . '"' . ($sh['id'] === $s['id'] ? ' selected' : '') . '>' . e(($sh['z_no'] ? 'Z #' . digits(str_pad((string) $sh['z_no'], 4, '0', STR_PAD_LEFT)) : t('rep.z_open')) . ' · ' . I18n::date((int) $sh['opened_at'], 'short')) . '</option>';
}
$picker .= '</select></label>';
$headActions = $picker
    . Ui::btn(t('rep.print80'), ['style' => 'secondary', 'icon' => 'printer', 'attrs' => ['data-post' => '/reports/z/' . $s['id'] . '/print']])
    . Ui::btn('PDF', ['style' => 'secondary', 'icon' => 'download', 'href' => '/reports/z/' . $s['id'] . '/pdf', 'attrs' => ['target' => '_blank']]);
$appActions = [Ui::ibtn('printer', t('rep.print80'), ['class' => 'appbar__act', 'attrs' => ['data-post' => '/reports/z/' . $s['id'] . '/print']]),
    Ui::ibtn('download', 'PDF', ['class' => 'appbar__act', 'href' => '/reports/z/' . $s['id'] . '/pdf', 'attrs' => ['target' => '_blank']])];
$rates = implode(' ' . t('rep.and') . ' ', array_map(static fn(string $r): string => '%' . digits(I18n::numAuto((float) $r)), array_keys($z['vat'])));
$ch = $z['channels'];
$exp = (int) ($z['expected']['TRY'] ?? 0);
$cnt = (int) ($z['counted']['TRY'] ?? 0);
$diff = $cnt - $exp;
$fxState = [];
foreach ($z['expected'] as $cur => $e) {
    if ($cur !== 'TRY' && (abs((float) $e) > 0.001 || isset($z['counted'][$cur]))) {
        $d = round((float) ($z['counted'][$cur] ?? 0) - (float) $e, 2);
        $fxState[] = Money::symbol($cur) . ($d == 0.0 ? '' : ' ' . ($d < 0 ? '−' : '+') . digits(I18n::num(abs($d), 2)));
    }
}
$fxAll = $fxState && !array_filter($fxState, static fn(string $x): bool => mb_strlen($x) > 1);
?>
<div class="only-mobile"><?= $picker ?></div>
<div class="stats stats--4">
  <?= Ui::stat(t('rep.z_turnover'), money($z['sales']), ['brand' => true, 'delta' => t('rep.bills_n', ['n' => digits(I18n::num($z['bills']))])]) ?>
  <?= Ui::stat(t('rep.z_vat'), money(array_sum($z['vat'])), ['delta' => $rates]) ?>
  <?= Ui::stat(t('rep.z_voids'), money((int) $z['sum']['voids']['amount']), ['delta' => t('rep.items_n', ['n' => digits((int) $z['sum']['voids']['n'])])]) ?>
  <?= Ui::stat(t('rep.z_disc'), money($z['discount']), ['delta' => t('rep.bills_n', ['n' => digits($z['discount_n'])])]) ?>
</div>
<div class="zcards">
  <section class="card kvcard2">
    <h2 class="t-heading-m"><?= e(t('rep.z_payments')) ?></h2>
    <div class="kv2"><span><?= e(t('rep.z_cash_tl')) ?></span><span class="num"><?= e(money($z['cash_try'])) ?></span></div>
    <?php foreach ($z['fx'] as $f): ?>
      <div class="kv2"><span><?= e(t('rep.z_cash_fx', ['sym' => Money::symbol($f['currency']), 'n' => digits(I18n::num((float) $f['fx'])), 'rate' => digits(number_format((float) $f['rate'], 2, ',', '.'))])) ?></span><span class="num"><?= e(money((int) $f['try'])) ?></span></div>
    <?php endforeach ?>
    <div class="kv2"><span><?= e(t('rep.pay_card')) ?></span><span class="num"><?= e(money($z['methods']['card'] ?? 0)) ?></span></div>
    <div class="kv2"><span><?= e(t('rep.z_account')) ?></span><span class="num"><?= e(money($z['methods']['account'] ?? 0)) ?></span></div>
    <div class="kv2 kv2--em"><span><?= e(t('rep.total')) ?></span><span class="num"><?= e(money(array_sum($z['methods']))) ?></span></div>
    <?php if ($z['collections']): ?><div class="kv2"><span><?= e(t('rep.z_collections')) ?></span><span class="num"><?= e('+' . money($z['collections'])) ?></span></div><?php endif ?>
  </section>
  <section class="card kvcard2">
    <h2 class="t-heading-m"><?= e(t('rep.channels')) ?></h2>
    <div class="kv2"><span><?= e(t('rep.ch.table')) ?></span><span class="num"><?= e(money(($ch['table'] ?? 0) + ($ch['qr'] ?? 0))) ?></span></div>
    <div class="kv2 kv2--sub"><span><?= e(t('rep.z_in_qr')) ?></span><span class="num"><?= e(money($ch['qr'] ?? 0)) ?></span></div>
    <div class="kv2"><span><?= e(t('rep.ch.takeaway')) ?></span><span class="num"><?= e(money($ch['takeaway'] ?? 0)) ?></span></div>
    <div class="kv2"><span><?= e(t('rep.ch.delivery')) ?></span><span class="num"><?= e(money($ch['delivery'] ?? 0)) ?></span></div>
    <div class="kv2"><span><?= e(t('rep.ch.online')) ?></span><span class="num"><?= e(money($ch['online'] ?? 0)) ?></span></div>
    <div class="kv2"><span><?= e(t('rep.k_guests')) ?></span><span class="num"><?= e(digits(I18n::num($z['guests']))) ?></span></div>
    <div class="kv2"><span><?= e(t('rep.k_avg')) ?></span><span class="num"><?= e(t('rep.per_bill', ['amount' => money($z['bills'] ? (int) round($z['sales'] / $z['bills'] / 100) * 100 : 0)])) ?></span></div>
  </section>
  <section class="card kvcard2">
    <h2 class="t-heading-m"><?= e(t('rep.z_count')) ?></h2>
    <div class="kv2"><span><?= e(t('rep.z_expected')) ?></span><span class="num"><?= e(money($exp)) ?></span></div>
    <div class="kv2"><span><?= e(t('rep.z_counted')) ?></span><span class="num"><?= e($z['closed'] ? money($cnt) : '—') ?></span></div>
    <div class="kv2 kv2--em"><span><?= e(t('rep.z_diff')) ?></span><span class="num <?= $z['closed'] && $diff < 0 ? 'c-danger' : ($z['closed'] && $diff > 0 ? 'c-success' : '') ?>"><?= e($z['closed'] ? ($diff === 0 ? money(0) : ($diff < 0 ? '−' : '+') . money(abs($diff))) : '—') ?></span></div>
    <?php if ($fxState): ?><div class="kv2"><span>£ / $ / €</span><span><?= e($fxAll ? t('rep.z_exact') : implode(' · ', $fxState)) ?></span></div><?php endif ?>
    <div class="kv2"><span><?= e(t('rep.z_courier')) ?></span><span class="num"><?= e($z['courier'] ? t('rep.z_courier_v', ['amount' => money($z['courier'])]) : '—') ?></span></div>
    <div class="kv2"><span><?= e(t('rep.z_open_tables')) ?></span><span><?= e($z['open']['n'] ? digits($z['open']['n']) . ' · ' . money($z['open']['amount']) : t('rep.none')) ?></span></div>
    <div class="kv2"><span><?= e(t('rep.z_note')) ?></span><span class="ellipsis"><?= e($z['note'] !== '' ? $z['note'] : '—') ?></span></div>
  </section>
</div>
