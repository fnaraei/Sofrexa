<?php
/**
 * Add an expense (or income) — Figma FI2 (95:507 phone): amount with currency, category chips, description,
 * date and payment, receipt photo, repeat every month. The desktop shows the same form centred.
 * @var string $kind @var array $cats @var array $currencies @var ?string $shift
 */
use Sofrexa\Core\Money;
use Sofrexa\Modules\Finance\Finance;
use Sofrexa\View\Ui;

$noHead = true;
$appSub = \Sofrexa\Modules\Customers\Customers::monthLabel(date('Y-m'));
$bodyClass = 'page-finform';
$bottom = Ui::btn(t($kind === 'income' ? 'fin.save_income' : 'fin.save_expense'), ['size' => 'l', 'icon' => 'check', 'block' => true, 'type' => 'submit', 'attrs' => ['form' => 'fin-form']]);
?>
<form class="finform" id="fin-form" method="post" action="/finance/new" enctype="multipart/form-data" data-ajax data-toast="off">
  <?= csrf_field() ?>
  <input type="hidden" name="kind" value="<?= e($kind) ?>">
  <div class="page-head page-head--item only-desktop">
    <?= Ui::ibtn('arrow-left', t('ui.back'), ['style' => 'secondary', 'href' => '/finance', 'class' => 'ibtn--flip']) ?>
    <div class="page-head__titles"><h1 class="t-heading-xl"><?= e($title) ?></h1><p class="t-body-m c-muted"><?= e($appSub) ?></p></div>
  </div>
  <div class="card amountcard">
    <span class="overline"><?= e(t('fin.amount')) ?></span>
    <input class="amountcard__input t-number-xl num" name="amount" inputmode="decimal" autocomplete="off" placeholder="₺0" aria-label="<?= e(t('fin.amount')) ?>" required autofocus data-amount>
    <div class="chips center">
      <?php foreach ($currencies as $i => $c): ?><label class="chip chip--s"><input type="radio" name="currency" value="<?= e($c) ?>"<?= $i === 0 ? ' checked' : '' ?> data-sym="<?= e(Money::symbol($c)) ?>"><?= e($c === 'TRY' ? '₺ TL' : Money::symbol($c)) ?></label><?php endforeach ?>
    </div>
  </div>
  <span class="overline"><?= e(t('fin.category')) ?></span>
  <div class="chips chips--wrap">
    <?php foreach ($cats as $i => $c): ?><?= Ui::chipRadio('category', $c, Finance::catLabel($c), false) ?><?php endforeach ?>
  </div>
  <?= Ui::field('description', ['label' => t('fin.c_desc'), 'icon' => 'note']) ?>
  <div class="row gap-10 end-a">
    <div class="finform__date"><?= Ui::field('day', ['label' => t('cust.c_date'), 'icon' => 'calendar', 'type' => 'date', 'value' => date('Y-m-d')]) ?></div>
    <div class="col grow" style="gap:6px"><span class="field__label"><?= e(t('fin.c_pay')) ?></span>
      <?= Ui::segs(['cash' => t('fin.m.cash_from'), 'bank' => t('fin.m.bank'), 'card' => t('fin.m.card')], $shift ? 'cash' : 'bank', 'method') ?></div>
  </div>
  <label class="lrow lrow--file finform__photo">
    <span class="lrow__lead lrow__lead--tile"><?= icon('image', 20) ?></span>
    <span class="lrow__mid"><span class="lrow__title"><?= e(t('fin.photo')) ?></span><span class="lrow__sub" data-file-name><?= e(t('fin.photo_s')) ?></span></span>
    <span class="t-label-l"><?= e(t('fin.add')) ?></span><?= icon('chevron-right', 20, 'c-muted') ?>
    <input type="file" name="receipt" accept="image/*,application/pdf" capture="environment" hidden data-file>
  </label>
  <?php if ($kind === 'expense'): ?>
    <div class="trow--2"><?= Ui::toggleRow('recurring', t('fin.repeat'), t('fin.repeat_s'), false) ?></div>
  <?php endif ?>
  <?php if (!$shift): ?><p class="t-body-s c-muted"><?= e(t('fin.no_shift_hint')) ?></p><?php endif ?>
  <div class="only-desktop"><?= Ui::btn(t($kind === 'income' ? 'fin.save_income' : 'fin.save_expense'), ['size' => 'l', 'icon' => 'check', 'block' => true, 'type' => 'submit']) ?></div>
</form>
