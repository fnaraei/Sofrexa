<?php
/**
 * Prices and stock — Figma M7 (80:658, desktop table) and M8 (80:1274, phone list with Fiyat/Stok segments).
 * Edits stay a preview (highlighted cells) until one save; quick.js collects the changes.
 * @var array $categories @var array $items @var string $cat @var string $q @var string $mode
 */
use Sofrexa\Core\I18n;
use Sofrexa\View\Ui;

$catName = [];
foreach ($categories as $c) {
    $catName[$c['id']] = tn($c['names']);
}
$allCount = array_sum(array_column($categories, 'item_count'));
$appSub = t('quick.sub_m', ['cat' => $cat !== '' ? ($catName[$cat] ?? '') : t('menu.all'), 'n' => digits(count($items))]);
$headActions = Ui::btn(t('ui.cancel'), ['style' => 'secondary', 'attrs' => ['data-quick-reset' => true]])
    . Ui::btn(t('quick.save'), ['icon' => 'check', 'attrs' => ['data-quick-save' => true, 'disabled' => true]]);
$appActions = [Ui::ibtn('search', t('ui.search'), ['class' => 'appbar__act', 'attrs' => ['data-sheet' => 'quick-search']])];
$bottom = Ui::btn(t('ui.cancel'), ['style' => 'secondary', 'size' => 'l', 'attrs' => ['data-quick-reset' => true]])
    . Ui::btn(t('quick.save'), ['size' => 'l', 'icon' => 'check', 'attrs' => ['data-quick-save' => true, 'disabled' => true]]);
