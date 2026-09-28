<?php
/**
 * Promotion fields — Figma PR2 (117:977; desktop dialog, "what" and "when" columns) and PR4 (120:1281; phone, one column).
 * Name in four languages (the badge is guest-facing), discount, what it applies to, days, hours, dates, channels, on/off.
 * @var array $p @var array $categories @var array $items @var string $mode desk | phone @var string $uid
 */
use Sofrexa\Modules\Menu\Promotions;
use Sofrexa\View\Ui;

$targets = json_arr($p['targets']);
$days = array_map('intval', json_arr($p['days']));
$channels = json_arr($p['channels']);
$names = json_arr($p['names']);
$desk = $mode === 'desk';
$check = static fn(string $name, string $value, string $label, bool $on, ?string $count = null): string => '<label class="chip"><input type="checkbox" name="' . $name . '[]" value="' . e($value) . '"'
    . ($on ? ' checked' : '') . '>' . e($label) . ($count !== null ? '<span class="chip__count">' . e($count) . '</span>' : '') . '</label>';
$shown = 6;
$pctValue = \Sofrexa\Core\I18n::numAuto((float) $p['pct']);

$what = '<div class="row gap-10 end promo__name">'
    . '<div class="grow">' . \Sofrexa\Core\View::partial('menu/_langtabs', ['fields' => [['names', $desk ? 'promo.f_name' : 'promo.f_name_short', 'tag', $names, false]]]) . '</div>'
    . '<div class="promo__pct">' . Ui::field('pct', ['id' => $uid . '-pct', 'label' => t('promo.f_pct'), 'icon' => 'percent', 'value' => $pctValue, 'attrs' => ['inputmode' => 'decimal', 'required' => true, 'data-preview' => true]]) . '</div></div>';
$scopeSeg = '<div class="segs segs--full" data-scope>';
foreach (['all' => 'promo.sc_all', 'categories' => 'promo.sc_categories', 'items' => 'promo.sc_items'] as $k => $label) {
    $scopeSeg .= '<label class="seg"><input type="radio" name="scope" value="' . $k . '"' . ($p['scope'] === $k ? ' checked' : '') . ' data-preview>' . e(t($label)) . '</label>';
}
$scopeSeg .= '</div>';
$cats = '<div class="chips ' . ($desk ? 'chips--wrap' : 'chips--scroll') . ' promo__cats" data-scope-pane="categories"' . ($p['scope'] === 'categories' ? '' : ' hidden') . '>';
foreach ($categories as $k => $c) {
    $cats .= str_replace('<label class="chip">', '<label class="chip"' . ($desk && $k >= $shown && !in_array($c['id'], $targets, true) ? ' data-more hidden' : '') . '>',
        $check('targets', $c['id'], tn($c['names']), in_array($c['id'], $targets, true), digits((int) $c['item_count'])));
}
if ($desk && count($categories) > $shown) {
    $cats .= '<button type="button" class="olink" data-more-cats>' . e(t('promo.more_cats', ['n' => digits(count($categories) - $shown)])) . '</button>';
}
$cats .= '</div>';
$picker = '<div class="promo__items" data-scope-pane="items"' . ($p['scope'] === 'items' ? '' : ' hidden') . '>'
    . Ui::field('item_q', ['id' => $uid . '-iq', 'type' => 'search', 'icon' => 'search', 'placeholder' => t('promo.search_items'), 'attrs' => ['data-item-search' => true]])
    . '<div class="promo__itemlist">';
foreach ($items as $i) {
    $picker .= '<label class="checkrow checkrow--l" data-item-row="' . e(mb_strtolower($i['name'] . ' ' . $i['cat'], 'UTF-8')) . '">' . Ui::checkbox('targets[]', in_array($i['id'], $targets, true), ['value' => $i['id'], 'data-preview' => true])
        . '<span class="grow col gap-2"><span class="t-body-m c-primary">' . e($i['name']) . '</span><span class="t-body-s c-muted">' . e($i['cat']) . '</span></span></label>';
}
$picker .= '</div></div>';
$scope = '<div class="field"><span class="field__label">' . e(t('promo.f_scope')) . '</span>' . $scopeSeg . '</div>' . $cats . $picker;
$active = '<div class="promo__active">' . Ui::toggleRow('active', t('promo.active'), t('promo.active_sub'), (bool) $p['active']) . '</div>';

$dayChips = '<div class="field"><span class="field__label">' . e(t('promo.f_days')) . '</span><div class="chips promo__days">';
for ($d = 1; $d <= 7; $d++) {
    $dayChips .= $check('days', (string) $d, t('promo.d' . $d), in_array($d, $days, true));
}
$dayChips .= '</div>' . ($desk ? '<span class="field__help">' . e(t('promo.days_help')) . '</span>' : '') . '</div>';
$times = '<div class="orow2">' . Ui::field('time_from', ['id' => $uid . '-tf', 'type' => 'time', 'label' => t('promo.f_from'), 'icon' => 'clock', 'value' => (string) $p['time_from']])
    . Ui::field('time_to', ['id' => $uid . '-tt', 'type' => 'time', 'label' => t('promo.f_to'), 'icon' => 'clock', 'value' => (string) $p['time_to']]) . '</div>';
$dates = '<div class="orow2">' . Ui::field('date_from', ['id' => $uid . '-df', 'type' => 'date', 'label' => t('promo.f_dfrom'), 'icon' => 'calendar', 'value' => (string) $p['date_from']])
    . Ui::field('date_to', ['id' => $uid . '-dt', 'type' => 'date', 'label' => t('promo.f_dto'), 'icon' => 'calendar', 'value' => (string) $p['date_to']]) . '</div>'
    . ($desk ? '<span class="field__help promo__help">' . e(t('promo.time_help')) . '</span>' : '');
$chans = '<div class="field"><span class="field__label">' . e(t('promo.f_channels')) . '</span><div class="chips chips--wrap promo__chans">';
foreach (Promotions::CHANNELS as $c) {
    $chans .= $check('channels', $c, t($c === 'table' ? 'promo.ch_table_qr' : 'promo.ch_' . $c), in_array($c, $channels, true));
}
$chans .= '</div></div>';
?>
<?= csrf_field() ?>
<input type="hidden" name="id" value="<?= e((string) $p['id']) ?>">
<?php if ($desk): ?>
  <div class="promoform">
    <div class="promoform__col"><?= $what . $scope ?><span class="grow"></span><?= $active ?></div>
    <div class="promoform__col"><?= $dayChips . $times . $dates . $chans ?></div>
  </div>
<?php else: ?>
  <div class="col gap-14"><?= $what . $scope . $dayChips . $times . $dates . $chans ?><div class="card promo__activecard"><?= Ui::toggleRow('active', t('promo.active'), t('promo.active_sub'), (bool) $p['active']) ?></div></div>
<?php endif ?>
