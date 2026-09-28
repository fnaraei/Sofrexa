<?php
/**
 * Areas and tables — Figma M5 (36:466, desktop: area cards + QR panel) and M6 (36:756, phone: area cards; the QR card opens in a sheet).
 * @var array $areas @var array $counts @var ?array $selected
 */
use Sofrexa\Modules\Menu\Floor;
use Sofrexa\Support\Qr;
use Sofrexa\View\Brand;
use Sofrexa\View\Ui;

$sub = t('floor.sub', ['areas' => digits($counts['areas']), 'tables' => digits($counts['tables'])]);
$appSub = t('floor.sub_m', ['areas' => digits($counts['areas']), 'tables' => digits($counts['tables'])]);
$back = '/more';
$headActions = Ui::btn(t('floor.new_area'), ['style' => 'secondary', 'icon' => 'plus', 'attrs' => ['data-area-new' => true]])
    . ($selected ? Ui::btn(t('floor.add_table'), ['icon' => 'grid', 'attrs' => ['data-post' => '/floor/areas/' . $selected['area_id'] . '/tables']]) : '');
$appActions = [
    Ui::ibtn('qr', t('floor.print'), ['class' => 'appbar__act', 'attrs' => ['data-sheet' => 'qr-sheet', 'disabled' => !$selected]]),
    Ui::ibtn('plus', t('floor.new_area'), ['class' => 'appbar__act', 'attrs' => ['data-area-new' => true]]),
];
$areaJson = static fn(array $a): string => json_encode(['id' => $a['id'], 'names' => json_arr($a['names']) ?: ['tr' => $a['name']], 'smoking' => (bool) $a['smoking'], 'n' => count($a['tables'])], JSON_UNESCAPED_UNICODE);
$subline = static function (array $a): string {
    $n = json_arr($a['names']);
    return implode(' · ', array_filter([$n['en'] ?? null, $n['fa'] ?? null]));
};

$qrCard = '';
if ($selected) {
    $an = json_arr($selected['area']['names']);
    $areaLine = mb_strtoupper(($an['tr'] ?? $selected['area']['name']) . (isset($an['en']) ? ' · ' . $an['en'] : ''), 'UTF-8');
    $logo = Brand::logoUrl();
    $qrCard = '<div class="qrcard" data-theme="dark">'
        . ($logo ? '<img class="qrcard__logo" src="' . e($logo) . '" alt="">' : Brand::tenantLogo('s'))
        . '<div class="qrcard__code">' . Qr::svg(Floor::qrUrl($selected), 104, '#0e1612') . '</div>'
        . '<div class="overline qrcard__area">' . e($areaLine) . '</div>'
        . '<div class="qrcard__no">' . e(t('floor.table_card', ['n' => $selected['number']])) . '</div>'
        . '<div class="t-body-s qrcard__scan">' . e(t('floor.scan')) . '</div></div>';
    $qrHelp = t('floor.qr_help', ['code' => $selected['code']]);
    $qrButtons = Ui::btn(t('floor.png'), ['style' => 'secondary', 'icon' => 'download', 'href' => '/floor/tables/' . $selected['id'] . '/qr.png', 'class' => 'grow'])
        . Ui::btn(t('floor.print'), ['icon' => 'printer', 'class' => 'grow', 'attrs' => ['data-post' => '/floor/tables/' . $selected['id'] . '/print']]);
    $tableTitle = t('floor.table_title', ['n' => $selected['number'], 'area' => tn($selected['area']['names'] ?: $selected['area']['name'])]);
}
?>
<?php if (!$areas): ?>
  <div class="empty"><?= e(t('floor.empty')) ?></div>
<?php endif ?>
<div class="floorwrap">
  <div class="floorareas">
    <?php foreach ($areas as $a): ?>
      <section class="section areacard">
        <div class="areacard__head">
          <?= icon('grip', 20, 'c-off') ?>
          <div class="col grow" style="gap:0;min-width:0">
            <div class="row gap-8"><h2 class="t-heading-m"><?= e(tn($a['names'] ?: $a['name'])) ?></h2><?= $a['smoking'] ? Ui::badge(t('floor.smoking'), 'warning') : '' ?></div>
            <span class="t-body-s c-muted ellipsis" dir="auto"><?= e($subline($a)) ?></span>
          </div>
          <?= Ui::badge(t('floor.n_tables', ['n' => digits(count($a['tables']))])) ?>
          <span class="only-desktop"><?= Ui::ibtn('pencil', t('floor.edit_area'), ['size' => 's', 'attrs' => ['data-area' => $areaJson($a)]]) ?></span>
          <span class="only-desktop"><?= Ui::ibtn('trash', t('ui.delete'), ['size' => 's', 'attrs' => ['data-post' => '/floor/areas/' . $a['id'] . '/delete', 'data-reload' => true, 'data-confirm' => t('floor.delete_area_confirm', ['name' => tn($a['names'] ?: $a['name']), 'n' => count($a['tables'])])]]) ?></span>
        </div>
        <div class="tiles tiles--tables">
          <?php foreach ($a['tables'] as $t): $sel = $selected && $selected['id'] === $t['id']; ?>
            <a class="ttab<?= $sel ? ' is-selected' : '' ?>" href="/floor?t=<?= e($t['id']) ?>" data-table-tile<?= $sel ? ' aria-current="true"' : '' ?>><?= e(digits($t['number'])) ?></a>
          <?php endforeach ?>
          <button type="button" class="ttab ttab--add" data-post="/floor/areas/<?= e($a['id']) ?>/tables" aria-label="<?= e(t('floor.add_table')) ?>"><?= icon('plus', 18) ?></button>
        </div>
      </section>
    <?php endforeach ?>
  </div>

  <?php if ($selected): ?>
  <aside class="section qrpanel only-desktop">
    <div class="row gap-8" style="width:100%"><h2 class="t-heading-m grow"><?= e($tableTitle) ?></h2><?= Ui::ibtn('pencil', t('floor.edit_table'), ['size' => 's', 'attrs' => ['data-sheet' => 'table-edit']]) ?></div>
    <?= $qrCard ?>
    <p class="t-body-s c-muted" style="width:100%"><?= e($qrHelp) ?></p>
    <div class="row gap-8" style="width:100%"><?= $qrButtons ?></div>
  </aside>
  <?php endif ?>
