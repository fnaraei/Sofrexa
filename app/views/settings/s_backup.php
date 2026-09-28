<?php
/**
 * Sync and backup — Figma SE7 (77:776, desktop), SE8 (78:794, phone). The restore sheet (SE9) is in s_backup_after.
 * Toggles save as soon as they change (the form has data-autosave); phone toggles mirror the desktop ones.
 * @var array $data
 */
use Sofrexa\Core\I18n;
use Sofrexa\Core\Settings;
use Sofrexa\Modules\Backup\Backup;
use Sofrexa\View\Ui;

$backups = $data['backups'];
$last = $backups[0] ?? null;
$hour = sprintf('%02d:00', (int) Settings::get('backup.hour', 3));
$usb = trim((string) Settings::get('backup.usb_path', ''));
$usbSub = $usb === '' ? t('set.bk.usb_path') . ': —' : t(is_dir($usb) ? 'set.bk.usb_ok' : 'set.bk.usb_missing', ['path' => $usb]);
$keep = (int) Settings::get('backup.keep_days', 30);
$places = static fn(array $b): string => implode(' · ', array_map(static fn(string $p): string => $p === 'pc' ? 'PC' : ($p === 'usb' ? 'USB' : 'web'), $b['places']));
$type = static fn(array $b): string => match ($b['kind']) {
    'auto' => Ui::badge(t('set.bk.auto')),
    'safety' => Ui::badge(t('set.bk.safety'), 'warning'),
    default => Ui::badge(t('set.bk.manual', ['name' => explode(' ', (string) $b['by'])[0] ?: '—']), 'accent'),
};
$kindLabel = static fn(array $b): string => match ($b['kind']) {
    'auto' => t('set.bk.auto'),
    'safety' => t('set.bk.safety'),
    default => t('set.bk.manual', ['name' => explode(' ', (string) $b['by'])[0] ?: '—']),
};
$restoreAttrs = static fn(array $b): array => [
    'data-restore' => $b['file'],
    'data-when' => I18n::date($b['at'], 'short') . ' ' . date('H:i', intdiv($b['at'], 1000)),
    'data-later' => (string) Backup::laterRecords($b['at']),
];
$isManager = (user()['role_code'] ?? '') === 'manager';
?>
<?= \Sofrexa\Core\View::partial('settings/_sync_card', ['mobile' => false]) ?>

<!-- desktop: SE7 -->
<section class="section only-desktop">
  <div class="section__head"><h2 class="section__title"><?= e(t('set.bk.title')) ?></h2><p class="section__sub"><?= e(t('set.bk.sub')) ?></p></div>
  <div class="grid3">
    <div class="mini"><span class="overline"><?= e(t('set.bk.last')) ?></span><span class="t-heading-m"><?= e($last ? when_label($last['at']) : '—') ?></span><span class="t-body-s c-muted"><?= e($last ? Backup::size($last['size']) . ' · ' . mb_strtolower($kindLabel($last)) : t('set.bk.none')) ?></span></div>
    <div class="mini"><span class="overline"><?= e(t('set.bk.kept')) ?></span><span class="t-heading-m"><?= e(t('set.bk.kept_n', ['n' => digits(count($backups))])) ?></span><span class="t-body-s c-muted"><?= e(t('set.bk.kept_days', ['n' => digits($keep)])) ?></span></div>
    <div class="mini"><span class="overline"><?= e(t('set.bk.where')) ?></span><span class="t-heading-m"><?= e(t('set.bk.where_n', ['n' => digits($last ? count($last['places']) : 0)])) ?></span><span class="t-body-s c-muted"><?= e($last ? str_replace('PC', 'Kasa PC', $places($last)) : '—') ?></span></div>
  </div>
  <div class="row gap-8 wrap">
    <?= Ui::btn(t('set.bk.now'), ['icon' => 'cloud-check', 'attrs' => ['data-post' => '/settings/backup/create', 'data-reload' => true]]) ?>
    <?php if ($last): ?><?= Ui::btn(t('set.bk.download_last'), ['style' => 'secondary', 'icon' => 'download', 'href' => '/settings/backup/download/' . $last['file']]) ?><?php endif ?>
    <?php if ($isManager): ?><?= Ui::btn(t('set.bk.from_file'), ['style' => 'secondary', 'icon' => 'upload', 'attrs' => ['data-sheet' => 'restore-upload']]) ?><?php endif ?>
  </div>
  <div class="tcard">
    <div class="trow--5"><?= Ui::toggleRow('s[backup.nightly]', t('set.bk.nightly'), t('set.bk.nightly_sub', ['time' => $hour]), (bool) Settings::get('backup.nightly'), ['data-mirror' => 'bkn']) ?></div>
    <div class="trow--5"><?= Ui::toggleRow('s[backup.usb]', t('set.bk.usb'), $usbSub, (bool) Settings::get('backup.usb'), ['data-mirror' => 'bku']) ?></div>
    <div class="trow--5"><?= Ui::toggleRow('s[backup.to_web]', t('set.bk.web'), t('set.bk.web_sub'), (bool) Settings::get('backup.to_web'), ['data-mirror' => 'bkw']) ?></div>
  </div>
  <div class="frow">
    <?= Ui::field('s[backup.usb_path]', ['label' => t('set.bk.usb_path'), 'icon' => 'download', 'value' => $usb, 'placeholder' => 'E:\\SofrexaYedek']) ?>
    <?= Ui::select('s[backup.hour]', array_combine(range(0, 23), array_map(static fn(int $h): string => sprintf('%02d:00', $h), range(0, 23))), (string) (int) Settings::get('backup.hour', 3), ['label' => t('set.bk.hour'), 'icon' => 'clock']) ?>
  </div>
