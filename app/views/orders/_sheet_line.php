<?php
/**
 * Line sheet: quantity and note of an unsent line, or void of a sent one (reason required, logged, cancel ticket).
 * @var array $l
 */
use Sofrexa\Modules\Orders\Board;
use Sofrexa\View\Ui;

$isNew = $l['status'] === 'new';
$q = (float) $l['qty'];
$mods = Board::lineMods($l);
?>
<div class="scrim" data-dyn hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead($isNew ? $l['name'] : t('line.void_t')) ?>
    <?php if ($isNew): ?>
      <form class="sheet__body" data-line-form="<?= e($l['id']) ?>">
        <?php if ($mods !== ''): ?><p class="t-body-m c-muted"><?= e($mods) ?></p><?php endif ?>
        <?= Ui::field('note', ['label' => t('opt.note'), 'icon' => 'note', 'value' => (string) $l['note']]) ?>
        <div class="optsheet__actions optsheet__actions--flush">
          <div class="qty qty--l" data-stepper><button type="button" data-step="-1" aria-label="−"><?= icon('minus', 20) ?></button><span class="qty__n num" data-stepper-n><?= e(digits(Sofrexa\Modules\Orders\Orders::qtyText($q))) ?></span><button type="button" data-step="1" aria-label="+"><?= icon('plus', 20) ?></button><input type="hidden" name="qty" value="<?= e((string) $q) ?>" data-stepper-v data-min="0" data-max="99"></div>
          <?= Ui::btn(t('line.save'), ['type' => 'submit', 'size' => 'l', 'icon' => 'check', 'class' => 'grow']) ?>
        </div>
        <?= Ui::btn(t('line.remove'), ['style' => 'ghost', 'size' => 'm', 'icon' => 'trash', 'class' => 'btn--danger-text', 'attrs' => ['data-line-remove' => $l['id']]]) ?>
      </form>
    <?php elseif (!can('orders.void')): ?>
      <div class="sheet__body"><?= Ui::banner(t('err.forbidden'), t('line.void_help'), 'warning', 'lock') ?></div>
    <?php else: ?>
      <form class="sheet__body" data-void-form="<?= e($l['id']) ?>">
        <div class="row gap-12"><span class="oline__qty num"><?= e(Board::qty($q)) ?></span><div class="grow col gap-2"><span class="t-label-l"><?= e($l['name']) ?></span><?php if ($mods !== ''): ?><span class="t-body-s c-muted"><?= e($mods) ?></span><?php endif ?></div><span class="t-label-l num"><?= e(money(Board::lineTotal($l))) ?></span></div>
        <p class="t-body-s c-muted"><?= e(t('line.void_help')) ?></p>
        <?php if ($q > 1): ?>
          <div class="row between"><span class="t-label-m c-secondary"><?= e(t('line.void_qty')) ?></span>
            <div class="qty" data-stepper><button type="button" data-step="-1" aria-label="−"><?= icon('minus', 18) ?></button><span class="qty__n num" data-stepper-n><?= e(digits(Sofrexa\Modules\Orders\Orders::qtyText($q))) ?></span><button type="button" data-step="1" aria-label="+"><?= icon('plus', 18) ?></button><input type="hidden" name="qty" value="<?= e((string) $q) ?>" data-stepper-v data-min="1" data-max="<?= e((string) $q) ?>"></div>
          </div>
        <?php else: ?><input type="hidden" name="qty" value="<?= e((string) $q) ?>"><?php endif ?>
        <div class="chips chips--wrap" data-fill="reason">
          <?php foreach (['line.r_changed', 'line.r_wrong', 'line.r_late', 'line.r_bad'] as $k): ?><?= Ui::chip(t($k), false, null, ['data-value' => t($k)]) ?><?php endforeach ?>
        </div>
        <?= Ui::field('reason', ['label' => t('line.reason'), 'icon' => 'note']) ?>
        <div class="sheet__actions"><?= Ui::btn(t('ui.cancel'), ['style' => 'secondary', 'size' => 'l', 'attrs' => ['data-close' => true]]) ?><?= Ui::btn(t('line.void'), ['type' => 'submit', 'style' => 'danger', 'size' => 'l', 'icon' => 'x-circle']) ?></div>
      </form>
    <?php endif ?>
  </div>
</div>
