<?php
/**
 * Customer account — Figma CU2 (41:307 desktop: stats, account movements, profile with the tier row) and
 * CU4 (41:652 phone: debt card, collection, last movements, statement / collect bar). The movements card also
 * switches to the purchase history and the points history.
 * @var array $c @var int $balance @var bool $hasAccount @var string $view @var string $month @var array $months @var array $ledger
 * @var array $recent @var array $stats @var array $spend @var ?array $lastPay @var ?int $since @var ?string $address @var ?array $tier
 * @var int $points @var array $discount @var array $orders @var array $favs @var array $life @var array $history @var bool $online @var ?string $shift
 */
use Sofrexa\Core\I18n;
use Sofrexa\Modules\Customers\{Customers, Loyalty};
use Sofrexa\View\Ui;

$id = $c['id'];
$n = static fn(int $v): string => digits(I18n::num($v));
$tag = Customers::tag($c + ['orders_n' => $stats['orders'], 'online' => $online]);
$tagBadge = $tag !== '' ? Ui::badge(t('cust.tag.' . ($tag === 'credit' ? 'credit_long' : $tag)), Customers::TAG_TONES[$tag]) : '';
$headSub = implode(' · ', array_filter([(string) $c['company'], (string) $c['phone'], t('cust.orders_n', ['n' => $n($stats['orders'])]), $since ? t('cust.since', ['y' => digits($since), 'sfx' => Customers::trFrom($since)]) : '']));
$canCollect = $hasAccount && can('cash.pay');
$dmy = static fn(int $ms, string $f = 'd.m H:i'): string => digits(date($f, intdiv($ms, 1000)));
$tierLine = $tier ? t('cust.tier_member', ['tier' => $tier['name']]) . (max($discount) > 0 ? ' · ' . t('cust.disc_pct', ['p' => digits(I18n::numAuto(max($discount)))]) . ($discount['own'] > $discount['tier'] ? ' ' . t('cust.disc_own') : '') : '') : t('cust.no_tier');
$monthsLink = '/customers/' . $id . '?v=account&m=';
$statementUrl = '/customers/' . $id . '/statement?m=' . $month;

$noHead = true;
$back = '/customers';
$appTitle = $c['name'];
$appSub = implode(' · ', array_filter([(string) $c['company'], $tag !== '' ? mb_strtolower(t('cust.tag.' . $tag), 'UTF-8') : '']));
$appActions = [
    $c['phone'] ? Ui::ibtn('phone', t('cust.call'), ['class' => 'appbar__act', 'href' => 'tel:' . phone_norm($c['phone'])]) : '',
    Ui::ibtn('more', t('ui.more'), ['class' => 'appbar__act', 'attrs' => ['data-load-sheet' => '/customers/' . $id . '/sheet/more']]),
];
$bodyClass = 'page-customer';
if ($canCollect) {
    $bottom = Ui::btn(t('cust.statement'), ['style' => 'secondary', 'size' => 'l', 'icon' => 'printer', 'href' => $statementUrl, 'attrs' => ['target' => '_blank']])
        . Ui::btn(t('cust.collect_do'), ['style' => 'accent', 'size' => 'l', 'icon' => 'check', 'class' => 'grow', 'type' => 'submit', 'attrs' => ['form' => 'collect-form']]);
}
$segs = Ui::segs(['/customers/' . $id . '?v=account' => t('cust.v_account'), '/customers/' . $id . '?v=orders' => t('cust.v_orders'), '/customers/' . $id . '?v=points' => t('cust.v_points')], '/customers/' . $id . '?v=' . $view, null, true);
$methodText = static fn(?string $m): string => $m ? implode(' + ', array_map(static fn(string $x): string => t('cust.m_' . $x), explode(',', $m))) : '—';
$where = static fn(array $o): string => in_array($o['channel'], ['table', 'qr'], true)
    ? t('cust.doc_table', ['n' => digits((string) $o['table_no']), 'no' => digits(sprintf('%04d', (int) $o['no']))]) . ((int) $o['guests'] > 1 ? ' · ' . t('cust.doc_guests', ['n' => digits((int) $o['guests'])]) : '')
    : t('cust.doc_pack', ['no' => digits(sprintf('%04d', (int) $o['no']))]);
