<?php
/** The ticket cards of a screen, oldest first. @var array $tickets */
if (!$tickets): ?>
  <div class="kds__empty"><?= icon('chef-hat', 32) ?><span><?= e(t('kds.empty')) ?></span></div>
<?php endif;
foreach ($tickets as $t) {
    echo \Sofrexa\Core\View::partial('kitchen/_ticket', ['t' => $t]);
}