</section>

<section class="section only-desktop">
  <div class="section__head"><h2 class="section__title"><?= e(t('set.bk.list')) ?></h2><p class="section__sub"><?= e(t('set.bk.list_sub', ['n' => digits($keep)])) ?></p></div>
  <?php if (!$backups): ?>
    <div class="empty"><?= e(t('set.bk.none')) ?></div>
  <?php else: ?>
  <div class="bktable">
    <table>
      <thead><tr><th style="width:170px"><?= e(t('set.bk.col_date')) ?></th><th><?= e(t('set.bk.col_type')) ?></th><th class="right" style="width:80px"><?= e(t('set.bk.col_size')) ?></th><th style="width:150px"><?= e(t('set.bk.col_where')) ?></th><th style="width:180px"></th></tr></thead>
      <tbody>
      <?php foreach ($backups as $b): ?>
        <tr>
          <td class="t-label-m c-primary"><?= e(digits(date('d.m.Y · H:i', intdiv($b['at'], 1000)))) ?></td>
          <td><?= $type($b) ?></td>
          <td class="right nowrap"><?= e(Backup::size($b['size'])) ?></td>
          <td><?= e($places($b)) ?></td>
          <td><div class="acts gap-6">
            <?php if ($isManager): ?><?= Ui::btn(t('set.bk.restore'), ['style' => 'ghost', 'size' => 's', 'icon' => 'history', 'attrs' => $restoreAttrs($b)]) ?><?php endif ?>
            <?= Ui::ibtn('download', t('ui.download'), ['size' => 's', 'href' => '/settings/backup/download/' . $b['file']]) ?>
          </div></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
  <?php endif ?>
  <?= Ui::banner(t('set.bk.warn_title'), t('set.bk.warn_text'), 'warning', 'alert') ?>
</section>

<!-- phone: SE8 -->
<div class="bkhero only-mobile">
  <span class="overline"><?= e(t('set.bk.last')) ?></span>
  <span class="bkhero__time"><?= e($last ? when_label($last['at']) : '—') ?></span>
  <span class="bkhero__meta"><?= e($last ? Backup::size($last['size']) . ' · ' . str_replace('PC', 'Kasa PC', $places($last)) : t('set.bk.none')) ?></span>
</div>
<div class="only-mobile"><?= Ui::btn(t('set.bk.now'), ['size' => 'l', 'block' => true, 'icon' => 'cloud-check', 'attrs' => ['data-post' => '/settings/backup/create', 'data-reload' => true]]) ?></div>
<?php if ($backups): ?>
<div class="overline only-mobile"><?= e(t('set.bk.list')) ?></div>
<div class="list only-mobile" style="padding:4px 12px">
  <?php foreach (array_slice($backups, 0, 10) as $b): ?>
    <?= Ui::lrow(I18n::date($b['at'], 'short') . ' · ' . digits(date('H:i', intdiv($b['at'], 1000))), ['icon' => 'history', 'sub' => $kindLabel($b) . ' · ' . Backup::size($b['size']), 'trail' => $isManager ? t('set.bk.restore') : null, 'chevron' => $isManager, 'attrs' => $isManager ? $restoreAttrs($b) + ['data-action' => 'restore'] : []]) ?>
  <?php endforeach ?>
</div>
<?php endif ?>
<div class="overline only-mobile"><?= e(t('set.bk.auto')) ?></div>
<div class="card only-mobile" style="padding:6px 16px;gap:0">
  <?= Ui::toggleRow('m_bk_nightly', t('set.bk.nightly_m', ['time' => $hour]), t('set.bk.nightly_sub_m'), (bool) Settings::get('backup.nightly'), ['data-mirror' => 'bkn']) ?>
  <?= Ui::toggleRow('m_bk_usb', t('set.bk.usb'), $usb ?: '—', (bool) Settings::get('backup.usb'), ['data-mirror' => 'bku']) ?>
  <?= Ui::toggleRow('m_bk_web', t('set.bk.web_m'), t('set.bk.encrypted'), (bool) Settings::get('backup.to_web'), ['data-mirror' => 'bkw']) ?>
</div>