$pointText = static function (array $h): string {
    $o = $h['order_id'] && $h['no'] !== null ? ' · ' . t('cust.doc_pack', ['no' => digits(sprintf('%04d', (int) $h['no']))]) : '';
    return t('loy.k.' . $h['kind']) . $o . ($h['note'] ? ' · ' . $h['note'] : '');
};
?>
<div class="cust only-desktop">
  <div class="page-head page-head--item">
    <?= Ui::ibtn('arrow-left', t('ui.back'), ['style' => 'secondary', 'href' => '/customers', 'class' => 'ibtn--flip']) ?>
    <div class="page-head__titles"><h1 class="t-heading-xl ellipsis"><?= e($c['name']) ?></h1><p class="t-body-m c-muted ellipsis"><?= e($headSub) ?></p></div>
    <?php if ($hasAccount): ?><?= Ui::btn(t('cust.statement_print'), ['style' => 'secondary', 'icon' => 'printer', 'href' => $statementUrl, 'attrs' => ['target' => '_blank']]) ?><?php endif ?>
    <?php if ($canCollect): ?><?= Ui::btn(t('cust.collect'), ['style' => 'accent', 'icon' => 'cash', 'attrs' => ['data-load-sheet' => '/customers/' . $id . '/sheet/collect']]) ?><?php endif ?>
  </div>

  <div class="stats stats--4">
    <?php if ($hasAccount): ?>
      <?= Ui::stat(t('cust.k_balance'), money($balance), ['brand' => true, 'delta' => (int) $c['credit_limit'] > 0 ? t('cust.limit', ['amount' => money((int) $c['credit_limit'])]) : '']) ?>
    <?php else: ?>
      <?= Ui::stat(t('cust.k_life'), money($life['amount']), ['brand' => true, 'delta' => $life['bills'] ? t('cust.k_life_d', ['avg' => money($life['avg'])]) : '']) ?>
    <?php endif ?>
    <?= Ui::stat(t('cust.k_month'), money($spend['amount']), ['delta' => $spend['bills'] ? t('cust.bills_n', ['n' => $n($spend['bills'])]) : '']) ?>
    <?php if ($hasAccount): ?>
      <?= Ui::stat(t('cust.k_last_pay'), $lastPay ? money(-(int) $lastPay['amount']) : '—', ['delta' => $lastPay ? I18n::date((int) $lastPay['at'], 'short') . ' · ' . t('cust.m_' . ($lastPay['method'] ?: 'cash')) : '']) ?>
    <?php else: ?>
      <?= Ui::stat(t('cust.k_last_visit'), Customers::ago($stats['last']), ['delta' => $stats['last'] ? I18n::date($stats['last'], 'short') : '']) ?>
    <?php endif ?>
    <?= Ui::stat(t('cust.k_points'), $n($points), ['delta' => $tierLine]) ?>
  </div>

  <div class="cust__body">
    <section class="card card--pad0 ledger">
      <div class="ledger__title">
        <h2 class="t-heading-m grow"><?= e(t('cust.t_' . $view)) ?></h2>
        <?= $segs ?>
        <?php if ($view === 'account'): ?>
          <label class="chip chip--select"><span><?= e(Customers::monthLabel($month)) ?></span><?= icon('chevron-down', 16) ?>
            <select aria-label="<?= e(t('cust.month_pick')) ?>" data-go="<?= e($monthsLink) ?>"><?php foreach ($months as $mo): ?><option value="<?= e($mo) ?>"<?= $mo === $month ? ' selected' : '' ?>><?= e(Customers::monthLabel($mo)) ?></option><?php endforeach ?></select></label>
        <?php endif ?>
      </div>
      <?php if ($view === 'account'): ?>
        <div class="lrow5 lrow5--head"><span><?= e(t('cust.c_date')) ?></span><span><?= e(t('cust.c_doc')) ?></span><span><?= e(t('cust.c_debit')) ?></span><span><?= e(t('cust.c_credit')) ?></span><span><?= e(t('cust.c_balance')) ?></span></div>
        <?php foreach ($ledger['rows'] as $r): $amt = (int) $r['amount']; ?>
          <div class="lrow5">
            <span class="t-body-m c-muted num"><?= e($dmy((int) $r['at'])) ?></span>
            <span class="t-label-m ellipsis"><?= e($r['doc']) ?></span>
            <span class="t-body-m num <?= $amt > 0 ? 'c-danger' : 'c-muted' ?>"><?= $amt > 0 ? e(money($amt)) : '—' ?></span>
            <span class="t-body-m num <?= $amt < 0 ? 'c-success' : 'c-muted' ?>"><?= $amt < 0 ? e(money(-$amt)) : '—' ?></span>
            <span class="t-body-m num"><?= e(money((int) $r['balance'])) ?></span>
          </div>
        <?php endforeach ?>
        <?php if ($ledger['opening'] !== 0): ?>
          <div class="lrow5">
            <span class="t-body-m c-muted num"><?= e(digits('01.' . substr($month, 5, 2) . ' 00:00')) ?></span>
            <span class="t-label-m ellipsis"><?= e(t('cust.carry')) ?></span><span class="t-body-m c-muted">—</span><span class="t-body-m c-muted">—</span>
            <span class="t-body-m num"><?= e(money($ledger['opening'])) ?></span>
          </div>
        <?php elseif (!$ledger['rows']): ?>
          <div class="empty"><?= e(t('cust.no_moves')) ?></div>
        <?php endif ?>
      <?php elseif ($view === 'orders'): ?>
        <?php if ($favs): ?>
          <div class="favs"><span class="overline"><?= e(t('cust.favs')) ?></span>
            <?php foreach ($favs as $f): ?><span class="chip chip--s chip--static"><?= e($f['name']) ?><span class="chip__count"><?= e('×' . digits(Sofrexa\Modules\Orders\Orders::qtyText((float) $f['qty']))) ?></span></span><?php endforeach ?>
          </div>
        <?php endif ?>
        <div class="lrow5 lrow5--head"><span><?= e(t('cust.c_date')) ?></span><span><?= e(t('cust.c_doc')) ?></span><span><?= e(t('cust.c_items')) ?></span><span><?= e(t('cust.c_paid_by')) ?></span><span><?= e(t('cust.c_amount')) ?></span></div>
        <?php foreach ($orders as $o): ?>
          <div class="lrow5">
            <span class="t-body-m c-muted num"><?= e($dmy((int) ($o['closed_at'] ?: $o['opened_at']), 'd.m.y H:i')) ?></span>
            <span class="t-label-m ellipsis"><?= e($where($o)) ?></span>
            <span class="t-body-m c-secondary num"><?= e(digits(Sofrexa\Modules\Orders\Orders::qtyText((float) $o['items']))) ?></span>
            <span class="t-body-m c-secondary ellipsis"><?= e($methodText($o['methods'])) ?></span>
            <span class="t-body-m num"><?= e(money((int) $o['total'])) ?></span>
          </div>
        <?php endforeach ?>
        <?php if (!$orders): ?><div class="empty"><?= e(t('cust.no_orders')) ?></div><?php endif ?>
      <?php else: ?>
        <div class="lrow5 lrow5--pts lrow5--head"><span><?= e(t('cust.c_date')) ?></span><span><?= e(t('cust.c_what')) ?></span><span><?= e(t('cust.c_by')) ?></span><span><?= e(t('cust.c_points')) ?></span></div>
        <?php foreach ($history as $h): $p = (int) $h['points']; ?>
          <div class="lrow5 lrow5--pts">
            <span class="t-body-m c-muted num"><?= e($dmy((int) $h['at'], 'd.m.y H:i')) ?></span>
            <span class="t-label-m ellipsis"><?= e($pointText($h)) ?></span>
            <span class="t-body-m c-secondary ellipsis"><?= e(first_name($h['user_name'] ?? '') ?: '—') ?></span>
            <span class="t-label-m num <?= $p >= 0 ? 'c-accent' : 'c-secondary' ?>"><?= e(($p >= 0 ? '+' : '−') . $n(abs($p))) ?></span>
          </div>
        <?php endforeach ?>
        <?php if (!$history): ?><div class="empty"><?= e(t('loy.no_history')) ?></div><?php endif ?>
      <?php endif ?>
    </section>

    <aside class="card profile">
      <div class="profile__head">
        <?= Ui::avatar($c['name'], 'l') ?>
        <div class="col grow" style="gap:2px;min-width:0"><h2 class="t-heading-m ellipsis"><?= e($c['name']) ?></h2><?php if ($tagBadge !== ''): ?><div class="row"><?= $tagBadge ?></div><?php endif ?></div>
        <?php if (can('customers.manage')): ?><?= Ui::ibtn('pencil', t('cust.edit'), ['style' => 'ghost', 'attrs' => ['data-load-sheet' => '/customers/' . $id . '/sheet/edit']]) ?><?php endif ?>
      </div>
      <?php foreach (array_filter(['phone' => $c['phone'], 'mail' => $c['email'], 'map-pin' => $address, 'file-text' => $c['tax_no'] ? t('cust.tax_no', ['no' => $c['tax_no']]) : null, 'note' => $c['note']]) as $ic => $txt): ?>
        <div class="pinfo"><?= icon($ic, 18) ?><span class="t-body-m c-secondary"><?= e((string) $txt) ?></span></div>
      <?php endforeach ?>
      <div class="profile__tier">
        <?= icon('star', 18, 'c-accent') ?>
        <div class="col grow" style="gap:0;min-width:0"><span class="t-label-m"><?= e(t('cust.tier_is', ['tier' => $tier['name'] ?? '—'])) ?></span>
          <span class="t-body-s c-muted"><?= e($c['tier_manual'] ? t('cust.tier_manual') . ($c['tier_note'] ? ' · ' . $c['tier_note'] : '') : t('cust.tier_auto', ['m' => digits((int) \Sofrexa\Core\Settings::get('loyalty.tier_window_months', 12)), 'amount' => money(Loyalty::spend($id))])) ?></span></div>
        <?php if (can('customers.manage')): ?><?= Ui::btn(t('cust.change'), ['style' => 'secondary', 'size' => 's', 'icon' => 'pencil', 'attrs' => ['data-load-sheet' => '/customers/' . $id . '/sheet/tier']]) ?><?php endif ?>
      </div>
    </aside>
  </div>