$keep = static fn(array $x): string => url('/menu/quick', array_filter(['c' => $cat, 'q' => $q, 'm' => $mode === 'stock' ? 'stock' : null] + $x, static fn($v) => $v !== null && $v !== ''));
$rows = array_map(static fn(array $i): array => [
    'id' => $i['id'], 'name' => tn($i['names']), 'cat' => $i['category_id'], 'price' => (int) $i['price'],
    'stock' => $i['daily_stock'] === null ? null : (int) $i['daily_stock'], 'sold' => \Sofrexa\Modules\Menu\Menu::left((float) $i['sold_today']), 'on' => (bool) $i['available'],
], $items);
$fmtStock = static fn(?int $s): string => $s === null ? '—' : digits($s);
?>
<?= \Sofrexa\Core\View::partial('menu/_tabs', ['active' => '/menu/quick']) ?>
<div class="quick" data-quick data-rows='<?= e(json_encode($rows, JSON_UNESCAPED_UNICODE)) ?>'
     data-l-save="<?= e(t('quick.save_n', ['n' => '{n}'])) ?>" data-l-save0="<?= e(t('quick.save')) ?>" data-l-unlimited="<?= e(t('quick.unlimited')) ?>"
     data-l-on="<?= e(t('quick.s_on')) ?>" data-l-low="<?= e(t('quick.s_low')) ?>" data-l-out="<?= e(t('quick.s_out')) ?>" data-l-off="<?= e(t('quick.s_off')) ?>"
     data-l-old="<?= e(t('quick.old', ['price' => '{price}'])) ?>" data-l-line="<?= e(t('quick.bulk_line')) ?>" data-l-mline="<?= e(t('quick.bulk_m')) ?>" data-l-msub="<?= e(t('quick.bulk_m_sub')) ?>"
     data-l-up="<?= e(t('quick.up')) ?>" data-l-down="<?= e(t('quick.down')) ?>" data-l-all="<?= e(t('menu.all')) ?>" data-l-leave="<?= e(t('quick.leave')) ?>">

  <div class="segs only-mobile">
    <a class="seg<?= $mode === 'price' ? ' is-active' : '' ?>" href="<?= e($keep(['m' => null])) ?>"><?= e(t('quick.price')) ?></a>
    <a class="seg<?= $mode === 'stock' ? ' is-active' : '' ?>" href="<?= e($keep(['m' => 'stock'])) ?>"><?= e(t('quick.stock')) ?></a>
  </div>

  <form class="toolbar only-desktop" method="get" action="/menu/quick">
    <?php if ($cat !== ''): ?><input type="hidden" name="c" value="<?= e($cat) ?>"><?php endif ?>
    <?= Ui::field('q', ['type' => 'search', 'icon' => 'search', 'value' => $q, 'placeholder' => t('menu.search'), 'class' => 'toolbar__search toolbar__search--280']) ?>
    <div class="chips">
      <?= Ui::chip(t('menu.all'), $cat === '', digits($allCount), ['href' => $keep(['c' => null])]) ?>
      <?php foreach ($categories as $c): if (!$c['item_count']) continue; ?>
        <?= Ui::chip(tn($c['names']), $cat === $c['id'], digits($c['item_count']), ['href' => $keep(['c' => $c['id']])]) ?>
      <?php endforeach ?>
    </div>
  </form>
  <div class="chips only-mobile">
    <?= Ui::chip(t('menu.all'), $cat === '', null, ['href' => $keep(['c' => null])]) ?>
    <?php foreach ($categories as $c): if (!$c['item_count']) continue; ?>
      <?= Ui::chip(tn($c['names']), $cat === $c['id'], null, ['href' => $keep(['c' => $c['id']])]) ?>
    <?php endforeach ?>
  </div>

  <div class="bulkbar<?= $mode === 'stock' ? ' only-desktop' : '' ?>" data-bulkbar>
    <?= icon('percent', 22) ?>
    <div class="col grow" style="gap:0;min-width:0">
      <span class="t-label-l c-primary only-desktop" data-bulk-title data-idle="<?= e(t('quick.bulk_idle')) ?>" data-active="<?= e(t('quick.bulk_title')) ?>"><?= e(t('quick.bulk_idle')) ?></span>
      <span class="t-body-s c-secondary only-desktop" data-bulk-line><?= e(t('quick.bulk_idle_sub')) ?></span>
      <span class="t-label-l c-primary only-mobile" data-bulk-mtitle><?= e(t('quick.bulk_idle')) ?></span>
      <span class="t-body-s c-secondary only-mobile" data-bulk-msub><?= e(t('quick.bulk_idle_sub')) ?></span>
    </div>
    <label class="pick only-desktop"><span><?= e(t('quick.k_cat')) ?></span>
      <select data-bulk="cat"><option value=""><?= e(t('menu.all')) ?></option><?php foreach ($categories as $c): if (!$c['item_count']) continue; ?><option value="<?= e($c['id']) ?>"<?= $cat === $c['id'] ? ' selected' : '' ?>><?= e(tn($c['names'])) ?></option><?php endforeach ?></select><?= icon('chevron-down', 16) ?></label>
    <label class="pick only-desktop"><span><?= e(t('quick.k_change')) ?></span>
      <select data-bulk="pct"><?php foreach ([-20, -15, -10, -5, 0, 5, 10, 15, 20, 25, 30] as $p): ?><option value="<?= $p ?>"<?= $p === 0 ? ' selected' : '' ?>><?= $p > 0 ? '+ ' : ($p < 0 ? '− ' : '') ?>%<?= abs($p) ?></option><?php endforeach ?></select><?= icon('chevron-down', 16) ?></label>
    <label class="pick only-desktop"><span><?= e(t('quick.k_round')) ?></span>
      <select data-bulk="step"><?php foreach ([100 => '₺1', 500 => '₺5', 1000 => '₺10', 5000 => '₺50'] as $v => $l): ?><option value="<?= $v ?>"<?= $v === 1000 ? ' selected' : '' ?>><?= $l ?></option><?php endforeach ?></select><?= icon('chevron-down', 16) ?></label>
    <span class="only-desktop"><?= Ui::btn(t('quick.undo'), ['style' => 'ghost', 'size' => 's', 'icon' => 'undo', 'attrs' => ['data-bulk-undo' => true]]) ?></span>
    <span class="only-mobile"><?= Ui::btn(t('quick.bulk'), ['style' => 'secondary', 'size' => 's', 'icon' => 'pencil', 'attrs' => ['data-sheet' => 'bulk-sheet']]) ?></span>
  </div>

  <?php if (!$items): ?>
    <div class="empty"><?= e(t('menu.empty')) ?></div>
  <?php else: ?>
  <div class="dtable dtable--dense dtable--quick only-desktop">
    <table>
      <thead><tr>
        <th><?= e(t('menu.col_item')) ?></th>
        <th style="width:180px"><?= e(t('menu.col_category')) ?></th>
        <th class="right" style="width:90px"><?= e(t('quick.col_old')) ?></th>
        <th class="right" style="width:110px"><?= e(t('quick.col_new')) ?></th>
        <th class="right" style="width:70px"><?= e(t('quick.col_diff')) ?></th>
        <th style="width:100px;text-align:center"><?= e(t('quick.col_stock')) ?></th>
        <th class="right" style="width:70px"><?= e(t('quick.col_left')) ?></th>
        <th style="width:110px"><?= e(t('quick.col_status')) ?></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr data-row="<?= e($r['id']) ?>">
          <td class="t-label-m c-primary"><?= e($r['name']) ?></td>
          <td><?= e($catName[$r['cat']] ?? '') ?></td>
          <td class="right nowrap"><?= e(money($r['price'])) ?></td>
          <td class="right"><label class="editable"><input type="text" inputmode="decimal" data-id="<?= e($r['id']) ?>" data-k="price" value="<?= e(money($r['price'])) ?>" aria-label="<?= e(t('quick.col_new') . ' · ' . $r['name']) ?>"></label></td>
          <td class="right nowrap" data-diff>—</td>
          <td style="text-align:center"><label class="editable editable--s"><input type="text" inputmode="numeric" data-id="<?= e($r['id']) ?>" data-k="stock" value="<?= e($fmtStock($r['stock'])) ?>" aria-label="<?= e(t('quick.col_stock') . ' · ' . $r['name']) ?>"></label></td>
          <td class="right nowrap" data-left></td>
          <td data-status></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>

  <div class="list qlist only-mobile">
    <?php foreach ($rows as $r): ?>
      <div class="qlist__row" data-row="<?= e($r['id']) ?>">
        <div class="col grow" style="gap:0;min-width:0"><span class="t-label-l ellipsis"><?= e($r['name']) ?></span><span class="t-body-s c-muted" data-msub data-cat="<?= e($catName[$r['cat']] ?? '') ?>"><?= e($catName[$r['cat']] ?? '') ?></span></div>
        <?php if ($mode === 'price'): ?>
          <label class="editable"><input type="text" inputmode="decimal" data-id="<?= e($r['id']) ?>" data-k="price" value="<?= e(money($r['price'])) ?>" aria-label="<?= e($r['name']) ?>"></label>
        <?php else: ?>
          <label class="editable editable--s"><input type="text" inputmode="numeric" data-id="<?= e($r['id']) ?>" data-k="stock" value="<?= e($fmtStock($r['stock'])) ?>" aria-label="<?= e($r['name']) ?>"></label>
        <?php endif ?>
      </div>
    <?php endforeach ?>
  </div>
  <?php endif ?>

  <div class="row gap-8 c-muted t-body-s start"><?= icon('info', 18) ?><span class="grow"><?= e(t('quick.note')) ?></span></div>
