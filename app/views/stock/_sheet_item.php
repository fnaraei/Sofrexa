<?php
/**
 * Stock item sheet (add / edit) with its recent moves. Not a separate Figma frame: built from the Sheet,
 * Input, Segment and ListRow components. @var ?array $item @var array $suppliers @var array $groups @var array $locations @var array $moves @var string $name
 */
use Sofrexa\Modules\Stock\Stock;
use Sofrexa\View\Ui;

$v = static fn(string $k, $d = '') => $item[$k] ?? $d;
$units = [];
foreach (Stock::UNITS as $u) {
    $units[$u] = Stock::unitLabel($u);
}
$sup = ['' => '—'];
foreach ($suppliers as $s) {
    $sup[$s['id']] = $s['name'];
}
$reason = static function (string $r): string {
    $base = explode(':', $r)[0];
    return t('stock.r.' . (in_array($base, ['purchase', 'sale', 'void', 'waste', 'return', 'count'], true) ? $base : 'count')) . (str_contains($r, ':') ? ' · ' . substr($r, strpos($r, ':') + 1) : '');
};
?>
<div class="scrim" data-dyn hidden>
  <div class="sheet sheet--wide" role="dialog" aria-modal="true">
    <?= Ui::sheetHead($item ? $item['name'] : t('stock.new_item')) ?>
    <form class="sheet__body" method="post" action="/stock/items/save" data-ajax data-toast="off" data-stock-item>
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= e($v('id')) ?>">
      <input type="hidden" name="reload" value="1">
      <?php if ($item): ?>
        <div class="stockhead"><span class="t-number-l num <?= ['critical' => 'c-danger', 'low' => 'c-warning', 'ok' => 'c-primary'][Stock::state($item['on_hand'], (float) $item['min_qty'])] ?>"><?= e(Stock::qty($item['on_hand']) . ' ' . Stock::unitLabel($item['unit'])) ?></span><span class="t-body-s c-muted"><?= e(money((int) round((float) $item['avg_cost'])) . ' / ' . Stock::unitLabel($item['unit'])) ?></span></div>
      <?php endif ?>
      <?= Ui::segs(['raw' => t('stock.kind.raw'), 'semi' => t('stock.kind.semi')], (string) $v('kind', 'raw'), 'kind') ?>
      <?= Ui::field('name', ['label' => t('stock.f_name'), 'icon' => 'box', 'value' => (string) ($v('name') ?: $name), 'attrs' => ['required' => true, 'autofocus' => !$item]]) ?>
      <div class="grid2">
        <?= Ui::select('unit', $units, (string) $v('unit', 'kg'), ['label' => t('stock.f_unit'), 'icon' => 'scale']) ?>
        <?= Ui::field('category', ['label' => t('stock.f_group'), 'icon' => 'tag', 'value' => (string) $v('category'), 'attrs' => ['list' => 'stock-groups']]) ?>
        <?= Ui::field('min_qty', ['label' => t('stock.f_min'), 'icon' => 'alert', 'value' => $item ? Stock::qty((float) $item['min_qty']) : '', 'attrs' => ['inputmode' => 'decimal']]) ?>
        <?= Ui::field('location', ['label' => t('stock.f_location'), 'icon' => 'map-pin', 'value' => (string) $v('location'), 'attrs' => ['list' => 'stock-locations']]) ?>
        <?= Ui::select('supplier_id', $sup, (string) $v('supplier_id'), ['label' => t('stock.f_supplier'), 'icon' => 'store']) ?>
        <?= Ui::field('vat_rate', ['label' => t('stock.f_vat'), 'icon' => 'percent', 'value' => $item ? \Sofrexa\Core\I18n::num((float) $item['vat_rate']) : '10', 'attrs' => ['inputmode' => 'decimal']]) ?>
      </div>
      <?php if (!$item): ?><?= Ui::field('avg_cost', ['label' => t('stock.f_cost'), 'icon' => 'wallet', 'suffix' => '₺', 'attrs' => ['inputmode' => 'decimal']]) ?><?php endif ?>
      <datalist id="stock-groups"><?php foreach ($groups as $g): ?><option value="<?= e($g) ?>"><?php endforeach ?></datalist>
      <datalist id="stock-locations"><?php foreach ($locations as $l): ?><option value="<?= e($l) ?>"><?php endforeach ?></datalist>
      <?php if ($moves): ?>
        <div class="overline"><?= e(t('stock.moves')) ?></div>
        <div class="picklist">
          <?php foreach ($moves as $m): ?>
            <div class="pickrow"><span class="grow col gap-2"><span class="t-label-m"><?= e($reason((string) $m['reason'])) ?><?= $m['supplier_name'] ? ' · ' . e($m['supplier_name']) : '' ?></span><span class="t-body-s c-muted"><?= e(when_label((int) $m['at']) . ($m['user_name'] ? ' · ' . first_name($m['user_name']) : '')) ?></span></span><span class="t-label-m num <?= (float) $m['qty'] < 0 ? 'c-danger' : 'c-success' ?>"><?= e(((float) $m['qty'] > 0 ? '+' : '−') . Stock::qty(abs((float) $m['qty'])) . ' ' . Stock::unitLabel($item['unit'])) ?></span></div>
          <?php endforeach ?>
        </div>
      <?php endif ?>
      <div class="sheet__actions">
        <?php if ($item): ?>
          <?= Ui::ibtn('trash', t('ui.delete'), ['style' => 'secondary', 'size' => 'l', 'attrs' => ['data-post' => '/stock/items/' . $item['id'] . '/delete', 'data-confirm' => t('stock.delete_confirm')]]) ?>
          <?php if ($item['kind'] === 'semi'): ?><?= Ui::btn(t('stock.recipe_btn'), ['style' => 'secondary', 'size' => 'l', 'icon' => 'layers', 'href' => '/stock/recipe/stock/' . $item['id']]) ?><?php endif ?>
        <?php else: ?>
          <?= Ui::btn(t('ui.cancel'), ['style' => 'secondary', 'size' => 'l', 'attrs' => ['data-close' => true]]) ?>
        <?php endif ?>
        <?= Ui::btn(t('ui.save'), ['type' => 'submit', 'size' => 'l', 'icon' => 'check']) ?>
      </div>
    </form>
  </div>
</div>
