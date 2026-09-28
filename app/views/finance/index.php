<?php
/**
 * Income and expenses — Figma FI1 (95:2 desktop: period, KPIs, movements table with filter chips,
 * expenses by category, recurring expenses). The phone uses the same blocks stacked.
 * @var array $p @var array $rows @var array $sum @var array $recurring @var string $filter
 */
use Sofrexa\Core\I18n;
use Sofrexa\Modules\Finance\Finance;
use Sofrexa\Modules\Reports\Reports;
use Sofrexa\View\Ui;

$label = in_array($p['key'], ['month', 'last_month'], true) ? \Sofrexa\Modules\Customers\Customers::monthLabel(substr($p['first'], 0, 7)) : Reports::rangeLabel($p);
$sub = t('fin.sub', ['period' => $label]);
$appSub = $label;
$headActions = \Sofrexa\Core\View::partial('reports/_period', ['p' => $p, 'keys' => ['month', 'last_month', 'custom'], 'base' => '/finance'])
    . Ui::btn(t('fin.add_income'), ['style' => 'secondary', 'icon' => 'plus', 'href' => '/finance/new?kind=income'])
    . Ui::btn(t('fin.add_expense'), ['icon' => 'plus', 'href' => '/finance/new']);
$appActions = [Ui::ibtn('file-sheet', 'Excel', ['class' => 'appbar__act', 'href' => '/finance/export?p=' . $p['key']]),
    Ui::ibtn('plus', t('fin.add_expense'), ['class' => 'appbar__act', 'href' => '/finance/new'])];
$bodyClass = 'page-finance';
$q = static fn(string $f): string => '/finance?' . http_build_query(array_filter(['p' => $p['key'] !== 'month' ? $p['key'] : null, 'from' => $p['key'] === 'custom' ? $p['first'] : null, 'to' => $p['key'] === 'custom' ? $p['last'] : null, 'f' => $f ?: null]));
$srcTone = ['manual' => 'neutral', 'recurring' => 'accent', 'stock' => 'info', 'payroll' => 'info', 'till' => 'info'];
$totalExp = max(1, $sum['expense']);
$ord = static fn(int $d): string => t('fin.day_of', ['d' => digits($d), 'sfx' => Finance::trDay($d)]);
?>
<div class="stats stats--4">
  <?= Ui::stat(t('fin.k_income'), money($sum['net_sales'] + $sum['other_income']), ['brand' => true, 'delta' => t('fin.k_income_d', ['s' => money($sum['net_sales']), 'o' => money($sum['other_income'])])]) ?>
  <?= Ui::stat(t('fin.k_expense'), money($sum['expense']), ['delta' => t('fin.k_expense_d', ['n' => digits(I18n::num($sum['expense_n']))])]) ?>
  <?= Ui::stat(t('fin.k_recurring'), money($sum['recurring']), ['delta' => t('fin.k_recurring_d', ['n' => digits($sum['recurring_n'])])]) ?>
  <?= Ui::stat(t('fin.k_receipt'), t('fin.k_receipt_v', ['n' => digits($sum['no_receipt'])]), ['delta' => $sum['no_receipt'] ? t('fin.k_receipt_d') : '']) ?>
