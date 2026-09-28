<?php
/**
 * Payment — Figma C2 (26:232 desktop: bill panel + payment panel) and C3 (26:557 phone).
 * Methods: cash (lira or foreign at the hand-entered rate, change in lira), card, account, mixed (cash + card).
 * @var array $o @var int $due @var int $share @var int $persons @var int $done @var array $rates @var array $currencies
 * @var ?array $shift @var string $discountText @var array $vat @var ?array $customer
 */
use Sofrexa\Core\Money;
use Sofrexa\Modules\Orders\Board;
use Sofrexa\View\Ui;

$where = in_array($o['channel'], ['table', 'qr'], true) ? t('order.table', ['n' => digits($o['table_no'])]) : Board::title($o);
$items = (float) array_sum(array_map(static fn(array $l): float => $l['status'] === 'void' ? 0 : (float) $l['qty'], $o['lines']));
$who = first_name($o['waiter_name'] ?? '') ?: ($o['customer_name'] ?? '');
$appTitle = t('pay.title_m', ['where' => $where]);
$appSub = t('pay.sub_m', ['n' => digits(\Sofrexa\Modules\Orders\Orders::qtyText($items)), 'who' => $who]);
$back = '/cashier';
$noHead = true;
$appActions = [
    Ui::ibtn('receipt', t('order.panel'), ['class' => 'appbar__act', 'attrs' => ['data-load-sheet' => '/cashier/pay/' . $o['id'] . '/sheet/bill']]),
    Ui::ibtn('more', t('ui.more'), ['class' => 'appbar__act', 'attrs' => ['data-load-sheet' => '/cashier/pay/' . $o['id'] . '/sheet/more']]),
];
$bodyClass = 'page-pay';
$headSub = in_array($o['channel'], ['table', 'qr'], true)
    ? t('pay.head_sub', ['no' => digits(sprintf('%04d', (int) $o['no'])), 'g' => digits(max(1, (int) $o['guests'])), 'waiter' => first_name($o['waiter_name'] ?? '—'), 't' => dur((int) $o['opened_at'])])
    : t('pay.head_sub_s', ['no' => digits(sprintf('%04d', (int) $o['no'])), 'who' => $o['customer_name'] ?? ($o['label'] ?? '—'), 't' => dur((int) $o['opened_at'])]);
$badge = ($o['bill_at'] || $o['status'] === 'billed') ? Ui::badge(t('cash.b_bill'), 'warning', true) : Ui::badge(t('cash.b_open'), 'accent', true);
$rateMap = [];
foreach ($rates as $c => $r) {
    if ($r['accepted'] && $r['rate']) {
        $rateMap[$c] = ['rate' => $r['rate'], 'sym' => Money::symbol($c), 'when' => $r['at'] ? (date('Y-m-d', intdiv((int) $r['at'], 1000)) === date('Y-m-d') ? t('rates.today', ['time' => digits(date('H:i', intdiv((int) $r['at'], 1000)))]) : t('rates.yesterday', ['time' => digits(date('H:i', intdiv((int) $r['at'], 1000)))])) : '', 'by' => first_name($r['by'] ?? '')];
    }
}
$cfg = [
    'share' => $share, 'rates' => $rateMap, 'lang' => \Sofrexa\Core\I18n::lang(),
    'str' => [
        'rate' => t('pay.rate_line', ['sym' => '{sym}', 'rate' => '{rate}', 'when' => '{when}', 'who' => '{who}']),
        'rate_m' => t('pay.rate_line_m', ['sym' => '{sym}', 'rate' => '{rate}', 'due' => '{due}']),
        'received' => t('pay.received', ['sym' => '{sym}']),
        'exact' => t('pay.exact', ['amount' => '{amount}']),
        'rest' => t('pay.card_rest', ['amount' => '{amount}']),
        'short' => t('pay.short'),
        'change' => t('pay.change'),
        'change_m' => t('pay.change_m'),
    ],
];
$opt = static function (string $value, string $icon, string $label, string $sub, string $subM, bool $on): string {
    return '<label class="opt"><input type="radio" name="method" value="' . e($value) . '"' . ($on ? ' checked' : '') . '>' . icon($icon, 24)
        . '<span class="opt__label">' . e($label) . '</span><span class="opt__sub ellipsis"><span class="only-desktop">' . e($sub) . '</span><span class="only-mobile">' . e($subM) . '</span></span></label>';
};
$curSegs = '';
foreach ($currencies as $c) {
    if ($c !== 'TRY' && !isset($rateMap[$c])) {
        continue;
    }
    $curSegs .= '<label class="seg"><input type="radio" name="currency" value="' . e($c) . '"' . ($c === 'TRY' ? ' checked' : '') . '>' . e(Money::symbol($c) . ' ' . ($c === 'TRY' ? 'TL' : $c)) . '</label>';
}
$bottom = Ui::btn(t('pay.complete'), ['style' => 'accent', 'size' => 'l', 'icon' => 'check', 'attrs' => ['data-pay-submit' => true, 'disabled' => !$shift]]);
?>
<?php if (!$shift): ?>
  <div class="banner banner--warning" role="status"><?= icon('lock', 20) ?><div class="col grow" style="gap:2px"><div class="banner__title"><?= e(t('cash.no_shift_t')) ?></div><div class="banner__text"><?= e(t('cash.no_shift')) ?></div></div>
    <?php if (can('cash.shift')): ?><?= Ui::btn(t('shift.open_btn'), ['size' => 's', 'icon' => 'lock', 'attrs' => ['data-load-sheet' => '/cashier/shift/open']]) ?><?php endif ?></div>
