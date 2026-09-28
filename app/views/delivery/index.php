<?php
/**
 * Takeaway and delivery board — Figma C5 (28:741): four columns (awaiting approval, kitchen, ready, on the way) and couriers.
 * @var array $cols @var array $couriers @var array $stats
 */
use Sofrexa\Core\{I18n, Money, Settings};
use Sofrexa\Modules\Orders\{Board, Till};
use Sofrexa\View\Ui;

$fee = (int) Settings::get('online.delivery_fee', 0);
$sub = t('deliv.sub', ['n' => digits($stats['n']), 'avg' => t('dur.min', ['m' => digits($stats['avg_min'])]), 'fee' => $fee ? t('deliv.fee', ['amount' => money($fee)]) : t('deliv.fee_free')]);
$headActions = Ui::btn(t('deliv.pickup_btn'), ['style' => 'secondary', 'icon' => 'bag', 'href' => '/delivery/new?type=pickup'])
    . Ui::btn(t('deliv.phone_btn'), ['icon' => 'phone', 'href' => '/delivery/new']);
$appActions = [Ui::ibtn('plus', t('deliv.phone_btn'), ['class' => 'appbar__act', 'href' => '/delivery/new'])];
$bodyClass = 'page-delivery aside-320';
$heads = [
    'pending' => ['deliv.col_pending', 'attention'],
    'kitchen' => ['deliv.col_kitchen', 'info'],
    'ready' => ['deliv.col_ready', 'success'],
    'way' => ['deliv.col_way', 'warning'],
];
$card = static function (array $o): string {
    $d = $o['delivery'];
    $stage = $o['stage'];
    $who = Till::shortName($o['customer_name'] ?? $o['label'] ?? '') ?: first_name($o['waiter_name'] ?? '');
    $addr = trim(explode(',', (string) ($d['address'] ?? ''))[0]);
    $courier = !empty($d['courier_id']) ? first_name((string) \Sofrexa\Core\Db::value('SELECT name FROM users WHERE id = ?', [$d['courier_id']])) : '';
    [$icon, $title] = match ($o['channel']) {
        'online' => ['globe', Board::title($o)],
        'takeaway' => ['bag', Board::title($o)],
        default => [$stage === 'way' ? 'bike' : 'phone', '#' . digits(sprintf('%04d', (int) $o['no'])) . ($addr !== '' ? ' · ' . $addr : '')],
    };
    $sub = match ($stage) {
        'pending' => implode(' · ', array_filter([$who, !empty($d['pickup_at']) ? t('deliv.pickup_at', ['time' => digits((string) $d['pickup_at'])]) : dur((int) $o['opened_at'])])),
        'ready' => implode(' · ', array_filter([$who, t('deliv.ready_ago', ['t' => dur((int) ($d['ready_at'] ?? $o['opened_at']))])])),
        'way' => implode(' · ', array_filter([$who, $courier, dur((int) ($d['out_at'] ?? $o['opened_at']))])),
        default => implode(' · ', array_filter([$who, !empty($d['pickup_at']) ? t('deliv.pickup_at', ['time' => digits((string) $d['pickup_at'])]) : dur((int) $o['opened_at'])])),
    };
    $hint = $d['pay_hint'] ?? 'cash';
    $total = (int) $o['total'];
    [$pIcon, $pText] = (int) $o['paid'] >= $total && $total > 0 ? ['check', t('deliv.pay_paid')] : match ($hint) {
        'card' => ['credit-card', t('deliv.pay_card')],
        'account' => ['wallet', t('deliv.pay_account')],
        default => ['cash', !empty($d['amount_fx']) && !empty($d['currency']) && $d['currency'] !== 'TRY'
            ? t('deliv.pay_cash') . ' ' . Money::symbol($d['currency']) . digits(I18n::num((float) $d['amount_fx']))
            : ((int) ($d['cash_given'] ?? 0) > $total ? t('deliv.pay_change', ['amount' => money((int) $d['cash_given'])]) : t('deliv.pay_cash'))],
    };
    return '<button type="button" class="dcard" data-load-sheet="/delivery/' . e($o['id']) . '/sheet">'
        . '<span class="dcard__row">' . icon($icon, 18) . '<span class="t-label-m grow ellipsis">' . e($title) . '</span><span class="t-label-m num">' . e(money($total)) . '</span></span>'
        . '<span class="t-body-s c-secondary ellipsis">' . e($sub) . '</span>'
        . '<span class="dcard__pay">' . icon($pIcon, 16) . '<span class="t-body-s c-muted">' . e($pText) . '</span></span></button>';
};
$aside = \Sofrexa\Core\View::partial('delivery/_couriers', ['couriers' => $couriers]);
?>
<div class="kanban">
  <?php foreach ($heads as $key => [$label, $tone]): ?>
    <section class="kcol">
      <div class="kcol__head"><span class="t-label-l grow"><?= e(t($label)) ?></span><?= Ui::badge(digits(count($cols[$key])), $tone) ?></div>
      <?php foreach ($cols[$key] as $o): ?><?= $card($o) ?><?php endforeach ?>
    </section>
  <?php endforeach ?>
</div>
<div class="only-mobile"><?= $aside ?></div>