</div>

<?php if ($selected): ?>
<div class="scrim" id="qr-sheet" hidden>
  <div class="sheet">
    <?= Ui::sheetHead($tableTitle) ?>
    <div class="sheet__body" style="align-items:center">
      <?= $qrCard ?>
      <p class="t-body-s c-muted"><?= e($qrHelp) ?></p>
      <div class="sheet__actions" style="width:100%"><?= Ui::btn(t('ui.edit'), ['style' => 'secondary', 'size' => 'l', 'icon' => 'pencil', 'attrs' => ['data-sheet' => 'table-edit']]) ?><?= Ui::btn(t('floor.print'), ['size' => 'l', 'icon' => 'printer', 'attrs' => ['data-post' => '/floor/tables/' . $selected['id'] . '/print']]) ?></div>
      <?= Ui::btn(t('floor.png'), ['style' => 'ghost', 'icon' => 'download', 'href' => '/floor/tables/' . $selected['id'] . '/qr.png']) ?>
    </div>
  </div>
</div>

<div class="scrim" id="table-edit" hidden>
  <form class="sheet" method="post" action="/floor/tables/<?= e($selected['id']) ?>/save" data-ajax data-reload>
    <?= Ui::sheetHead(t('floor.edit_table')) ?>
    <div class="sheet__body">
      <?= csrf_field() ?>
      <div class="frow">
        <?= Ui::field('number', ['label' => t('floor.table_no'), 'icon' => 'hash', 'value' => $selected['number'], 'attrs' => ['required' => true, 'maxlength' => 12]]) ?>
        <?= Ui::field('seats', ['label' => t('floor.seats'), 'icon' => 'users', 'type' => 'number', 'value' => (string) $selected['seats'], 'attrs' => ['min' => 1, 'max' => 40]]) ?>
      </div>
      <?= Ui::select('area_id', array_combine(array_column($areas, 'id'), array_map(static fn(array $a): string => tn($a['names'] ?: $a['name']), $areas)), $selected['area_id'], ['label' => t('floor.area'), 'icon' => 'grid']) ?>
      <div class="sheet__actions">
        <?= Ui::ibtn('trash', t('ui.delete'), ['style' => 'secondary', 'size' => 'l', 'attrs' => ['data-post' => '/floor/tables/' . $selected['id'] . '/delete', 'data-confirm' => t('floor.delete_table_confirm', ['n' => $selected['number']])]]) ?>
        <?= Ui::btn(t('ui.cancel'), ['style' => 'secondary', 'size' => 'l', 'attrs' => ['data-close' => true]]) ?>
        <?= Ui::btn(t('ui.save'), ['size' => 'l', 'icon' => 'check', 'type' => 'submit']) ?>
      </div>
    </div>
  </form>
</div>
<?php endif ?>

<div class="scrim" id="area-edit" hidden>
  <form class="sheet" method="post" action="/floor/areas/save" data-ajax data-reload>
    <?= Ui::sheetHead(t('floor.edit_area')) ?>
    <div class="sheet__body">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="">
      <?= \Sofrexa\Core\View::partial('menu/_langtabs', ['fields' => [['names', 'floor.area_name', 'grid', [], false]]]) ?>
      <?= Ui::toggleRow('smoking', t('floor.smoking_area'), null, false) ?>
      <div class="sheet__actions">
        <?= Ui::btn(t('ui.cancel'), ['style' => 'secondary', 'size' => 'l', 'attrs' => ['data-close' => true]]) ?>
        <?= Ui::btn(t('ui.save'), ['size' => 'l', 'icon' => 'check', 'type' => 'submit']) ?>
      </div>
    </div>
  </form>
</div>
