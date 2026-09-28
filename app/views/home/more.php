<?php
/** Phone "More" tab. @var array $items */
use Sofrexa\View\Ui;

$u = user();
?>
<div class="card">
  <?= Ui::who($u['name'], t('role.' . $u['role_code']), 'm') ?>
</div>
<div class="list">
  <?php foreach ($items as $key => [$label, $icon, $href]): ?>
    <?= Ui::lrow($label, ['icon' => $icon, 'href' => $href]) ?>
  <?php endforeach ?>
</div>
<?php if (can('customers.manage')): ?>
<div class="list"><?= Ui::lrow(t('loy.title'), ['icon' => 'star', 'href' => '/customers/loyalty']) ?></div>
<?php endif ?>
<?php if (can('cash.pay')): ?>
<div class="list">
  <?php if (can('cash.moves')): ?><?= Ui::lrow(t('cash.moves'), ['icon' => 'wallet', 'href' => '/cashier/moves']) ?><?php endif ?>
  <?php if (can('cash.shift')): ?><?= Ui::lrow(t('cash.close_shift'), ['icon' => 'lock', 'href' => '/cashier/shift']) ?><?php endif ?>
  <?php if (can('cash.rates')): ?><?= Ui::lrow(t('cash.rates'), ['icon' => 'currency', 'href' => '/cashier/rates']) ?><?php endif ?>
</div>
<?php endif ?>
<form method="post" action="/logout"><?= csrf_field() ?><?= Ui::btn(t('ui.logout'), ['type' => 'submit', 'style' => 'secondary', 'icon' => 'logout', 'block' => true]) ?></form>
