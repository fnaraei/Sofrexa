<?php
/**
 * Order taking — Figma W2 (18:164 phone: menu + send bar) and W10 (22:1214 desktop: menu + order panel).
 * An order that does not exist yet (free table, new takeaway) is created with the first item.
 * @var ?array $o @var array $ctx @var array $items @var array $cats @var array $count @var array $popular
 * @var array $withGroups @var array $required @var array $incart
 */
use Sofrexa\Modules\Orders\Board;
use Sofrexa\View\OrderUi;
use Sofrexa\View\Ui;

$table = $ctx['table'] ?? null;
if ($o && in_array($o['channel'], ['table', 'qr'], true)) {
    $area = Board::areaName($o);
    $appTitle = t('order.table', ['n' => digits($o['table_no'])]);
    $appSub = t('order.sub_m', ['area' => $area, 'g' => digits(max(1, (int) $o['guests'])), 't' => dur((int) $o['opened_at'])]);
    $title = t('order.table_area', ['n' => digits($o['table_no']), 'area' => $area]);
    $sub = t('order.sub_desk', ['g' => digits(max(1, (int) $o['guests'])), 'waiter' => first_name($o['waiter_name'] ?? '—'), 'time' => digits(date('H:i', intdiv((int) $o['opened_at'], 1000)))]);
    $back = '/tables';
} elseif ($table) {
    $area = tn(json_arr($table['area_names']) ?: $table['area_name']);
    $appTitle = t('order.table', ['n' => digits($table['number'])]);
    $title = t('order.table_area', ['n' => digits($table['number']), 'area' => $area]);
    $appSub = $area . ' · ' . t('tables.empty_table');
    $sub = t('tables.empty_table');
    $back = '/tables';
} else {
    $title = $o ? Board::title($o) : t('order.new_takeaway');
    $appTitle = $title;
    $sub = $o ? first_name($o['waiter_name'] ?? '') . ' · ' . dur((int) $o['opened_at']) : '';
    $appSub = $sub;
    $back = can('cash.pay') ? '/cashier' : '/tables';
}
$noHead = true;
$appActions = [Ui::ibtn('search', t('ui.search'), ['class' => 'appbar__act', 'attrs' => ['data-search-toggle' => true]])];
if ($o && $o['table_id']) {
    $appActions[] = Ui::ibtn('more', t('order.more'), ['class' => 'appbar__act', 'attrs' => ['data-load-sheet' => '/tables/' . $o['table_id'] . '/actions']]);
}
$new = $o ? array_values(array_filter($o['lines'], static fn(array $l): bool => $l['status'] === 'new')) : [];
$newTotal = array_sum(array_map([Board::class, 'lineTotal'], $new));
$newQty = (float) array_sum(array_column($new, 'qty'));
$bottom = '<a class="sendbar__info grow" data-summary href="' . ($o ? '/orders/' . e($o['id']) . '/summary' : '#') . '"><span class="t-label-m c-accent" data-new-text>' . e(t('order.n_new', ['n' => digits(\Sofrexa\Modules\Orders\Orders::qtyText($newQty))])) . '</span><span class="t-heading-l num" data-new-total>' . e(money($newTotal)) . '</span></a>'
    . Ui::btn(t('order.send'), ['size' => 'l', 'icon' => 'send', 'class' => 'btn--hug', 'attrs' => ['data-send' => true, 'disabled' => !$new]]);
$bodyClass = 'page-take aside-420';
$aside = '<div class="opanel" data-order-panel>' . \Sofrexa\Core\View::partial('orders/_order_panel', ['o' => $o]) . '</div>';
$ctxJson = json_encode(['order' => $o['id'] ?? '', 'table' => $table['id'] ?? ($o['table_id'] ?? ''), 'guests' => (int) ($ctx['guests'] ?? 0), 'channel' => $ctx['channel'] ?? 'table'], JSON_UNESCAPED_UNICODE);
?>
<div class="take" data-take='<?= e($ctxJson) ?>'>
  <div class="take__head only-desktop">
    <?= Ui::ibtn('arrow-left', t('ui.back'), ['style' => 'secondary', 'href' => $back, 'class' => 'ibtn--flip']) ?>
    <div class="grow col gap-2"><h1 class="t-heading-xl ellipsis"><?= e($title) ?></h1><p class="t-body-m c-muted ellipsis"><?= e($sub) ?></p></div>
    <?= Ui::field('q', ['type' => 'search', 'icon' => 'search', 'placeholder' => t('order.search'), 'class' => 'take__search', 'attrs' => ['data-menu-search' => true]]) ?>
  </div>
  <div class="take__msearch only-mobile" data-msearch hidden><?= Ui::field('qm', ['type' => 'search', 'icon' => 'search', 'placeholder' => t('menu.search'), 'attrs' => ['data-menu-search' => true]]) ?></div>
  <div class="chips chips--scroll" data-cats>
    <?= Ui::chip(t('order.popular'), true, digits(count($popular)), ['data-cat' => 'pop']) ?>
    <?php foreach ($cats as $c): ?><?= Ui::chip(tn($c['names']), false, digits($count[$c['id']] ?? 0), ['data-cat' => $c['id']]) ?><?php endforeach ?>
  </div>
  <div class="mtiles mtiles--order" data-menu>
    <?php $pch = \Sofrexa\Modules\Menu\Promotions::channelOf((string) ($o['channel'] ?? $ctx['channel'] ?? 'table'));
    foreach ($items as $i):
        $i['promo'] = \Sofrexa\Modules\Menu\Promotions::best($i, $pch);
        $search = mb_strtolower(implode(' ', json_arr($i['names'])), 'UTF-8');
        echo OrderUi::menuTile($i, (float) ($incart[$i['id']] ?? 0), [
            'data-cat' => $i['category_id'], 'data-pop' => isset($popular[$i['id']]) ? '1' : null, 'data-q' => $search,
            'data-groups' => isset($withGroups[$i['id']]) ? '1' : null, 'data-required' => isset($required[$i['id']]) ? '1' : null,
            'hidden' => !isset($popular[$i['id']]),
        ]);
    endforeach ?>
  </div>
  <div class="empty" data-menu-empty hidden><?= e(t('menu.empty')) ?></div>
</div>
