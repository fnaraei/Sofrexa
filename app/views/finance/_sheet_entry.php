<?php
/** One income / expense entered by hand: details, the receipt, cancel (a reversal; the cash move is reversed too). @var array $f */
use Sofrexa\Core\Money;
use Sofrexa\Modules\Finance\Finance;
use Sofrexa\View\Ui;

$reversed = (bool) \Sofrexa\Core\Db::value('SELECT 1 FROM finance_entries WHERE reverses = ?', [$f['id']]);
?>
<div class="scrim" data-dyn hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead(Finance::catLabel($f['category'])) ?>
    <div class="sheet__body">
      <div class="brandcard"><span class="overline"><?= e(t($f['kind'] === 'income' ? 'fin.f_income' : 'fin.f_expense')) ?></span>
        <span class="t-number-l num"><?= e(money((int) $f['amount'])) ?></span>
        <span class="t-body-s"><?= e(($f['currency'] !== 'TRY' ? Money::symbol($f['currency']) . digits(number_format((float) $f['amount_fx'], 2, ',', '.')) . ' · ' : '') . digits(date('d.m.Y', (int) strtotime($f['day'])))) ?></span></div>
      <div class="card kvcard">
        <div class="kv"><span><?= e(t('fin.c_desc')) ?></span><span><?= e((string) ($f['description'] ?: '—')) ?></span></div>
        <div class="kv"><span><?= e(t('fin.c_pay')) ?></span><span><?= e(t('fin.m.' . $f['method'])) ?></span></div>
        <div class="kv"><span><?= e(t('fin.c_src')) ?></span><span><?= e(t('fin.src.' . $f['source'])) ?></span></div>
        <div class="kv"><span><?= e(t('fin.by')) ?></span><span><?= e(first_name((string) $f['user_name']) ?: '—') ?></span></div>
      </div>
      <?php if ($f['receipt']): ?>
        <?= Ui::lrow(t('fin.photo'), ['icon' => 'image', 'href' => '/finance/receipt/' . $f['id'], 'attrs' => ['target' => '_blank']]) ?>
      <?php endif ?>
      <?php if ($reversed): ?>
        <p class="t-body-s c-muted"><?= e(t('fin.is_reversed')) ?></p>
      <?php else: ?>
        <div class="sheet__actions">
          <?= Ui::btn(t('ui.close'), ['style' => 'secondary', 'size' => 'l', 'attrs' => ['data-close' => true]]) ?>
          <?= Ui::btn(t('fin.reverse'), ['style' => 'danger', 'size' => 'l', 'icon' => 'undo', 'attrs' => ['data-post' => '/finance/entry/' . $f['id'] . '/reverse', 'data-confirm' => t('fin.reverse_q')]]) ?>
        </div>
      <?php endif ?>
    </div>
  </div>
</div>
