<?php
/** The bill on the phone (C3 › receipt icon): lines, discount, note and totals of the C2 left panel. @var array $o @var string $discountText @var array $vat */
use Sofrexa\Modules\Orders\Board;
use Sofrexa\View\Ui;
?>
<div class="scrim" data-dyn hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead(Board::title($o)) ?>
    <div class="sheet__body bill--sheet">
      <?= \Sofrexa\Core\View::partial('cashier/_bill', ['o' => $o, 'discountText' => $discountText, 'vat' => $vat, 'sheet' => true]) ?>
    </div>
  </div>
</div>
