<?php
/** Couriers panel — Figma C5 right column: today's count, out or free, cash on them, settle. @var array $couriers */
use Sofrexa\Modules\Orders\Delivery;
use Sofrexa\View\Ui;
?>
<div class="couriers">
  <h2 class="t-heading-l"><?= e(t('deliv.couriers')) ?></h2>
  <?php if (!$couriers): ?><p class="t-body-s c-muted"><?= e(t('deliv.no_couriers')) ?></p><?php endif ?>
  <?php foreach ($couriers as $c): ?>
    <div class="ccard">
      <div class="row gap-10">
        <?= Ui::avatar($c['name']) ?>
        <div class="grow col" style="gap:0"><span class="t-label-l ellipsis"><?= e($c['name']) ?></span><span class="t-body-s c-muted"><?= e(t('deliv.c_today', ['n' => digits($c['today'])])) ?></span></div>
        <?= $c['out'] ? Ui::badge(t('deliv.c_way'), 'warning', true) : Ui::badge(t('deliv.c_free'), 'success', true) ?>
      </div>
      <div class="row between t-label-m"><span class="c-secondary"><?= e(t('deliv.c_cash')) ?></span><span class="num"><?= e(Delivery::cashText($c['cash'])) ?></span></div>
      <?= Ui::btn(t('deliv.c_settle'), ['style' => 'secondary', 'size' => 's', 'block' => true, 'icon' => 'wallet', 'attrs' => ['data-load-sheet' => '/delivery/couriers/' . $c['id'] . '/settle', 'disabled' => !$c['orders']]]) ?>
    </div>
  <?php endforeach ?>
</div>
