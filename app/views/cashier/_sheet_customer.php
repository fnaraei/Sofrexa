<?php
/**
 * Customer of the bill: search by name or phone, register a new one (CU6 sheet, "Kaydet ve hesaba ekle"),
 * or take the customer off. Not a separate Figma frame: Sheet, Input (search) and ListRow components.
 * @var array $o @var ?array $customer
 */
use Sofrexa\Core\I18n;
use Sofrexa\Modules\Customers\Loyalty;
use Sofrexa\View\Ui;

$tier = $customer ? Loyalty::tierOf($customer['id']) : null;
?>
<div class="scrim" data-dyn hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead(t('pay.cust_t')) ?>
    <div class="sheet__body" data-cust-pick data-order="<?= e($o['id']) ?>">
      <?php if ($customer): ?>
        <div class="selcard">
          <?= Ui::who($customer['name'], implode(' · ', array_filter([(string) $customer['phone'], $tier['name'] ?? '', t('loy.points_n', ['n' => digits(I18n::num(Loyalty::balance($customer['id'])))])]))) ?>
          <span class="grow"></span>
          <?= Ui::btn(t('pay.cust_remove'), ['style' => 'ghost', 'size' => 's', 'icon' => 'close', 'attrs' => ['data-post' => '/cashier/pay/' . $o['id'] . '/customer', 'data-body' => '{"customer_id":""}']]) ?>
        </div>
      <?php endif ?>
      <?= Ui::field('q', ['type' => 'search', 'icon' => 'search', 'placeholder' => t('pay.cust_find'), 'attrs' => ['data-cust-q' => true, 'autofocus' => true]]) ?>
      <div class="picklist" data-cust-results></div>
      <?= Ui::btn(t('cust.new'), ['style' => 'secondary', 'icon' => 'user-plus', 'block' => true, 'attrs' => ['data-cust-new' => '/customers/new/sheet/edit?order=' . rawurlencode($o['id'])]]) ?>
    </div>
  </div>
</div>
