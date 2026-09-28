<?php
/**
 * Collection on the desktop ("Tahsilat al"): the TAHSİLAT block of Figma CU4 (41:652) in a sheet —
 * debt card, amount, method, note. @var array $c @var int $balance @var ?string $shift
 */
use Sofrexa\View\Ui;

?>
<div class="scrim" data-dyn hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead(t('cust.collect')) ?>
    <form class="sheet__body" method="post" action="/customers/<?= e($c['id']) ?>/collect" data-ajax data-toast="off">
      <?= csrf_field() ?>
      <div class="brandcard"><span class="overline"><?= e(t('cust.debt_now')) ?></span><span class="t-number-l num"><?= e(money($balance)) ?></span><span class="t-body-s"><?= e($c['name']) ?></span></div>
      <?= Ui::field('amount', ['label' => t('cust.amount'), 'icon' => 'cash', 'value' => $balance > 0 ? money($balance) : '', 'attrs' => ['inputmode' => 'decimal', 'required' => true, 'autofocus' => true]]) ?>
      <div class="opts opts--3">
        <?= Ui::opt(t('cust.m_cash'), t('cust.m_cash_s'), 'cash', true, 'method', 'cash') ?>
        <?= Ui::opt(t('cust.m_card'), t('cust.m_card_s'), 'credit-card', false, 'method', 'card') ?>
        <?= Ui::opt(t('cust.m_transfer'), t('cust.m_transfer_s'), 'wallet', false, 'method', 'transfer') ?>
      </div>
      <?= Ui::field('note', ['label' => t('cust.f_note'), 'icon' => 'note']) ?>
      <?php if (!$shift): ?><p class="t-body-s c-muted"><?= e(t('cust.no_shift_hint')) ?></p><?php endif ?>
      <div class="sheet__actions">
        <?= Ui::btn(t('ui.cancel'), ['style' => 'secondary', 'size' => 'l', 'attrs' => ['data-close' => true]]) ?>
        <?= Ui::btn(t('cust.collect_do'), ['style' => 'accent', 'size' => 'l', 'icon' => 'check', 'type' => 'submit']) ?>
      </div>
    </form>
  </div>
</div>
