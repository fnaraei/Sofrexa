<?php
/**
 * Printers — Figma SE1 card 2 (desktop) and SE4 (45:566, phone, with the sync card below).
 * Tapping a printer opens its connection sheet; "Test" prints a test ticket at once.
 * @var array $data
 */
use Sofrexa\Core\Settings;
use Sofrexa\View\Ui;

$badge = static fn(array $p): string => $p['same'] ? Ui::badge(t('set.prn.same_badge'), 'neutral', true)
    : match ($p['status']) {
        'ready' => Ui::badge(t('set.prn.ready'), 'success', true),
        'error' => Ui::badge(t('set.prn.error'), 'danger', true),
        default => Ui::badge(t('set.prn.unknown'), 'neutral', true),
    };
?>
<section class="section section--m-plain">
  <div class="section__head"><h2 class="section__title"><?= e(t('set.prn.title')) ?></h2><p class="section__sub"><?= e(t('set.prn.sub')) ?></p></div>
  <div class="tcard tcard--m" style="padding-block:8px">
    <?php foreach ($data['printers'] as $key => $p): ?>
      <div class="prow">
        <?= icon('printer', 22) ?>
        <button type="button" class="prow__mid" style="text-align:start" data-sheet="prn-<?= $key === 'courier' ? 'routing' : $key ?>">
          <span class="t-label-m"><?= e(t('set.prn.' . $key)) ?></span>
          <span class="t-body-s c-muted" title="<?= e($p['error'] ?? $p['line']) ?>"><?= e($p['line']) ?></span>
        </button>
        <?= $badge($p) ?>
        <?= Ui::btn(t('ui.test'), ['style' => 'ghost', 'size' => 's', 'attrs' => ['data-post' => '/settings/printers/' . $key . '/test', 'data-reload' => true]]) ?>
      </div>
    <?php endforeach ?>
  </div>
</section>

<?php foreach (['cashier', 'kitchen'] as $key): $cfg = $data['printers'][$key]['cfg']; ?>
<div class="scrim" id="prn-<?= $key ?>" hidden>
  <div class="sheet">
    <?= Ui::sheetHead(t('set.prn.' . $key)) ?>
    <div class="sheet__body">
      <?= Ui::select('p[' . $key . '][driver]', ['windows' => t('set.prn.d_windows'), 'share' => t('set.prn.d_share'), 'tcp' => t('set.prn.d_tcp'), 'file' => t('set.prn.d_file')], (string) $cfg['driver'], ['label' => t('set.prn.driver'), 'icon' => 'printer']) ?>
      <?= Ui::field('p[' . $key . '][target]', ['label' => t('set.prn.target'), 'icon' => 'hash', 'value' => (string) $cfg['target'], 'placeholder' => 'POS-80 · \\\\MUTFAK-PC\\Thermal80 · 192.168.1.60:9100']) ?>
      <div class="field"><span class="field__label"><?= e(t('set.prn.width')) ?></span><?= Ui::segs(['80' => '80 mm', '58' => '58 mm'], (string) $cfg['width'], 'p[' . $key . '][width]') ?></div>
      <div class="sheet__actions">
        <?= Ui::btn(t('ui.cancel'), ['style' => 'secondary', 'size' => 'l', 'attrs' => ['data-close' => true]]) ?>
        <?= Ui::btn(t('ui.save'), ['size' => 'l', 'icon' => 'check', 'type' => 'submit']) ?>
      </div>
    </div>
  </div>
</div>
<?php endforeach ?>
<div class="scrim" id="prn-routing" hidden>
  <div class="sheet">
    <?= Ui::sheetHead(t('set.prn.courier')) ?>
    <div class="sheet__body">
      <div class="field"><span class="field__label"><?= e(t('set.prn.courier')) ?></span><?= Ui::segs(['cashier' => t('set.prn.cashier'), 'kitchen' => t('set.prn.kitchen')], (string) Settings::get('printer.courier_on'), 's[printer.courier_on]') ?></div>
      <div class="field"><span class="field__label"><?= e(t('set.prn.bar_on')) ?></span><?= Ui::segs(['cashier' => t('set.prn.cashier'), 'kitchen' => t('set.prn.kitchen')], (string) Settings::get('printer.bar_on'), 's[printer.bar_on]') ?></div>
      <div class="sheet__actions">
        <?= Ui::btn(t('ui.cancel'), ['style' => 'secondary', 'size' => 'l', 'attrs' => ['data-close' => true]]) ?>
        <?= Ui::btn(t('ui.save'), ['size' => 'l', 'icon' => 'check', 'type' => 'submit']) ?>
      </div>
    </div>
  </div>
</div>

<div class="only-mobile"><?= \Sofrexa\Core\View::partial('settings/_sync_card') ?></div>
