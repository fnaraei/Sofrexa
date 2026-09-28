<?php
/**
 * Restore confirmation — Figma SE9 (78:878): danger banner, three checks, typed confirmation, Vazgeç + Danger "Geri yükle".
 * The same sheet restores a saved backup or an uploaded file.
 */
use Sofrexa\View\Ui;

$checks = '<div class="checks">' . implode('', array_map(static fn(string $k): string => '<div>' . icon('check-circle', 20) . '<span>' . e(t($k)) . '</span></div>', ['set.bk.check1', 'set.bk.check2', 'set.bk.check3'])) . '</div>';
$confirm = Ui::field('confirm', ['label' => t('set.bk.confirm_label'), 'icon' => 'lock', 'placeholder' => t('set.bk.confirm_word'), 'attrs' => ['required' => true, 'autocomplete' => 'off', 'data-confirm-word' => t('set.bk.confirm_word')]]);
?>
<div class="scrim" id="restore-sheet" hidden>
  <form class="sheet" method="post" action="/settings/backup/restore" data-ajax data-keep-open data-restore-form
        data-banner="<?= e(t('set.bk.sheet_banner')) ?>" data-banner-text="<?= e(t('set.bk.sheet_banner_text')) ?>">
    <?= Ui::sheetHead(t('set.bk.sheet_title')) ?>
    <div class="sheet__body">
      <?= csrf_field() ?>
      <input type="hidden" name="file" value="">
      <div class="banner banner--danger" role="status"><?= icon('alert', 20) ?><div class="col" style="gap:2px"><div class="banner__title" data-r-title></div><div class="banner__text" data-r-text></div></div></div>
      <?= $checks ?>
      <?= $confirm ?>
      <div class="sheet__actions" style="padding-top:6px">
        <?= Ui::btn(t('ui.cancel'), ['style' => 'secondary', 'size' => 'l', 'attrs' => ['data-close' => true]]) ?>
        <?= Ui::btn(t('set.bk.restore'), ['style' => 'danger', 'size' => 'l', 'icon' => 'history', 'type' => 'submit', 'attrs' => ['disabled' => true, 'data-r-go' => true]]) ?>
      </div>
    </div>
  </form>
</div>

<div class="scrim" id="restore-upload" hidden>
  <form class="sheet" method="post" action="/settings/backup/upload" enctype="multipart/form-data" data-ajax data-keep-open data-restore-form>
    <?= Ui::sheetHead(t('set.bk.upload_title')) ?>
    <div class="sheet__body">
      <?= csrf_field() ?>
      <div class="field"><label class="field__label" for="f-backup-file"><?= e(t('set.bk.upload_file')) ?></label><div class="field__box"><?= icon('upload', 20) ?><input id="f-backup-file" type="file" name="backup" accept=".zip,application/zip" required></div></div>
      <?= Ui::banner(t('set.bk.warn_title'), t('set.bk.warn_text'), 'danger', 'alert') ?>
      <?= $checks ?>
      <?= str_replace('id="f-confirm"', 'id="f-confirm-2"', str_replace('for="f-confirm"', 'for="f-confirm-2"', $confirm)) ?>
      <div class="sheet__actions" style="padding-top:6px">
        <?= Ui::btn(t('ui.cancel'), ['style' => 'secondary', 'size' => 'l', 'attrs' => ['data-close' => true]]) ?>
        <?= Ui::btn(t('set.bk.restore'), ['style' => 'danger', 'size' => 'l', 'icon' => 'history', 'type' => 'submit', 'attrs' => ['disabled' => true, 'data-r-go' => true]]) ?>
      </div>
    </div>
  </form>
</div>
