<?php
/**
 * Customers — Figma CU1 (41:2 desktop: search, filter chips, table) and CU3 (41:526 phone: chips, list card).
 * @var array $rows @var array $sum @var string $filter @var string $q
 */
use Sofrexa\Core\I18n;
use Sofrexa\Modules\Customers\Customers;
use Sofrexa\View\Ui;

$n = static fn(int $v): string => digits(I18n::num($v));
$sub = t('cust.sub', ['n' => $n($sum['all']), 'c' => $n($sum['credit']), 'amount' => money($sum['receivable'])]);
$appSub = t('cust.sub_m', ['n' => $n($sum['all']), 'amount' => money($sum['receivable'])]);
$url = static fn(string $f): string => '/customers' . ($f !== '' ? '?f=' . $f : '');
$headActions = (can('customers.manage') ? Ui::btn(t('loy.title'), ['style' => 'ghost', 'icon' => 'star', 'href' => '/customers/loyalty']) : '')
    . Ui::btn(t('cust.export'), ['style' => 'secondary', 'icon' => 'download', 'href' => '/customers/export' . ($filter !== '' ? '?f=' . $filter : '')])
    . Ui::btn(t('cust.new'), ['icon' => 'user-plus', 'attrs' => ['data-load-sheet' => '/customers/new/sheet/edit']]);
$appActions = [
    Ui::ibtn('search', t('ui.search'), ['class' => 'appbar__act', 'attrs' => ['data-search-toggle' => true]]),
    Ui::ibtn('user-plus', t('cust.new'), ['class' => 'appbar__act', 'attrs' => ['data-load-sheet' => '/customers/new/sheet/edit']]),
];
$bodyClass = 'page-customers';
$chips = [['', t('ui.all'), $sum['all'], null], ['debt', t('cust.f_debt'), $sum['debt'], t('cust.f_debt_m')], ['regular', t('cust.f_regular'), $sum['regular'], null],
    ['online', t('cust.f_online'), $sum['online'], null], ['blacklist', t('cust.f_blacklist'), $sum['blacklist'], null]];
$amount = static fn(int $b): string => '<span class="t-label-m num ' . ($b > 0 ? 'c-danger' : 'c-muted') . '">' . e(money($b)) . '</span>';
?>
<form class="toolbar cust-filter" method="get" action="/customers">
  <?= Ui::field('q', ['type' => 'search', 'icon' => 'search', 'placeholder' => t('cust.search'), 'value' => $q, 'class' => 'toolbar__search toolbar__search--320']) ?>
  <?php if ($filter !== ''): ?><input type="hidden" name="f" value="<?= e($filter) ?>"><?php endif ?>
  <div class="chips only-desktop">
    <?php foreach ($chips as [$f, $label, $count]): ?><?= Ui::chip($label, $filter === $f, $n($count), ['href' => $url($f)]) ?><?php endforeach ?>
  </div>
</form>
<div class="chips chips--scroll only-mobile">
  <?php foreach ($chips as [$f, $label, $count, $labelM]): ?><?= Ui::chip($labelM ?? $label, $filter === $f, $n($count), ['href' => $url($f)]) ?><?php endforeach ?>
</div>

<?php if (!$sum['all']): ?>
  <div class="empty"><?= icon('users', 24) ?><div><?= e(t('cust.none')) ?></div></div>
<?php else: ?>
<section class="card card--pad0 custtable only-desktop">
  <div class="crow crow--head"><span><?= e(t('cust.c_customer')) ?></span><span><?= e(t('cust.c_phone')) ?></span><span><?= e(t('cust.c_tag')) ?></span><span><?= e(t('cust.c_orders')) ?></span><span><?= e(t('cust.c_last')) ?></span><span><?= e(t('cust.c_balance')) ?></span></div>
  <?php if (!$rows): ?><div class="empty"><?= e(t('cust.empty')) ?></div><?php endif ?>
  <?php foreach ($rows as $r): ?>
    <a class="crow" href="/customers/<?= e($r['id']) ?>">
      <span class="who"><?= Ui::avatar($r['name'], 's') ?><span class="t-label-m ellipsis"><?= e($r['name'] . ($r['company'] ? ' (' . $r['company'] . ')' : '')) ?></span></span>
      <span class="t-body-m c-secondary ellipsis"><?= e((string) $r['phone']) ?></span>
      <span><?= $r['tag'] !== '' ? Ui::badge(t('cust.tag.' . $r['tag']), Customers::TAG_TONES[$r['tag']]) : '' ?></span>
      <span class="t-body-m c-secondary num"><?= e($n($r['orders_n'])) ?></span>
      <span class="t-body-m c-secondary"><?= e(Customers::ago($r['last_at'])) ?></span>
      <?= $amount($r['balance']) ?>
    </a>
  <?php endforeach ?>
</section>
<div class="card custlist only-mobile">
  <?php if (!$rows): ?><div class="empty"><?= e(t('cust.empty')) ?></div><?php endif ?>
  <?php foreach ($rows as $r): ?>
    <a class="custrow" href="/customers/<?= e($r['id']) ?>">
      <?= Ui::avatar($r['name']) ?>
      <span class="custrow__mid"><span class="t-label-m ellipsis"><?= e($r['name'] . ($r['company'] ? ' (' . $r['company'] . ')' : '')) ?></span><span class="t-body-s c-muted ellipsis"><?= e((string) $r['phone']) ?></span></span>
      <?= $amount($r['balance']) ?>
    </a>
  <?php endforeach ?>
</div>
<?php endif ?>
