<?php
/**
 * Cash in / out sheet — Figma C11 (85:1556): amount and currency, reason chips, description, optional receipt photo.
 * @var string $kind in|out @var array $currencies
 */
use Sofrexa\Core\Money;
use Sofrexa\View\Ui;

$reasons = [
    'out' => ['moves.r_market', 'moves.r_supplier', 'moves.r_courier', 'moves.r_coins', 'moves.r_other'],
    'in' => ['moves.r_coins', 'moves.r_bank', 'moves.r_topup', 'moves.r_other'],
];
?>
<div class="scrim" data-dyn hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead(t('moves.sheet')) ?>
    <form class="sheet__body" method="post" action="/cashier/moves" enctype="multipart/form-data" data-ajax data-toast="off" data-move-form>
      <?= csrf_field() ?>
      <?= Ui::segs(['in' => t('moves.in'), 'out' => t('moves.out')], $kind, 'kind') ?>
      <div class="amountbox">
        <input class="amountbox__input t-number-xl num" name="amount" inputmode="decimal" autocomplete="off" placeholder="₺0" aria-label="<?= e(t('moves.c_amount')) ?>" autofocus>
        <div class="chips center">
          <?php foreach ($currencies as $i => $c): ?><label class="chip chip--s"><input type="radio" name="currency" value="<?= e($c) ?>"<?= $i === 0 ? ' checked' : '' ?> data-sym="<?= e(Money::symbol($c)) ?>"><?= e($c === 'TRY' ? '₺ TL' : Money::symbol($c)) ?></label><?php endforeach ?>
        </div>
      </div>
      <div class="overline"><?= e(t('moves.reason')) ?></div>
      <?php foreach ($reasons as $k => $list): ?>
        <div class="chips chips--wrap" data-reasons="<?= $k ?>"<?= $k !== $kind ? ' hidden' : '' ?>>
          <?php foreach ($list as $i => $r): ?><label class="chip"><input type="radio" name="reason" value="<?= e(t($r)) ?>"<?= $i === 0 && $k === $kind ? ' checked' : '' ?><?= $k !== $kind ? ' disabled' : '' ?>><?= e(t($r)) ?></label><?php endforeach ?>
        </div>
      <?php endforeach ?>
      <?= Ui::field('note', ['label' => t('moves.note'), 'icon' => 'note']) ?>
      <label class="lrow lrow--file">
        <span class="lrow__lead"><?= icon('image', 20) ?></span>
        <span class="lrow__mid"><span class="lrow__title"><?= e(t('moves.photo')) ?></span><span class="lrow__sub"><?= e(t('moves.photo_s')) ?></span></span>
        <span class="lrow__trail" data-photo-state data-added="<?= e(t('moves.photo_added')) ?>"><?= e(t('moves.photo_add')) ?></span>
        <span class="lrow__chev"><?= icon('chevron-right', 20) ?></span>
        <input type="file" name="photo" accept="image/*,application/pdf" capture="environment" class="sr">
      </label>
      <div class="sheet__actions">
        <?= Ui::btn(t('ui.cancel'), ['style' => 'secondary', 'size' => 'l', 'attrs' => ['data-close' => true]]) ?>
        <?= Ui::btn(t($kind === 'in' ? 'moves.save_in' : 'moves.save_out'), ['type' => 'submit', 'size' => 'l', 'icon' => 'check', 'attrs' => ['data-save-label' => json_encode(['in' => t('moves.save_in'), 'out' => t('moves.save_out')], JSON_UNESCAPED_UNICODE)]]) ?>
      </div>
    </form>
  </div>
</div>