<?php endif ?>
<div class="pay">
  <section class="pay__bill only-desktop">
    <div class="row gap-12">
      <?= Ui::ibtn('arrow-left', t('ui.back'), ['style' => 'secondary', 'href' => '/cashier', 'class' => 'ibtn--flip']) ?>
      <div class="grow col gap-2"><h1 class="t-heading-xl ellipsis"><?= e(Board::title($o)) ?></h1><p class="t-body-m c-muted ellipsis"><?= e($headSub) ?></p></div>
      <?= $badge ?>
    </div>
    <div class="segs" data-split-mode>
      <label class="seg"><input type="radio" name="split" value="one"<?= $persons < 2 ? ' checked' : '' ?>><?= e(t('pay.split_one')) ?></label>
      <label class="seg"><input type="radio" name="split" value="person"<?= $persons >= 2 ? ' checked' : '' ?>><?= e(t('pay.split_person')) ?></label>
      <label class="seg" data-load-sheet="/orders/<?= e($o['id']) ?>/sheet/split"><input type="radio" name="split" value="item"><?= e(t('pay.split_item')) ?></label>
    </div>
    <?= \Sofrexa\Core\View::partial('cashier/_bill', ['o' => $o, 'discountText' => $discountText, 'vat' => $vat]) ?>
  </section>

  <form class="pay__panel" method="post" action="/cashier/pay/<?= e($o['id']) ?>" data-pay-form data-pay='<?= e(json_encode($cfg, JSON_UNESCAPED_UNICODE)) ?>'>
    <?= csrf_field() ?>
    <input type="hidden" name="share" value="<?= $share ?>">
    <input type="hidden" name="persons" value="<?= $persons ?>">
    <input type="hidden" name="k" value="<?= $done ?>">
    <input type="hidden" name="customer_id" value="<?= e($o['customer_id'] ?? '') ?>">
    <div class="paytotal only-mobile">
      <div class="overline"><?= e(t('order.total_vat')) ?></div>
      <div class="row between end-a"><span class="t-number-xl num"><?= e(money($share)) ?></span><?php if ((int) $o['discount'] > 0): ?><span class="t-label-m c-accent"><?= e(t('order.discount') . ' ' . money(-(int) $o['discount'])) ?></span><?php endif ?></div>
    </div>
    <h2 class="t-heading-l only-desktop"><?= e(t('pay.title')) ?></h2>
    <?php if ($persons >= 2): ?>
      <div class="payshare">
        <?= icon('users', 20) ?><span class="grow t-label-m"><?= e(t('pay.per_person', ['amount' => money($share)])) ?></span><span class="t-body-s c-muted"><?= e(t('pay.paid_n', ['n' => digits($done), 't' => digits($persons)])) ?></span>
        <div class="qty" data-stepper><button type="button" data-step="-1" aria-label="−"><?= icon('minus', 18) ?></button><span class="qty__n num" data-stepper-n><?= e(digits($persons)) ?></span><button type="button" data-step="1" aria-label="+"><?= icon('plus', 18) ?></button><input type="hidden" value="<?= $persons ?>" data-stepper-v data-min="2" data-max="20" data-persons></div>
      </div>
    <?php endif ?>
    <div class="paymethods">
      <?= $opt('cash', 'cash', t('pay.m_cash'), t('pay.m_cash_s'), t('pay.m_cash_s'), true) ?>
      <?= $opt('card', 'credit-card', t('pay.m_card'), t('pay.m_card_s'), t('pay.m_card_sm'), false) ?>
      <?= $opt('account', 'wallet', t('pay.m_account'), t('pay.m_account_s'), t('pay.m_account_sm'), false) ?>
      <?= $opt('mixed', 'split', t('pay.m_mixed'), t('pay.m_mixed_s'), t('pay.m_mixed_s'), false) ?>
    </div>
    <div class="segs" data-show="cash mixed"><?= $curSegs ?></div>
    <div class="payrate only-desktop" data-show="cash mixed" data-rate-box hidden><?= icon('currency', 18) ?><span class="grow t-body-s c-secondary" data-rate-line></span>
      <?php if (can('cash.rates')): ?><?= Ui::btn(t('pay.change_rate'), ['style' => 'ghost', 'size' => 's', 'href' => '/cashier/rates?back=' . rawurlencode('/cashier/pay/' . $o['id'])]) ?><?php endif ?></div>
    <div class="payrate-m only-mobile" data-show="cash mixed" data-rate-box hidden><?= icon('currency', 16) ?><span class="t-body-s c-secondary" data-rate-line-m></span></div>
    <div class="paydue only-desktop" data-show="cash mixed">
      <div class="col gap-2"><span class="overline"><?= e(t('pay.due')) ?></span><span class="t-number-xl num" data-due></span></div>
      <span class="t-heading-m c-muted num" data-due-try></span>
    </div>
    <div data-show="cash mixed"><?= Ui::field('received', ['label' => t('pay.received', ['sym' => '₺']), 'icon' => 'cash', 'attrs' => ['inputmode' => 'decimal', 'data-received' => true]]) ?></div>
    <div class="chips chips--wrap only-desktop" data-show="cash" data-quick></div>
    <div data-show="card"><?= Ui::field('card', ['label' => t('pay.card_amount'), 'icon' => 'credit-card', 'value' => number_format($share / 100, $share % 100 ? 2 : 0, ',', '.'), 'attrs' => ['inputmode' => 'decimal']]) ?></div>
    <div class="payrest" data-show="mixed" data-rest></div>
    <div class="paycust" data-show="account">
      <?php if ($customer): ?><div class="who who--m"><?= Ui::avatar($customer['name']) ?><span class="col" style="gap:0"><span class="who__name"><?= e($customer['name']) ?></span><span class="who__sub"><?= e((string) $customer['phone']) ?></span></span></div><?php endif ?>
      <?= Ui::field('cust_q', ['label' => t('pay.customer'), 'icon' => 'search', 'placeholder' => t('pay.customer_find'), 'attrs' => ['data-cust-search' => true]]) ?>
      <div class="picklist" data-cust-list></div>
    </div>
    <div class="paychange" data-show="cash mixed" data-change-box hidden>
      <?= icon('check-circle', 22, 'only-desktop') ?>
      <span class="grow t-label-m"><span class="only-desktop"><?= e(t('pay.change')) ?></span><span class="only-mobile"><?= e(t('pay.change_m')) ?></span></span>
      <span class="t-heading-l num" data-change></span>
    </div>
    <div class="grow only-desktop"></div>
    <label class="row gap-10 only-desktop t-body-m c-secondary"><?= Ui::checkbox('receipt', true) ?><?= e(t('pay.print')) ?></label>
    <?= Ui::btn(t('pay.complete'), ['type' => 'submit', 'style' => 'accent', 'size' => 'l', 'block' => true, 'icon' => 'check', 'class' => 'only-desktop', 'attrs' => ['disabled' => !$shift]]) ?>
  </form>
</div>
