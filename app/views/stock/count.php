<?php
/**
 * Stock count — Figma S3 (39:529, phone): progress, place tabs, one row per item with the counted quantity;
 * the difference and its value at the bottom; "Bitir" books the differences. Counts are kept on the device
 * until "Bitir" (a count can be interrupted). @var array $locations @var string $loc @var array $items
 */
use Sofrexa\Modules\Stock\Stock;
use Sofrexa\View\Ui;

$back = '/stock';
$title = t('cnt.title');
$appSub = t('cnt.sub', ['loc' => $loc !== '' ? $loc : t('cnt.all'), 'a' => '0', 'b' => digits(count($items))]);
$sub = $appSub;
$appActions = [Ui::ibtn('search', t('ui.search'), ['class' => 'appbar__act', 'attrs' => ['data-count-search' => true]])];
$bodyClass = 'page-count';
$bottom = '<div class="grow col" style="gap:0"><span class="t-label-m c-warning" data-diff-text>' . e(t('cnt.no_diff')) . '</span><span class="t-body-s c-muted num" data-diff-value></span></div>'
    . Ui::btn(t('cnt.finish'), ['size' => 'l', 'icon' => 'check', 'class' => 'btn--hug', 'type' => 'submit', 'attrs' => ['form' => 'count-form']]);
$str = ['sub' => t('cnt.sub', ['loc' => $loc !== '' ? $loc : t('cnt.all'), 'a' => '{a}', 'b' => '{b}']), 'one' => t('cnt.diff', ['q' => '{q}', 'unit' => '{unit}', 'name' => '{name}']), 'many' => t('cnt.diff_many', ['n' => '{n}']), 'none' => t('cnt.no_diff')];
?>
<form class="count" id="count-form" method="post" action="/stock/count" data-count data-key="<?= e('count:' . \Sofrexa\Modules\Orders\Orders::businessDay() . ':' . $loc) ?>" data-str='<?= e(json_encode($str, JSON_UNESCAPED_UNICODE)) ?>'>
  <?= csrf_field() ?>
  <input type="hidden" name="loc" value="<?= e($loc) ?>">
  <div class="progress"><span class="progress__fill" data-progress style="width:0"></span></div>
  <?php if (count($locations) > 1): ?>
    <nav class="segs"><?php foreach ($locations as $l): ?><a class="seg<?= $l === $loc ? ' is-active' : '' ?>" href="/stock/count?loc=<?= e(rawurlencode($l)) ?>"><?= e($l) ?></a><?php endforeach ?></nav>
  <?php endif ?>
  <div class="field field--search" data-count-filter hidden><div class="field__box"><?= icon('search', 20) ?><input type="search" placeholder="<?= e(t('stock.search')) ?>" data-count-q></div></div>
  <?php if (!$items): ?><div class="empty"><?= e(t('stock.empty')) ?></div><?php endif ?>
  <div class="cntlist">
    <?php foreach ($items as $r): ?>
      <label class="cntrow" data-cnt data-expected="<?= e((string) $r['on_hand']) ?>" data-cost="<?= e((string) (float) $r['avg_cost']) ?>" data-name="<?= e(mb_strtolower($r['name'], 'UTF-8')) ?>" data-unit="<?= e(Stock::unitLabel($r['unit'])) ?>">
        <span class="grow col gap-2"><span class="t-label-l"><?= e($r['name']) ?></span><span class="t-body-s c-muted"><?= e(t('cnt.system', ['q' => Stock::qty($r['on_hand']), 'unit' => Stock::unitLabel($r['unit'])])) ?></span></span>
        <span class="cntrow__box"><input name="counted[<?= e($r['id']) ?>]" inputmode="decimal" placeholder="—" aria-label="<?= e($r['name']) ?>"><span class="t-label-s c-muted"><?= e(Stock::unitLabel($r['unit'])) ?></span></span>
        <span class="cntrow__state"><?= icon('chevron-right', 22) ?></span>
      </label>
    <?php endforeach ?>
  </div>
</form>
