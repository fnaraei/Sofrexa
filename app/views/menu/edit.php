<?php
/**
 * Item editor — Figma M2 (35:251, desktop) and M4 (35:560, phone). One form serves both layouts:
 * fields the phone design leaves out stay in the form (only-desktop) so saving keeps their values.
 * @var ?array $item @var array $categories @var array $groups @var string $preselect
 */
use Sofrexa\Core\Money;
use Sofrexa\Modules\Menu\Menu;
use Sofrexa\View\Ui;

$isNew = $item === null;
$i = $item ?? ['id' => '', 'names' => '{}', 'descs' => '{}', 'category_id' => $preselect ?: ($categories[0]['id'] ?? ''), 'price' => 0, 'station' => null, 'vat_rate' => null,
    'prep_minutes' => null, 'available' => 1, 'show_web' => 1, 'show_qr' => 1, 'show_online' => 1, 'image' => null, 'groups' => [], 'cost' => 0, 'cat_station' => 'kitchen', 'changed' => null, 'orderable' => true, 'soldout' => false];
$names = json_arr($i['names']);
$descs = json_arr($i['descs']);
$catMap = [];
foreach ($categories as $c) {
    $catMap[$c['id']] = $c;
}
$cat = $catMap[$i['category_id']] ?? null;
$catName = $cat ? tn($cat['names']) : '';
$photo = Menu::photoUrl($i['image'], 800);
$noHead = true;
$appTitle = $isNew ? t('menu.new_item') : tn($names);
$appSub = $isNew ? '' : $catName . ' · ' . money((int) $i['price']);
$appActions = $isNew ? [] : [Ui::ibtn('more', t('ui.more'), ['class' => 'appbar__act', 'attrs' => ['data-sheet' => 'item-more']])];
$bottom = Ui::btn(t('ui.save'), ['size' => 'l', 'icon' => 'check', 'type' => 'submit', 'attrs' => ['form' => 'item-form']]);
$vatEff = $i['vat_rate'] ?? ($cat['vat_rate'] ?? 0);
$net = $vatEff ? (int) round($i['price'] / (1 + $vatEff / 100)) : (int) $i['price'];
$attached = array_values(array_filter($groups, static fn(array $g): bool => in_array($g['id'], $i['groups'], true)));
$status = $isNew ? '' : ($i['soldout'] ? Ui::badge(t('quick.s_out'), 'danger', true) : ($i['available'] ? Ui::badge(t('quick.s_on'), 'success', true) : Ui::badge(t('quick.s_off'), 'neutral', true)));
?>
<form id="item-form" class="itemform" method="post" action="/menu/items/save" enctype="multipart/form-data" data-ajax data-item-form>
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= e($i['id']) ?>">
  <input type="hidden" name="groups[]" value="">

  <div class="page-head page-head--item only-desktop">
    <?= Ui::ibtn('arrow-left', t('ui.back'), ['style' => 'secondary', 'href' => '/menu']) ?>
    <div class="page-head__titles">
      <h1 class="t-heading-xl"><?= e($isNew ? t('menu.new_item') : tn($names)) ?></h1>
      <?php if (!$isNew): ?><p class="t-body-m c-muted"><?= e($catName . ($i['changed'] ? ' · ' . t('menu.last_change', ['when' => mb_strtolower(when_label((int) $i['changed']['at'])), 'who' => explode(' ', (string) $i['changed']['name'])[0]]) : '')) ?></p><?php endif ?>
    </div>
    <?= $status ?>
  </div>

  <div class="itemgrid">
    <div class="itemgrid__main">
      <section class="section section--m-plain itemcard">
        <div class="itemtop">
          <label class="itemphoto" title="<?= e(t('menu.photo_change')) ?>">
            <?= $photo ? '<img src="' . e($photo) . '" alt="" data-photo-preview>' : '<span class="itemphoto__empty" data-photo-preview>' . icon('image', 32) . '</span>' ?>
            <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" data-photo-input hidden>
          </label>
          <div class="col grow gap-6 only-mobile">
            <div><?= Ui::btn(t('menu.photo_change'), ['style' => 'secondary', 'size' => 's', 'icon' => 'image', 'attrs' => ['data-photo-pick' => true]]) ?></div>
            <span class="t-body-s c-muted"><?= e(t('menu.photo_help')) ?></span>
          </div>
          <div class="col grow gap-12 itemtop__fields">
            <?= \Sofrexa\Core\View::partial('menu/_langtabs', ['fields' => [['names', 'menu.name', 'utensils', $names, false], ['descs', 'menu.desc', 'note', $descs, true]]]) ?>
          </div>
        </div>
        <div class="frow only-desktop">
          <?= Ui::select('category_id', array_map(static fn(array $c): string => tn($c['names']), $catMap), (string) $i['category_id'], ['label' => t('menu.category'), 'icon' => 'layers']) ?>
          <?= Ui::select('station', ['' => t('menu.station_default', ['station' => t('menu.station.' . ($cat['station'] ?? 'kitchen'))]), 'kitchen' => t('menu.station.kitchen'), 'bar' => t('menu.station.bar')], (string) $i['station'], ['label' => t('menu.station'), 'icon' => 'chef-hat']) ?>
        </div>
        <div class="frow frow--3">
          <?= Ui::field('price', ['label' => t('menu.price'), 'labelM' => t('menu.price_m'), 'icon' => 'tag', 'value' => $i['price'] ? money((int) $i['price']) : '', 'placeholder' => '₺0', 'attrs' => ['inputmode' => 'decimal', 'required' => true]]) ?>
          <?= Ui::field('vat_rate', ['label' => t('menu.vat'), 'labelM' => t('menu.vat_m'), 'icon' => 'percent', 'value' => $i['vat_rate'] === null ? '' : '%' . num((float) $i['vat_rate']), 'placeholder' => '%' . num((float) ($cat['vat_rate'] ?? 0)) . ' · ' . t('menu.vat_default'), 'attrs' => ['inputmode' => 'decimal']]) ?>
          <div class="only-desktop"><?= Ui::field('prep_minutes', ['label' => t('menu.prep'), 'icon' => 'timer', 'value' => $i['prep_minutes'] === null ? '' : (string) $i['prep_minutes'], 'suffix' => t('menu.minutes'), 'attrs' => ['inputmode' => 'numeric']]) ?></div>
        </div>
      </section>

      <section class="section only-desktop">
        <div class="row gap-8"><h2 class="section__title grow"><?= e(t('menu.options')) ?></h2><?= Ui::btn(t('menu.add_group'), ['style' => 'ghost', 'size' => 's', 'icon' => 'plus', 'attrs' => ['data-sheet' => 'item-groups']]) ?></div>
        <div class="optview" data-attached data-empty="<?= e(t('menu.no_groups')) ?>">
          <?php if (!$attached): ?><p class="t-body-s c-muted" data-no-groups><?= e(t('menu.no_groups')) ?></p><?php endif ?>
          <?php foreach ($attached as $g): ?>
            <div class="optview__row" data-group-row="<?= e($g['id']) ?>">
              <input type="hidden" name="groups[]" value="<?= e($g['id']) ?>">
              <span class="optview__label"><?= e(tn($g['names']) . ' (' . t($g['kind'] === 'multi' ? 'menu.multi' : 'menu.single') . ')') ?></span>
              <div class="chips chips--wrap gap-6"><?php foreach ($g['options'] as $o): ?><span class="chip chip--static"><?= e(tn($o['names'])) ?><?= $o['price'] ? ' +' . e(money((int) $o['price'])) : '' ?></span><?php endforeach ?></div>
            </div>
          <?php endforeach ?>
        </div>
      </section>
    </div>

    <div class="itemgrid__side">
      <section class="section itemvis">
        <h2 class="section__title only-desktop"><?= e(t('menu.visibility')) ?></h2>
        <div class="only-desktop">
          <?= Ui::toggleRow('available', t('menu.v_on'), t('menu.v_on_sub'), (bool) $i['available'], ['data-mirror' => 'v-on']) ?>
          <?= Ui::toggleRow('show_web', t('menu.v_web'), preg_replace('#^https?://#', '', rtrim((string) \Sofrexa\Core\Settings::get('profile.website'), '/')) . '/menu', (bool) $i['show_web'], ['data-mirror' => 'v-web']) ?>
          <?= Ui::toggleRow('show_qr', t('menu.v_qr'), null, (bool) $i['show_qr'], ['data-mirror' => 'v-qr']) ?>
          <?= Ui::toggleRow('show_online', t('menu.v_online'), null, (bool) $i['show_online'], ['data-mirror' => 'v-online']) ?>
        </div>
        <div class="only-mobile">
          <?= Ui::toggleRow('m_available', t('menu.v_on'), null, (bool) $i['available'], ['data-mirror' => 'v-on']) ?>
          <?= Ui::toggleRow('m_webqr', t('menu.v_webqr_m'), null, $i['show_web'] && $i['show_qr'], ['data-mirror-both' => 'v-web v-qr']) ?>
          <?= Ui::toggleRow('m_online', t('menu.v_online_m'), null, (bool) $i['show_online'], ['data-mirror' => 'v-online']) ?>
        </div>
      </section>

      <section class="section only-desktop">
        <div class="row gap-8"><h2 class="section__title grow"><?= e(t('menu.recipe')) ?></h2><?= Ui::btn(t('ui.edit'), ['style' => 'ghost', 'size' => 's', 'icon' => 'pencil', 'href' => $isNew ? null : '/stock/recipes/' . $i['id'], 'attrs' => $isNew ? ['disabled' => true] : []]) ?></div>
        <div class="kvrows">
          <div class="kv"><span><?= e(t('menu.r_ingredients')) ?></span><span><?= e(t('menu.r_none')) ?></span></div>
          <div class="kv"><span><?= e(t('menu.r_cost')) ?></span><span><?= $i['cost'] ? e(money((int) $i['cost'])) : '—' ?></span></div>
          <div class="kv"><span><?= e(t('menu.r_net')) ?></span><span><?= e(money($net)) ?></span></div>
          <div class="kv kv--l"><span><?= e(t('menu.r_profit')) ?></span><span class="<?= $i['cost'] ? 'c-success' : 'c-muted' ?>"><?= $i['cost'] ? e(money($net - (int) $i['cost']) . ' · %' . digits((int) round(($net - $i['cost']) / max(1, $net) * 100))) : '—' ?></span></div>
        </div>
      </section>
    </div>
  </div>

  <div class="savebar only-desktop">
    <?php if (!$isNew): ?><?= Ui::btn(t('menu.delete_item'), ['style' => 'danger', 'icon' => 'trash', 'attrs' => ['data-post' => '/menu/items/' . $i['id'] . '/delete', 'data-confirm' => t('menu.delete_confirm', ['name' => tn($names)])]]) ?><?php endif ?>
    <span class="grow"></span>
    <?= Ui::btn(t('ui.cancel'), ['style' => 'secondary', 'href' => '/menu']) ?>
    <?= Ui::btn(t('ui.save'), ['icon' => 'check', 'type' => 'submit']) ?>
  </div>
