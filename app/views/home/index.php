<?php
/** Temporary overview until the manager dashboard (R1/R2) is built in the reports stage. */
use Sofrexa\View\Ui;
?>
<div class="list">
  <?php foreach (\Sofrexa\View\Shell::navItems() as $key => [$label, $icon, $href]): if ($key === 'dashboard') continue; ?>
    <?= Ui::lrow($label, ['icon' => $icon, 'href' => $href]) ?>
  <?php endforeach ?>
</div>
