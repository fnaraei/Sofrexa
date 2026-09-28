<?php
/**
 * Recipe and cost — Figma S5 (39:722): ingredients and semi-finished groups (their own recipe shown under them),
 * quantity, waste %, cost; the cost summary with price, margin and food cost. Quantities of items kept in kg / L
 * are entered in g / ml. @var string $kind @var string $id @var array $head @var array $lines @var int $cost
 */
use Sofrexa\Core\I18n;
use Sofrexa\Modules\Stock\Stock;
use Sofrexa\View\Ui;

$noHead = true;
$back = $head['back'];
$appTitle = $title;
$appSub = $kind === 'item' ? t('rec.sub') : t('rec.sub_semi', ['unit' => Stock::unitLabel($head['unit'] ?? 'kg')]);
$bodyClass = 'page-recipe';
$bottom = Ui::btn(t('ui.save'), ['size' => 'l', 'icon' => 'check', 'block' => true, 'type' => 'submit', 'attrs' => ['form' => 'recipe-form']]);
// display unit: g for kg, ml for L; factor from the stock unit
$disp = static fn(string $u): array => match ($u) { 'kg' => ['g', 1000], 'lt' => ['ml', 1000], default => [Stock::unitLabel($u), 1] };
$fmt = static fn(float $q): string => Stock::qty($q);
$price = (int) ($head['price'] ?? 0);
// costs in whole lira, as on the design
$lira = static fn(int $k): string => money((int) round($k / 100) * 100);
usort($lines, static fn(array $a, array $b): int => [$a['item_kind'] === 'semi' ? 0 : 1, $a['name']] <=> [$b['item_kind'] === 'semi' ? 0 : 1, $b['name']]);
$net = $head['vat'] > 0 ? (int) round($price / (1 + $head['vat'] / 100)) : $price;
$str = ['semi' => t('rec.semi', ['name' => '{name}']), 'remove' => t('pur.remove')];
?>
<form class="recipe" id="recipe-form" method="post" action="/stock/recipe/<?= e($kind) ?>/<?= e($id) ?>" data-ajax data-toast="off" data-recipe data-price="<?= $price ?>" data-net="<?= $net ?>" data-str='<?= e(json_encode($str, JSON_UNESCAPED_UNICODE)) ?>'>
  <?= csrf_field() ?>
  <div class="page-head page-head--item only-desktop">
    <?= Ui::ibtn('arrow-left', t('ui.back'), ['style' => 'secondary', 'href' => $back, 'class' => 'ibtn--flip']) ?>
    <?php if (!empty($head['photo'])): ?><img class="thumb48" src="<?= e($head['photo']) ?>" alt=""><?php endif ?>
    <div class="page-head__titles"><h1 class="t-heading-xl ellipsis"><?= e($title) ?></h1><p class="t-body-m c-muted"><?= e($appSub) ?></p></div>
    <?= Ui::btn(t('ui.save'), ['icon' => 'check', 'type' => 'submit']) ?>
  </div>
  <div class="recipe__body">
    <section class="card card--pad0 rectable">
      <div class="rrow rrow--head"><span><?= e(t('rec.c_item')) ?></span><span><?= e(t('rec.c_qty')) ?></span><span><?= e(t('rec.c_unit')) ?></span><span><?= e(t('rec.c_waste')) ?></span><span><?= e(t('rec.c_cost')) ?></span></div>
      <div data-rlines>
        <?php foreach ($lines as $i => $l):
            [$du, $f] = $disp($l['unit']);
            $unitCost = Stock::unitCost($l['stock_item_id']) / $f;
            $semi = $l['item_kind'] === 'semi'; ?>
          <div class="rrow<?= $semi ? ' rrow--group' : '' ?>" data-rline data-cost="<?= e((string) $unitCost) ?>">
            <input type="hidden" name="lines[<?= $i ?>][stock_item_id]" value="<?= e($l['stock_item_id']) ?>">
            <input type="hidden" name="lines[<?= $i ?>][factor]" value="<?= $f ?>">
            <span class="rrow__name"><?php if ($semi): ?><button type="button" class="rrow__toggle" data-rtoggle aria-expanded="true"><?= icon('chevron-down', 18) ?></button><?= icon('layers', 18, 'c-accent') ?><span class="t-label-m ellipsis"><?= e(t('rec.semi', ['name' => $l['name']])) ?></span><?php else: ?><?= icon('box', 18) ?><span class="t-body-m ellipsis"><?= e($l['name']) ?></span><?php endif ?></span>
            <span class="cellin"><input name="lines[<?= $i ?>][qty]" value="<?= e($fmt((float) $l['qty'] * $f)) ?>" inputmode="decimal" data-rqty aria-label="<?= e(t('rec.c_qty')) ?>"></span>
            <span class="t-body-s c-secondary"><?= e($du) ?></span>
            <span class="cellin cellin--s"><input name="lines[<?= $i ?>][waste_pct]" value="<?= (float) $l['waste_pct'] > 0 ? e(I18n::num((float) $l['waste_pct'])) : '' ?>" placeholder="—" inputmode="decimal" aria-label="<?= e(t('rec.c_waste')) ?>"></span>
            <span class="rrow__cost"><span class="t-label-m c-secondary num" data-rcost><?= e($lira($l['cost'])) ?></span><button type="button" class="ibtn ibtn--ghost ibtn--s" data-rremove aria-label="<?= e(t('pur.remove')) ?>"><?= icon('close', 18) ?></button></span>
          </div>
          <?php foreach ($l['children'] as $c):
              [$cu, $cf] = $disp($c['unit']); ?>
            <div class="rrow rrow--child" data-rchild>
              <span class="rrow__name"><?= icon('box', 18) ?><span class="t-body-m ellipsis"><?= e($c['name']) ?></span></span>
              <span class="t-label-m c-secondary num"><?= e($fmt((float) $c['qty'] * (float) $l['qty'] * $cf)) ?></span>
              <span class="t-body-s c-secondary"><?= e($cu) ?></span>
              <span class="t-body-s c-secondary"><?= (float) $c['waste_pct'] > 0 ? e('%' . I18n::num((float) $c['waste_pct'])) : '—' ?></span>
              <span class="t-label-m c-secondary num"><?= e($lira($c['cost'])) ?></span>
            </div>
          <?php endforeach ?>
        <?php endforeach ?>
      </div>
      <?php if (!$lines): ?><div class="empty" data-rempty><?= e(t('rec.empty')) ?></div><?php endif ?>
      <div class="puradd rec__add" data-radd hidden>
        <div class="field field--search"><div class="field__box"><?= icon('search', 20) ?><input type="search" placeholder="<?= e(t('rec.pick')) ?>" data-add-search></div></div>
        <div class="picklist puradd__results" data-add-results hidden></div>
      </div>
      <div class="rec__foot">
        <?= Ui::btn(t('rec.add'), ['style' => 'ghost', 'size' => 's', 'icon' => 'plus', 'attrs' => ['data-radd-open' => 'raw']]) ?>
        <?= Ui::btn(t('rec.add_semi'), ['style' => 'ghost', 'size' => 's', 'icon' => 'layers', 'attrs' => ['data-radd-open' => 'semi']]) ?>
      </div>
    </section>
    <section class="card sumcard sumcard--rec">
      <h2 class="t-heading-m"><?= e(t('rec.summary')) ?></h2>
      <div class="kv"><span><?= e(t('rec.cost')) ?></span><span class="num" data-total-cost><?= e($lira($cost)) ?></span></div>
      <?php if ($kind === 'item'): ?>
        <div class="kv"><span><?= e(t('rec.price')) ?></span><span class="num"><?= e(money($price)) ?></span></div>
        <div class="kv"><span><?= e(t('rec.net')) ?></span><span class="num"><?= e(money($net)) ?></span></div>
        <div class="kv kv--big t-heading-m"><span><?= e(t('rec.profit')) ?></span><span class="num c-success" data-profit><?= e($lira($net - $cost)) ?></span></div>
        <div class="kv kv--l"><span><?= e(t('rec.margin')) ?></span><span class="num c-success" data-margin><?= e($net > 0 ? '%' . digits((string) (int) round(($net - $cost) * 100 / $net)) : '—') ?></span></div>
        <div class="kv"><span><?= e(t('rec.food_cost')) ?></span><span class="num" data-food><?= e($net > 0 ? '%' . digits((string) (int) round($cost * 100 / $net)) : '—') ?></span></div>
      <?php endif ?>
      <p class="t-body-s c-muted"><?= e(t('rec.note')) ?></p>
    </section>
  </div>
</form>
