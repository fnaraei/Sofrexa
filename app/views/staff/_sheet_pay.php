<?php
/**
 * Paying the selected people ("Seçilenlere ödeme", ST2): the amount per person (what is left by default),
 * cash from the till or bank transfer, a note. Not a separate Figma frame (Sheet, Input, OptionTile).
 * @var array $rows @var string $month @var ?string $shift
 */
use Sofrexa\Modules\Customers\Customers;
use Sofrexa\View\Ui;

$advance = $month >= date('Y-m');
?>
<div class="scrim" data-dyn hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead($advance ? t('pay2.sheet_adv') : t('pay2.sheet_pay')) ?>
    <form class="sheet__body" method="post" action="/staff/pay" data-ajax data-toast="off" data-paysheet>
      <?= csrf_field() ?>
      <input type="hidden" name="month" value="<?= e($month) ?>">
      <p class="t-body-s c-muted"><?= e(Customers::monthLabel($month) . ($advance ? ' · ' . t('pay2.adv_hint') : '')) ?></p>
      <div class="card paylines">
        <?php foreach ($rows as $r): ?>
          <div class="payline">
            <span class="grow col" style="gap:0;min-width:0"><span class="t-label-m ellipsis"><?= e($r['name']) ?></span><span class="t-body-s c-muted"><?= e(t('pay2.c_left') . ' ' . money($r['left'])) ?></span></span>
            <span class="cellin payline__in"><input name="amount[<?= e($r['id']) ?>]" value="<?= e($r['left'] > 0 ? money($r['left']) : '') ?>" inputmode="decimal" aria-label="<?= e($r['name']) ?>" data-pay-amt></span>
          </div>
        <?php endforeach ?>
      </div>
      <div class="opts opts--2">
        <?= Ui::opt(t('pay2.m_cash'), t('pay2.m_cash_s'), 'cash', (bool) $shift, 'method', 'cash') ?>
        <?= Ui::opt(t('pay2.m_bank'), t('pay2.m_bank_s'), 'wallet', !$shift, 'method', 'bank') ?>
      </div>
      <?php if (!$shift): ?><p class="t-body-s c-muted"><?= e(t('pay2.no_shift')) ?></p><?php endif ?>
      <?= Ui::field('note', ['label' => t('cust.f_note'), 'icon' => 'note']) ?>
      <div class="kv kv--total"><span class="t-label-l"><?= e(t('pay2.total')) ?></span><span class="t-heading-m num" data-pay-total></span></div>
      <div class="sheet__actions">
        <?= Ui::btn(t('ui.cancel'), ['style' => 'secondary', 'size' => 'l', 'attrs' => ['data-close' => true]]) ?>
        <?= Ui::btn(t('pay2.do_pay'), ['size' => 'l', 'icon' => 'check', 'type' => 'submit']) ?>
      </div>
    </form>
  </div>
</div>
