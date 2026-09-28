<?php
/** Account menu of an online customer (not in Figma): recent orders with their state, and sign-out. @var array $a @var array $orders */
use Sofrexa\Modules\Online\OnlineOrders;
use Sofrexa\View\Ui;
?>
<div class="scrim" data-dyn hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead((string) $a['name']) ?>
    <div class="sheet__body">
      <div class="overline"><?= e(t('on.my_orders')) ?></div>
      <div class="list list--flush">
        <?php foreach ($orders as $o):
            $o['lines'] = \Sofrexa\Modules\Orders\Orders::lines($o['id']);
            $t = OnlineOrders::track($o); ?>
          <?= Ui::lrow(t('on.order_no', ['no' => digits(sprintf('%04d', (int) $o['no']))]), ['icon' => $t['hero'][0], 'href' => '/online/siparis/' . $o['id'],
              'sub' => digits(date('d.m H:i', intdiv((int) $o['opened_at'], 1000))) . ' · ' . $t['hero'][1], 'trail' => money((int) $o['total'])]) ?>
        <?php endforeach ?>
      </div>
      <?php if (!$orders): ?><div class="empty"><?= e(t('on.no_orders')) ?></div><?php endif ?>
      <?= Ui::btn(t('on.sign_out'), ['style' => 'secondary', 'size' => 'l', 'block' => true, 'icon' => 'arrow-right', 'attrs' => ['data-post' => '/online/cikis']]) ?>
    </div>
  </div>
</div>
