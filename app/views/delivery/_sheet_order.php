<?php
/** Actions for a takeaway / delivery order on the board. @var array $o (with 'stage') @var array $couriers */
use Sofrexa\Modules\Orders\Board;
use Sofrexa\View\{OrderUi, Ui};

$d = $o['delivery'];
$base = '/delivery/' . $o['id'];
$stage = $o['stage'];
$isDelivery = $o['channel'] === 'delivery';
?>
<div class="scrim" data-dyn hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead(Board::title($o)) ?>
    <div class="sheet__body">
      <?php if ($o['customer_name'] || !empty($d['address'])): ?>
        <div class="row gap-10"><?= icon('map-pin', 20) ?><div class="grow col gap-2"><span class="t-label-m"><?= e(($o['customer_name'] ?? '') . (!empty($d['phone']) ? ' · ' . $d['phone'] : '')) ?></span><?php if (!empty($d['address'])): ?><span class="t-body-s c-secondary"><?= e($d['address']) ?></span><?php endif ?><?php if (!empty($d['note'])): ?><span class="t-body-s c-muted"><?= e($d['note']) ?></span><?php endif ?></div></div>
      <?php endif ?>
      <div class="bill__lines">
        <?php foreach (OrderUi::sorted($o['lines']) as $l): ?><?= OrderUi::line($l, true) ?><?php endforeach ?>
      </div>
      <div class="row between t-heading-m"><span><?= e(t('pay.total')) ?></span><span class="num"><?= e(money((int) $o['total'] - (int) $o['paid'])) ?></span></div>

      <?php if ($stage === 'pending'): ?>
        <div class="sheet__actions">
          <?= Ui::btn(t('deliv.a_reject'), ['style' => 'danger', 'size' => 'l', 'icon' => 'close', 'attrs' => ['data-post' => $base . '/move/reject', 'data-confirm' => t('js.confirm')]]) ?>
          <?= Ui::btn(t('deliv.a_approve'), ['size' => 'l', 'icon' => 'check', 'attrs' => ['data-post' => $base . '/move/approve']]) ?>
        </div>
      <?php else: ?>
        <?php if ($isDelivery && in_array($stage, ['kitchen', 'ready'], true)): ?>
          <form method="post" action="<?= e($base) ?>/courier" data-ajax data-toast="off" class="col gap-8">
            <?= csrf_field() ?>
            <div class="overline"><?= e(t('deliv.courier')) ?></div>
            <div class="couropts">
              <?php foreach ($couriers as $c): ?><?= Ui::opt(first_name($c['name']), $c['out'] ? t('deliv.c_way') : t('deliv.c_free'), 'bike', ($d['courier_id'] ?? '') === $c['id'], 'courier_id', $c['id']) ?><?php endforeach ?>
            </div>
            <?= Ui::btn(t('deliv.a_courier'), ['type' => 'submit', 'style' => 'secondary', 'size' => 'm', 'icon' => 'check']) ?>
          </form>
        <?php endif ?>
        <div class="list list--flush">
          <?php if ($stage === 'kitchen'): ?><?= Ui::lrow(t('deliv.a_ready'), ['icon' => 'bell', 'attrs' => ['data-post' => $base . '/move/ready', 'data-action' => 'ready']]) ?><?php endif ?>
          <?php if ($isDelivery && in_array($stage, ['kitchen', 'ready'], true)): ?><?= Ui::lrow(t('deliv.a_out'), ['icon' => 'bike', 'attrs' => ['data-post' => $base . '/move/way', 'data-action' => 'way']]) ?><?php endif ?>
          <?php if ($isDelivery && $stage === 'way'): ?><?= Ui::lrow(t('deliv.a_done'), ['icon' => 'check-circle', 'attrs' => ['data-post' => $base . '/move/done', 'data-action' => 'done']]) ?><?php endif ?>
          <?php if (!$isDelivery || !in_array($stage, ['way', 'done'], true)): ?><?= Ui::lrow(t('deliv.a_pay'), ['icon' => 'cash', 'href' => '/cashier/pay/' . $o['id']]) ?><?php endif ?>
          <?php if ($isDelivery): ?><?= Ui::lrow(t('deliv.a_ticket'), ['icon' => 'printer', 'attrs' => ['data-post' => $base . '/slip', 'data-action' => 'slip']]) ?><?php endif ?>
          <?= Ui::lrow(t('deliv.a_edit'), ['icon' => 'receipt', 'href' => '/orders/' . $o['id']]) ?>
          <?php if (can('orders.void') && (int) $o['paid'] === 0): ?><?= Ui::lrow(t('deliv.a_cancel'), ['icon' => 'x-circle', 'attrs' => ['data-post' => $base . '/move/reject', 'data-confirm' => t('js.confirm'), 'data-action' => 'cancel']]) ?><?php endif ?>
        </div>
      <?php endif ?>
    </div>
  </div>
</div>
