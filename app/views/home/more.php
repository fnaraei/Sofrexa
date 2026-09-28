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
<form method="post" action="/logout"><?= csrf_field() ?><?= Ui::btn(t('ui.logout'), ['type' => 'submit', 'style' => 'secondary', 'icon' => 'logout', 'block' => true]) ?></form>