</div>

<div class="cust-m only-mobile">
  <div class="brandcard">
    <?php if ($hasAccount): ?>
      <span class="overline"><?= e(t('cust.debt_now')) ?></span>
      <span class="t-number-xl num"><?= e(money($balance)) ?></span>
      <span class="t-body-s"><?= e(implode(' · ', array_filter([(int) $c['credit_limit'] > 0 ? t('cust.limit', ['amount' => money((int) $c['credit_limit'])]) : '', $lastPay ? t('cust.last_pay', ['date' => I18n::date((int) $lastPay['at'], 'short')]) : '']))) ?></span>
    <?php else: ?>
      <span class="overline"><?= e(t('cust.k_points')) ?></span>
      <span class="t-number-xl num"><?= e($n($points)) ?></span>
      <span class="t-body-s"><?= e($tierLine) ?></span>
    <?php endif ?>
  </div>

  <?php if ($canCollect): ?>
    <form class="col gap-12" id="collect-form" method="post" action="/customers/<?= e($id) ?>/collect" data-ajax data-reload>
      <?= csrf_field() ?>
      <span class="overline"><?= e(t('cust.collection')) ?></span>
      <?= Ui::field('amount', ['label' => t('cust.amount'), 'icon' => 'cash', 'value' => $balance > 0 ? money($balance) : '', 'attrs' => ['inputmode' => 'decimal', 'required' => true]]) ?>
      <div class="opts opts--3">
        <?= Ui::opt(t('cust.m_cash'), t('cust.m_cash_s'), 'cash', true, 'method', 'cash') ?>
        <?= Ui::opt(t('cust.m_card'), t('cust.m_card_s'), 'credit-card', false, 'method', 'card') ?>
        <?= Ui::opt(t('cust.m_transfer'), t('cust.m_transfer_s'), 'wallet', false, 'method', 'transfer') ?>
      </div>
      <?php if (!$shift): ?><p class="t-body-s c-muted"><?= e(t('cust.no_shift_hint')) ?></p><?php endif ?>
    </form>
  <?php endif ?>

  <?php if ($hasAccount): ?>
    <span class="overline"><?= e(t('cust.recent')) ?></span>
    <div class="card minilist">
      <?php foreach ($recent as $r): $amt = (int) $r['amount']; ?>
        <div class="lmrow"><span class="t-label-s c-muted num"><?= e($dmy((int) $r['at'], 'd.m')) ?></span><span class="t-body-m grow ellipsis"><?= e($r['doc']) ?></span>
          <span class="t-label-m num <?= $amt > 0 ? 'c-danger' : 'c-success' ?>"><?= e(($amt > 0 ? '+' : '−') . money(abs($amt))) ?></span></div>
      <?php endforeach ?>
      <?php if (!$recent): ?><div class="empty"><?= e(t('cust.no_moves')) ?></div><?php endif ?>
    </div>
  <?php else: ?>
    <span class="overline"><?= e(t('cust.recent_orders')) ?></span>
    <div class="card minilist">
      <?php foreach (array_slice(Customers::orders($id, 8), 0, 8) as $o): ?>
        <div class="lmrow"><span class="t-label-s c-muted num"><?= e($dmy((int) ($o['closed_at'] ?: $o['opened_at']), 'd.m')) ?></span><span class="t-body-m grow ellipsis"><?= e($where($o)) ?></span>
          <span class="t-label-m num"><?= e(money((int) $o['total'])) ?></span></div>
      <?php endforeach ?>
      <?php if (!$stats['orders']): ?><div class="empty"><?= e(t('cust.no_orders')) ?></div><?php endif ?>
    </div>
  <?php endif ?>
</div>