</div>

<div class="scrim" id="bulk-sheet" hidden>
  <div class="sheet">
    <?= Ui::sheetHead(t('quick.bulk_idle')) ?>
    <div class="sheet__body">
      <?= Ui::select('m_cat', ['' => t('menu.all')] + $catName, $cat, ['label' => t('quick.k_cat'), 'icon' => 'layers', 'attrs' => ['data-bulk-m' => 'cat']]) ?>
      <div class="frow frow--3">
        <?= Ui::select('m_pct', array_combine([-20, -15, -10, -5, 5, 10, 15, 20, 25, 30], array_map(static fn(int $p): string => ($p > 0 ? '+ ' : '− ') . '%' . abs($p), [-20, -15, -10, -5, 5, 10, 15, 20, 25, 30])), '10', ['label' => t('quick.k_change'), 'icon' => 'percent', 'attrs' => ['data-bulk-m' => 'pct']]) ?>
        <?= Ui::select('m_step', [100 => '₺1', 500 => '₺5', 1000 => '₺10', 5000 => '₺50'], '1000', ['label' => t('quick.k_round'), 'icon' => 'tag', 'attrs' => ['data-bulk-m' => 'step']]) ?>
      </div>
      <div class="sheet__actions">
        <?= Ui::btn(t('quick.undo'), ['style' => 'secondary', 'size' => 'l', 'icon' => 'undo', 'attrs' => ['data-bulk-undo' => true, 'data-close' => true]]) ?>
        <?= Ui::btn(t('quick.apply'), ['size' => 'l', 'icon' => 'check', 'attrs' => ['data-bulk-apply' => true, 'data-close' => true]]) ?>
      </div>
    </div>
  </div>
</div>

<div class="scrim" id="quick-search" hidden>
  <form class="sheet" method="get" action="/menu/quick">
    <?= Ui::sheetHead(t('ui.search')) ?>
    <div class="sheet__body">
      <?php if ($cat !== ''): ?><input type="hidden" name="c" value="<?= e($cat) ?>"><?php endif ?>
      <?php if ($mode === 'stock'): ?><input type="hidden" name="m" value="stock"><?php endif ?>
      <?= Ui::field('q', ['type' => 'search', 'icon' => 'search', 'value' => $q, 'placeholder' => t('menu.search'), 'id' => 'f-q-m', 'attrs' => ['autofocus' => true]]) ?>
      <div class="sheet__actions"><?= Ui::btn(t('ui.search'), ['size' => 'l', 'icon' => 'search', 'type' => 'submit']) ?></div>
    </div>
  </form>
</div>
