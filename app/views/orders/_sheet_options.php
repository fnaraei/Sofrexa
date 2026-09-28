<?php
/**
 * Item options sheet — Figma W4 (19:351): cooking chips, "remove" checkboxes, kitchen note, quantity and add.
 * @var array $item @var array $groups (each with 'options')
 */
use Sofrexa\View\Ui;

$station = t('menu.station.' . ($item['station_eff'] === 'bar' ? 'bar' : 'kitchen'));
$meta = $item['prep_minutes']
    ? t('opt.meta', ['price' => money((int) $item['price']), 'station' => $station, 'min' => digits((int) $item['prep_minutes'])])
    : t('opt.meta_short', ['price' => money((int) $item['price']), 'station' => $station]);
$plus = static fn(int $p): string => $p > 0 ? ' +' . money($p) : '';
?>
<div class="scrim" data-dyn hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead(tn($item['names'])) ?>
    <form class="optsheet" data-options data-item="<?= e($item['id']) ?>" data-price="<?= (int) $item['price'] ?>">
      <div class="optsheet__body">
        <p class="t-body-m c-muted"><?= e($meta) ?></p>
        <?php foreach ($groups as $gi => $g):
            $name = tn($g['names']);
            $multi = $g['kind'] === 'multi' || (int) $g['max_sel'] > 1; ?>
          <div class="optgroup<?= $multi ? ' optgroup--multi' : '' ?>" data-group data-min="<?= (int) $g['min_sel'] ?>" data-max="<?= (int) $g['max_sel'] ?>" data-name="<?= e($name) ?>">
            <div class="overline"><?= e($name) ?></div>
            <?php if ($multi): ?>
              <?php foreach ($g['options'] as $m): ?>
                <label class="checkrow checkrow--opt"><?= Ui::checkbox('mods[]', false, ['value' => $m['id'], 'data-price' => (int) $m['price']]) ?><span class="t-body-l"><?= e(tn($m['names']) . $plus((int) $m['price'])) ?></span></label>
              <?php endforeach ?>
            <?php else: ?>
              <div class="chips chips--wrap">
                <?php foreach ($g['options'] as $k => $m): ?>
                  <label class="chip"><input type="radio" name="g<?= $gi ?>" value="<?= e($m['id']) ?>" data-price="<?= (int) $m['price'] ?>"<?= $k === 0 && (int) $g['min_sel'] > 0 ? ' checked' : '' ?>><?= e(tn($m['names']) . $plus((int) $m['price'])) ?></label>
                <?php endforeach ?>
              </div>
            <?php endif ?>
          </div>
        <?php endforeach ?>
        <?= Ui::field('note', ['label' => t('opt.note'), 'icon' => 'note']) ?>
      </div>
      <div class="optsheet__actions">
        <div class="qty qty--l" data-stepper><button type="button" data-step="-1" aria-label="−"><?= icon('minus', 20) ?></button><span class="qty__n num" data-stepper-n><?= e(digits(1)) ?></span><button type="button" data-step="1" aria-label="+"><?= icon('plus', 20) ?></button><input type="hidden" name="qty" value="1" data-stepper-v data-min="1" data-max="99"></div>
        <?= Ui::btn(t('opt.add', ['amount' => money((int) $item['price'])]), ['type' => 'submit', 'size' => 'l', 'icon' => 'plus', 'class' => 'grow', 'attrs' => ['data-add-label' => t('opt.add', ['amount' => '{amount}'])]]) ?>
      </div>
    </form>
  </div>
</div>
