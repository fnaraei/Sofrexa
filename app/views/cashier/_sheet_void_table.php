<?php
/**
 * "Masaya ver" — Figma IP3 (133:10508): the open bills a cooked cancelled dish can go to, as radio cards; the button says
 * where it goes and for how much. @var array $v the cancelled line @var int $amount @var array $bills
 */
use Sofrexa\Modules\Orders\Orders;
use Sofrexa\View\Ui;
?>
<div class="scrim" data-dyn hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead(t('voids.table_t', ['name' => $v['name']])) ?>
    <form class="sheet__body voidsheet" method="post" action="/cashier/voids/<?= e($v['id']) ?>/reuse" data-ajax data-toast="off" data-void-form>
      <?= csrf_field() ?>
      <p class="t-body-s c-muted"><?= e(t('voids.table_help')) ?></p>
      <?= Ui::field('q', ['type' => 'search', 'icon' => 'search', 'placeholder' => t('voids.search_bill'), 'attrs' => ['data-void-search' => true]]) ?>
      <div class="overline"><?= e(t('voids.bills')) ?></div>
      <div class="optlist">
        <?php foreach ($bills as $b):
            $where = Orders::where($b) . ($b['area_names'] ? ' · ' . tn($b['area_names']) : '');
            $subText = in_array($b['channel'], ['table', 'qr'], true)
                ? t('voids.bill_sub', ['n' => digits(max(1, (int) $b['guests'])), 'waiter' => first_name((string) $b['waiter_name']), 'dur' => dur((int) $b['opened_at'])])
                : implode(' · ', array_filter([(string) $b['label'], first_name((string) $b['waiter_name'])]));
            $search = mb_strtolower($where . ' ' . $subText . ' ' . $b['no'], 'UTF-8'); ?>
          <label class="optrow" data-void-row="<?= e($search) ?>">
            <input type="radio" name="order_id" value="<?= e($b['id']) ?>" data-void-pick data-label="<?= e(t('voids.table_btn', ['where' => Orders::where($b), 'amount' => money($amount)])) ?>">
            <span class="optrow__radio"></span>
            <span class="grow col gap-2"><span class="t-label-l ellipsis"><?= e($where) ?></span><span class="t-body-s c-muted ellipsis"><?= e($subText) ?></span></span>
            <span class="t-label-l num"><?= e(money((int) $b['total'])) ?></span>
          </label>
        <?php endforeach ?>
        <?php if (!$bills): ?><div class="empty"><?= e(t('voids.table_none')) ?></div><?php endif ?>
      </div>
      <div class="voidsheet__foot">
        <?= Ui::btn(t('voids.pick'), ['type' => 'submit', 'size' => 'l', 'block' => true, 'icon' => 'check', 'attrs' => ['data-void-submit' => true, 'data-empty' => t('voids.pick'), 'disabled' => true]]) ?>
      </div>
    </form>
  </div>
</div>
