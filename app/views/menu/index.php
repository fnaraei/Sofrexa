<?php
/**
 * Menu — Figma M1 (34:2, desktop: category panel + table) and M3 (34:444, phone: chips + list with switches).
 * @var array $categories @var array $items @var int $total @var int $soldout @var string $cat @var string $state @var string $q
 */
use Sofrexa\Modules\Menu\Menu;
use Sofrexa\View\Ui;

$sub = t('menu.sub', ['items' => digits($total), 'cats' => digits(count($categories))]);
$appSub = t('menu.sub_m', ['items' => digits($total), 'soldout' => digits($soldout)]);
$headActions = Ui::btn(t('quick.title'), ['style' => 'secondary', 'icon' => 'tag', 'href' => '/menu/quick'])
    . Ui::btn(t('menu.categories'), ['style' => 'secondary', 'icon' => 'layers', 'attrs' => ['data-sheet' => 'cat-list']])
    . Ui::btn(t('menu.groups'), ['style' => 'secondary', 'icon' => 'list', 'attrs' => ['data-sheet' => 'group-list']])
    . Ui::btn(t('menu.new_item'), ['icon' => 'plus', 'href' => url('/menu/items/new', array_filter(['c' => $cat]))]);
$appActions = [
    Ui::ibtn('search', t('ui.search'), ['class' => 'appbar__act', 'attrs' => ['data-sheet' => 'menu-search']]),
    Ui::ibtn('tag', t('quick.title'), ['class' => 'appbar__act', 'href' => '/menu/quick']),
    Ui::ibtn('plus', t('menu.new_item'), ['class' => 'appbar__act', 'href' => '/menu/items/new']),
];
$keep = static fn(array $extra): string => url('/menu', array_filter(['c' => $cat, 's' => $state, 'q' => $q] + $extra, static fn($v) => $v !== null && $v !== ''));
$catName = [];
foreach ($categories as $c) {
    $catName[$c['id']] = tn($c['names']);
}
$webqr = static fn(array $i): string => '<span class="webqr">' . icon($i['show_web'] ? 'eye' : 'eye-off', 18, $i['show_web'] ? 'c-ok' : 'c-off') . icon('qr', 18, $i['show_qr'] ? 'c-ok' : 'c-off') . '</span>';
?>
<div class="menuwrap">
  <nav class="catpanel only-desktop" aria-label="<?= e(t('menu.categories')) ?>">
    <a class="catpanel__row<?= $cat === '' ? ' is-active' : '' ?>" href="<?= e($keep(['c' => null])) ?>"><span><?= e(t('menu.all')) ?></span><b><?= e(digits($total)) ?></b></a>
    <?php foreach ($categories as $c): ?>
      <a class="catpanel__row<?= $cat === $c['id'] ? ' is-active' : '' ?>" href="<?= e($keep(['c' => $c['id']])) ?>"><span><?= e(tn($c['names'])) ?></span><b><?= e(digits($c['item_count'])) ?></b></a>
    <?php endforeach ?>
  </nav>

  <div class="menumain">
    <form class="toolbar only-desktop" method="get" action="/menu">
      <?php if ($cat !== ''): ?><input type="hidden" name="c" value="<?= e($cat) ?>"><?php endif ?>
      <?php if ($state !== ''): ?><input type="hidden" name="s" value="<?= e($state) ?>"><?php endif ?>
      <?= Ui::field('q', ['type' => 'search', 'icon' => 'search', 'value' => $q, 'placeholder' => t('menu.search'), 'class' => 'toolbar__search toolbar__search--280']) ?>
      <div class="chips">
        <?php foreach (['on' => 'menu.f_on', 'soldout' => 'menu.f_soldout', 'hidden' => 'menu.f_hidden'] as $k => $label): ?>
          <?= Ui::chip(t($label), $state === $k, null, ['href' => $keep(['s' => $state === $k ? null : $k])]) ?>
        <?php endforeach ?>
      </div>
    </form>

    <div class="chips only-mobile">
      <?= Ui::chip(t('menu.all'), $cat === '', null, ['href' => $keep(['c' => null])]) ?>
      <?php foreach ($categories as $c): ?>
        <?= Ui::chip(tn($c['names']), $cat === $c['id'], null, ['href' => $keep(['c' => $c['id']])]) ?>
      <?php endforeach ?>
    </div>

    <?php if (!$items): ?>
      <div class="empty"><?= e(t('menu.empty')) ?></div>
    <?php else: ?>
    <div class="dtable dtable--dense dtable--menu only-desktop">
      <table>
        <thead><tr>
          <th><?= e(t('menu.col_item')) ?></th>
          <th style="width:150px"><?= e(t('menu.col_category')) ?></th>
          <th style="width:80px"><?= e(t('menu.col_station')) ?></th>
          <th style="width:80px"><?= e(t('menu.col_price')) ?></th>
          <th style="width:110px"><?= e(t('menu.col_margin')) ?></th>
          <th style="width:64px"><?= e(t('menu.col_on')) ?></th>
          <th style="width:70px"><?= e(t('menu.col_webqr')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($items as $i): $photo = Menu::photoUrl($i['image'], 400); $net = $i['vat_eff'] ? (int) round($i['price'] / (1 + $i['vat_eff'] / 100)) : (int) $i['price']; ?>
          <tr data-href="/menu/items/<?= e($i['id']) ?>">
            <td><span class="mitem"><?= $photo ? '<img class="mitem__ph" src="' . e($photo) . '" alt="" loading="lazy">' : '<span class="mitem__ph mitem__ph--empty">' . icon('image', 18) . '</span>' ?><span class="t-label-m c-primary ellipsis"><?= e(tn($i['names'])) ?></span></span></td>
            <td class="t-body-s"><?= e($catName[$i['category_id']] ?? '') ?></td>
            <td class="t-body-s"><?= e(t('menu.station.' . $i['station_eff'])) ?></td>
            <td class="t-label-m c-primary nowrap"><?= e(money((int) $i['price'])) ?></td>
            <td class="t-body-s <?= $i['cost'] ? 'c-success' : 'c-muted' ?> nowrap"><?= $i['cost'] ? e(money((int) $i['cost']) . ' · %' . digits((int) round(($net - $i['cost']) / max(1, $net) * 100))) : '—' ?></td>
            <td><?= Ui::toggle('available', (bool) $i['available'], ['data-toggle-item' => $i['id'], 'data-field' => 'available', 'aria-label' => t('menu.col_on')]) ?></td>
            <td><?= $webqr($i) ?></td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    </div>

    <div class="list mlist only-mobile">
      <?php foreach ($items as $i): $photo = Menu::photoUrl($i['image'], 400); ?>
        <div class="mlist__row">
          <a class="mlist__link" href="/menu/items/<?= e($i['id']) ?>">
            <?= $photo ? '<img class="mlist__ph" src="' . e($photo) . '" alt="" loading="lazy">' : '<span class="mlist__ph mitem__ph--empty">' . icon('image', 20) . '</span>' ?>
            <span class="col grow" style="gap:2px;min-width:0"><span class="t-label-m ellipsis"><?= e(tn($i['names'])) ?></span><span class="t-body-s c-muted ellipsis"><?= e(money((int) $i['price']) . ' · ' . ($catName[$i['category_id']] ?? '')) ?><?= $i['soldout'] ? ' · ' . e(t('quick.s_out')) : '' ?></span></span>
          </a>
          <?= Ui::toggle('available', (bool) $i['available'], ['data-toggle-item' => $i['id'], 'data-field' => 'available', 'aria-label' => t('menu.col_on')]) ?>
        </div>
      <?php endforeach ?>
    </div>
    <?php endif ?>
  </div>
</div>

<div class="scrim" id="menu-search" hidden>
  <form class="sheet" method="get" action="/menu">
    <?= Ui::sheetHead(t('ui.search')) ?>
    <div class="sheet__body">
      <?php if ($cat !== ''): ?><input type="hidden" name="c" value="<?= e($cat) ?>"><?php endif ?>
      <?= Ui::field('q', ['type' => 'search', 'icon' => 'search', 'value' => $q, 'placeholder' => t('menu.search'), 'id' => 'f-q-m', 'attrs' => ['autofocus' => true]]) ?>
      <div class="chips chips--wrap">
        <?php foreach (['on' => 'menu.f_on', 'soldout' => 'menu.f_soldout', 'hidden' => 'menu.f_hidden'] as $k => $label): ?>
          <?= Ui::chip(t($label), $state === $k, null, ['href' => $keep(['s' => $state === $k ? null : $k])]) ?>
        <?php endforeach ?>
      </div>
      <div class="sheet__actions"><?= Ui::btn(t('ui.search'), ['size' => 'l', 'icon' => 'search', 'type' => 'submit']) ?></div>
    </div>
  </form>
</div>

<?= \Sofrexa\Core\View::partial('menu/_categories', ['categories' => $categories]) ?>
<?= \Sofrexa\Core\View::partial('menu/_groups', ['groups' => Menu::groups()]) ?>
