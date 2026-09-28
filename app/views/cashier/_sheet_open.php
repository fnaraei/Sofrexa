<?php
/** Open the shift: opening cash per currency (lira in the drawer, foreign cash if any). @var array $currencies */
use Sofrexa\Core\Money;
use Sofrexa\View\Ui;
?>
<div class="scrim" data-dyn hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead(t('shift.open_title')) ?>
    <form class="sheet__body" method="post" action="/cashier/shift/open" data-ajax data-toast="off">
      <?= csrf_field() ?>
      <p class="t-body-m c-muted"><?= e(t('shift.open_help')) ?></p>
      <?php foreach ($currencies as $c): ?>
        <?= Ui::field('open_' . $c, ['label' => t('moves.k_open') . ' · ' . ($c === 'TRY' ? 'TL' : $c), 'icon' => 'cash', 'suffix' => Money::symbol($c), 'attrs' => ['inputmode' => 'decimal', 'autofocus' => $c === 'TRY']]) ?>
      <?php endforeach ?>
      <?php if (can('cash.rates')): ?><?= Ui::btn(t('cash.rates'), ['style' => 'ghost', 'size' => 's', 'icon' => 'currency', 'href' => '/cashier/rates']) ?><?php endif ?>
      <div class="sheet__actions"><?= Ui::btn(t('ui.cancel'), ['style' => 'secondary', 'size' => 'l', 'attrs' => ['data-close' => true]]) ?><?= Ui::btn(t('shift.open_btn'), ['type' => 'submit', 'size' => 'l', 'icon' => 'lock']) ?></div>
    </form>
  </div>
</div>
