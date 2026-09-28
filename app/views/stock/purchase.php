<?php
/**
 * Goods in — Figma S2 (38:341): supplier, invoice number, date, payment; invoice lines with editable quantity
 * and unit price; add a line by name; note and totals. Lines live in the page until "Stoğa ekle".
 * @var array $suppliers
 */
use Sofrexa\View\Ui;

$noHead = true;
$back = '/stock';
$appTitle = t('pur.title');
$appSub = t('pur.sub');
$bodyClass = 'page-purchase';
$sup = ['' => '—'];
foreach ($suppliers as $s) {
    $sup[$s['id']] = $s['name'];
}
$sup['__new'] = t('pur.supplier_new');
$pay = ['credit:15' => t('pur.pay.credit', ['n' => 15]), 'credit:30' => t('pur.pay.credit', ['n' => 30]), 'cash' => t('pur.pay.cash'), 'card' => t('pur.pay.card'), 'transfer' => t('pur.pay.transfer')];
$bottom = Ui::btn(t('pur.save'), ['size' => 'l', 'icon' => 'check', 'type' => 'submit', 'attrs' => ['form' => 'purchase-form']]);
$str = ['new' => t('pur.new_item', ['q' => '{q}']), 'remove' => t('pur.remove'), 'vat' => t('pur.vat_r', ['r' => '{r}']), 'draft' => t('pur.draft_saved')];
?>
<form class="pur" id="purchase-form" method="post" action="/stock/purchase" data-purchase data-str='<?= e(json_encode($str, JSON_UNESCAPED_UNICODE)) ?>' autocomplete="off">
  <?= csrf_field() ?>
  <div class="page-head page-head--item only-desktop">
    <?= Ui::ibtn('arrow-left', t('ui.back'), ['style' => 'secondary', 'href' => '/stock', 'class' => 'ibtn--flip']) ?>
    <div class="page-head__titles"><h1 class="t-heading-xl"><?= e(t('pur.title')) ?></h1><p class="t-body-m c-muted"><?= e(t('pur.sub')) ?></p></div>
    <?= Ui::badge(t('pur.draft'), 'neutral', true) ?>
  </div>
  <section class="card purhead">
    <?= Ui::select('supplier_id', $sup, '', ['label' => t('pur.supplier'), 'icon' => 'store', 'attrs' => ['data-supplier' => true]]) ?>
    <?= Ui::field('doc_no', ['label' => t('pur.doc_no'), 'icon' => 'file-text']) ?>
    <?= Ui::field('day', ['label' => t('pur.date'), 'icon' => 'calendar', 'type' => 'date', 'value' => date('Y-m-d')]) ?>
    <?= Ui::select('pay', $pay, 'credit:15', ['label' => t('pur.pay'), 'icon' => 'wallet']) ?>
  </section>
  <section class="card card--pad0 purlines">
    <div class="prow2 prow2--head only-desktop"><span><?= e(t('stock.c_item')) ?></span><span><?= e(t('pur.c_qty')) ?></span><span><?= e(t('stock.c_unit')) ?></span><span><?= e(t('pur.c_price')) ?></span><span><?= e(t('pur.c_vat')) ?></span><span><?= e(t('pur.c_total')) ?></span><span></span></div>
    <div data-lines></div>
    <div class="puradd">
      <div class="field field--search puradd__field"><div class="field__box"><?= icon('plus', 20) ?><input type="search" placeholder="<?= e(t('pur.add')) ?>" data-add-search aria-label="<?= e(t('pur.add')) ?>"></div></div>
      <div class="picklist puradd__results" data-add-results hidden></div>
    </div>
  </section>
  <div class="purfoot">
    <div class="grow"><?= Ui::field('note', ['label' => t('pur.note'), 'icon' => 'note']) ?></div>
    <section class="card sumcard">
      <div class="kv"><span><?= e(t('pur.subtotal')) ?></span><span class="num" data-net>₺0</span></div>
      <div class="kv"><span data-vat-label><?= e(t('pur.vat')) ?></span><span class="num" data-vat>₺0</span></div>
      <div class="kv kv--big t-heading-m"><span><?= e(t('pur.total')) ?></span><span class="num" data-total>₺0</span></div>
      <div class="row gap-8 sumcard__acts only-desktop">
        <?= Ui::btn(t('pur.draft'), ['style' => 'secondary', 'class' => 'grow', 'attrs' => ['data-draft' => true]]) ?>
        <?= Ui::btn(t('pur.save'), ['type' => 'submit', 'icon' => 'check', 'class' => 'grow']) ?>
      </div>
    </section>
  </div>
</form>