</form>

<div class="scrim" id="item-groups" hidden>
  <div class="sheet">
    <?= Ui::sheetHead(t('menu.pick_groups')) ?>
    <div class="sheet__body">
      <?php if (!$groups): ?><div class="empty"><?= e(t('menu.groups_empty')) ?></div><?php endif ?>
      <?php foreach ($groups as $g): ?>
        <label class="checkrow checkrow--l">
          <?= Ui::checkbox('pick', in_array($g['id'], $i['groups'], true), ['value' => $g['id'], 'data-pick-group' => json_encode(['id' => $g['id'], 'label' => tn($g['names']) . ' (' . t($g['kind'] === 'multi' ? 'menu.multi' : 'menu.single') . ')', 'options' => array_map(static fn(array $o): string => tn($o['names']) . ($o['price'] ? ' +' . money((int) $o['price']) : ''), $g['options'])], JSON_UNESCAPED_UNICODE)]) ?>
          <span class="col" style="gap:0"><span class="t-label-m c-primary"><?= e(tn($g['names'])) ?></span><span class="t-body-s c-muted"><?= e(implode(' · ', array_map(static fn(array $o): string => tn($o['names']), $g['options']))) ?></span></span>
        </label>
      <?php endforeach ?>
      <div class="sheet__actions"><?= Ui::btn(t('ui.confirm'), ['size' => 'l', 'icon' => 'check', 'attrs' => ['data-groups-apply' => true]]) ?></div>
    </div>
  </div>
</div>

<?php if (!$isNew): ?>
<div class="scrim" id="item-more" hidden>
  <div class="sheet">
    <?= Ui::sheetHead(tn($names)) ?>
    <div class="sheet__body">
      <?= Ui::select('category_id_m', array_map(static fn(array $c): string => tn($c['names']), $catMap), (string) $i['category_id'], ['label' => t('menu.category'), 'icon' => 'layers', 'attrs' => ['data-mirror-select' => 'category_id']]) ?>
      <?= Ui::btn(t('menu.delete_item'), ['style' => 'danger', 'size' => 'l', 'block' => true, 'icon' => 'trash', 'attrs' => ['data-post' => '/menu/items/' . $i['id'] . '/delete', 'data-confirm' => t('menu.delete_confirm', ['name' => tn($names)])]]) ?>
    </div>
  </div>
</div>
<?php endif ?>
