<?php
/**
 * Printable account statement of one month ("Ekstre yazdır"), A4. Not a Figma frame: the restaurant profile,
 * the customer, the movements oldest first with the running balance, and the totals.
 * @var array $c @var string $month @var array $ledger @var ?string $address @var array $profile
 */
use Sofrexa\Modules\Customers\Customers;

$rows = array_reverse($ledger['rows']);
$debit = array_sum(array_map(static fn(array $r): int => max(0, (int) $r['amount']), $rows));
$credit = array_sum(array_map(static fn(array $r): int => max(0, -(int) $r['amount']), $rows));
$scripts = ['js/customers.js'];
?>
<div class="stmt">
  <div class="stmt__bar no-print">
    <a class="btn btn--secondary" href="/customers/<?= e($c['id']) ?>"><?= icon('arrow-left', 18, 'ic--flip') ?><span><?= e(t('ui.back')) ?></span></a>
    <button type="button" class="btn" data-print><?= icon('printer', 18) ?><span><?= e(t('cust.print')) ?></span></button>
  </div>
  <header class="stmt__head">
    <div>
      <div class="stmt__brand"><?= e((string) $profile['profile.name']) ?></div>
      <div class="stmt__small"><?= e(implode(' · ', array_filter([(string) $profile['profile.address'], (string) $profile['profile.phone']]))) ?></div>
      <?php if ($profile['profile.tax_no']): ?><div class="stmt__small"><?= e(t('cust.tax_no', ['no' => $profile['profile.tax_no']]) . ($profile['profile.tax_office'] ? ' · ' . $profile['profile.tax_office'] : '')) ?></div><?php endif ?>
    </div>
    <div class="stmt__title"><div><?= e(t('cust.statement')) ?></div><div class="stmt__small"><?= e(Customers::monthLabel($month)) ?></div></div>
  </header>
  <section class="stmt__cust">
    <div class="stmt__name"><?= e($c['name'] . ($c['company'] ? ' · ' . $c['company'] : '')) ?></div>
    <div class="stmt__small"><?= e(implode(' · ', array_filter([(string) $c['phone'], (string) $address, $c['tax_no'] ? t('cust.tax_no', ['no' => $c['tax_no']]) : '']))) ?></div>
  </section>
  <table class="stmt__table">
    <thead><tr><th><?= e(t('cust.c_date')) ?></th><th><?= e(t('cust.c_doc')) ?></th><th class="r"><?= e(t('cust.c_debit')) ?></th><th class="r"><?= e(t('cust.c_credit')) ?></th><th class="r"><?= e(t('cust.c_balance')) ?></th></tr></thead>
    <tbody>
      <tr class="stmt__carry"><td><?= e(digits('01.' . substr($month, 5, 2) . '.' . substr($month, 0, 4))) ?></td><td><?= e(t('cust.carry')) ?></td><td class="r">—</td><td class="r">—</td><td class="r"><?= e(money($ledger['opening'])) ?></td></tr>
      <?php foreach ($rows as $r): $amt = (int) $r['amount']; ?>
        <tr><td><?= e(digits(date('d.m.Y H:i', intdiv((int) $r['at'], 1000)))) ?></td><td><?= e($r['doc']) ?></td>
          <td class="r"><?= $amt > 0 ? e(money($amt)) : '—' ?></td><td class="r"><?= $amt < 0 ? e(money(-$amt)) : '—' ?></td><td class="r"><?= e(money((int) $r['balance'])) ?></td></tr>
      <?php endforeach ?>
    </tbody>
    <tfoot>
      <tr><td colspan="2"><?= e(t('cust.totals')) ?></td><td class="r"><?= e(money($debit)) ?></td><td class="r"><?= e(money($credit)) ?></td><td class="r"><?= e(money($ledger['closing'])) ?></td></tr>
    </tfoot>
  </table>
  <p class="stmt__small"><?= e(t('cust.statement_at', ['date' => digits(date('d.m.Y H:i'))])) ?></p>
</div>