</div>
<div class="fin">
  <section class="card card--pad0 fin__table">
    <div class="ledger__title">
      <h2 class="t-heading-s grow"><?= e(t('fin.moves')) ?></h2>
      <div class="chips only-desktop">
        <?= Ui::chip(t('ui.all'), $filter === '', null, ['href' => $q('')]) ?>
        <?= Ui::chip(t('fin.f_expense'), $filter === 'expense', null, ['href' => $q('expense')]) ?>
        <?= Ui::chip(t('fin.f_income'), $filter === 'income', null, ['href' => $q('income')]) ?>
        <?= Ui::chip(t('fin.f_auto'), $filter === 'auto', null, ['href' => $q('auto')]) ?>
      </div>
      <?= Ui::ibtn('file-sheet', 'Excel', ['style' => 'ghost', 'class' => 'only-desktop', 'href' => '/finance/export?p=' . $p['key']]) ?>
    </div>
    <div class="frow frow--head only-desktop"><span><?= e(t('cust.c_date')) ?></span><span><?= e(t('fin.c_cat')) ?></span><span><?= e(t('fin.c_desc')) ?></span><span><?= e(t('fin.c_pay')) ?></span><span><?= e(t('fin.c_src')) ?></span><span><?= e(t('cust.c_amount')) ?></span></div>
    <?php foreach ($rows as $r):
        $attrs = $r['own'] ? ' data-load-sheet="/finance/entry/' . e($r['id']) . '" role="button" tabindex="0"' : ''; ?>
      <div class="frow<?= $r['own'] ? ' is-link' : '' ?>"<?= $attrs ?>>
        <span class="t-label-m num"><?= e(digits(date('d.m', (int) strtotime($r['day'])))) ?></span>
        <span class="only-desktop"><?= Ui::badge(Finance::catLabel($r['category']), $r['kind'] === 'income' ? 'success' : 'neutral') ?></span>
        <span class="frow__desc"><span class="t-body-m c-secondary ellipsis"><?= e($r['text'] !== '' ? $r['text'] : Finance::catLabel($r['category'])) ?></span><span class="t-body-s c-muted only-mobile"><?= e(Finance::catLabel($r['category']) . ' · ' . t('fin.m.' . $r['method'])) ?></span></span>
        <span class="t-body-m c-secondary only-desktop"><?= e(t('fin.m.' . $r['method'])) ?><?php if ($r['receipt']): ?> <?= icon('image', 14, 'c-muted') ?><?php endif ?></span>
        <span class="only-desktop"><?= Ui::badge(t('fin.src.' . $r['source']), $srcTone[$r['source']] ?? 'neutral') ?></span>
        <span class="t-label-m num <?= $r['kind'] === 'income' ? 'c-success' : 'c-danger' ?>"><?= e(($r['kind'] === 'income' ? '+' : '−') . money($r['amount'])) ?></span>
      </div>
    <?php endforeach ?>
    <?php if (!$rows): ?><div class="empty"><?= e(t('fin.none')) ?></div><?php endif ?>
  </section>
  <div class="fin__side">
    <section class="card catcard">
      <h2 class="t-heading-s"><?= e(t('fin.by_cat')) ?></h2>
      <?php foreach ($sum['cats'] as $c => $v): ?>
        <div class="catbar"><div class="row gap-8"><span class="grow t-body-m c-secondary ellipsis"><?= e(Finance::catLabel((string) $c)) ?></span><span class="t-label-m num"><?= e(money($v)) ?></span></div>
          <span class="catbar__track"><span class="catbar__fill" style="width:<?= max(1.5, round($v * 100 / $totalExp, 1)) ?>%"></span></span></div>
      <?php endforeach ?>
      <?php if (!$sum['cats']): ?><div class="t-body-s c-muted"><?= e(t('fin.none')) ?></div><?php endif ?>
    </section>
    <section class="card kvcard3">
      <h2 class="t-heading-s"><?= e(t('fin.recurring')) ?></h2>
      <?php foreach ($recurring as $r): ?>
        <div class="kv"><span class="ellipsis" title="<?= e((string) $r['description']) ?>"><?= e(Finance::catLabel($r['category']) . ' · ' . $ord((int) $r['day_of_month'])) ?></span><span class="t-label-l num"><?= e(money((int) $r['amount'])) ?></span>
          <button type="button" class="ibtn ibtn--ghost ibtn--s" data-post="/finance/recurring/<?= e($r['id']) ?>/stop" data-confirm="<?= e(t('fin.rec_stop_q')) ?>" aria-label="<?= e(t('fin.rec_stop')) ?>"><?= icon('close', 16) ?></button></div>
      <?php endforeach ?>
      <?php if (!$recurring): ?><div class="t-body-s c-muted"><?= e(t('fin.rec_none')) ?></div><?php endif ?>
    </section>
  </div>
</div>
