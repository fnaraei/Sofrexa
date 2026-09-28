<?php
/**
 * Edit / new user sheet — Figma ST9 (104:1339). Filled by users.js from the row's data-user JSON.
 * Also the one-time PIN sheet shown after creating a user or resetting a PIN.
 * @var array $roles
 */
use Sofrexa\View\Ui;
?>
<div class="scrim" id="user-sheet" hidden>
  <form class="sheet" method="post" action="/staff/users/save" data-ajax data-keep-open data-toast="off" data-user-form
    data-new-title="<?= e(t('users.new_title')) ?>" data-since="<?= e(t('users.since')) ?>" data-locked="<?= e(t('ui.locked')) ?>" data-passive="<?= e(t('ui.passive')) ?>"
    data-pin-title="<?= e(t('users.pin_title')) ?>" data-pin-confirm="<?= e(t('users.pin_confirm')) ?>" data-delete-confirm="<?= e(t('users.delete_confirm')) ?>">
    <?= Ui::sheetHead(t('users.edit_title')) ?>
    <div class="sheet__body">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="">
      <div class="row" data-u-who>
        <span class="who grow"><span class="avatar avatar--s" data-u-initials></span><span class="col" style="gap:0;min-width:0"><span class="who__name ellipsis" data-u-name></span><span class="who__sub ellipsis" data-u-since></span></span></span>
        <span data-u-status></span>
      </div>
      <?= Ui::field('name', ['label' => t('users.name'), 'icon' => 'user', 'autocomplete' => 'name', 'attrs' => ['required' => true, 'maxlength' => 80]]) ?>
      <?= Ui::field('phone', ['label' => t('users.phone'), 'icon' => 'phone', 'type' => 'tel', 'autocomplete' => 'tel', 'attrs' => ['maxlength' => 30]]) ?>
      <div class="overline"><?= e(t('users.role')) ?></div>
      <div class="chips chips--wrap" role="radiogroup">
        <?php foreach ($roles as $r): ?>
          <?= Ui::chipRadio('role_id', $r['id'], $r['label']) ?>
        <?php endforeach ?>
      </div>
      <?= Ui::toggleRow('remote_login', t('users.remote'), t('users.remote_sub'), false, ['data-u-remote' => true]) ?>
      <div class="reveal" data-u-email hidden>
        <?= Ui::field('email', ['label' => t('users.email'), 'icon' => 'mail', 'type' => 'email', 'autocomplete' => 'email', 'attrs' => ['maxlength' => 120]]) ?>
      </div>
      <div class="reveal" data-u-activebox hidden>
        <?= Ui::toggleRow('active', t('users.active'), t('users.active_sub'), true, ['data-u-active' => true]) ?>
      </div>
      <div class="list list--soft" data-u-security>
        <button type="button" class="lrow lrow--btn" data-u-reset-pin>
          <span class="lrow__lead"><?= icon('key', 20) ?></span>
          <span class="lrow__mid"><span class="lrow__title"><?= e(t('users.reset_pin')) ?></span><span class="lrow__sub"><?= e(t('users.reset_pin_sub')) ?></span></span>
          <span class="lrow__trail"><?= e(t('users.reset')) ?></span>
          <span class="lrow__chev"><?= icon('chevron-right', 20) ?></span>
        </button>
        <button type="button" class="lrow lrow--btn" data-u-reset-pw hidden>
          <span class="lrow__lead"><?= icon('mail', 20) ?></span>
          <span class="lrow__mid"><span class="lrow__title"><?= e(t('users.reset_password')) ?></span><span class="lrow__sub"><?= e(t('users.reset_password_sub')) ?></span></span>
          <span class="lrow__chev"><?= icon('chevron-right', 20) ?></span>
        </button>
      </div>
      <div class="sheet__actions">
        <?= Ui::ibtn('trash', t('ui.delete'), ['style' => 'secondary', 'size' => 'l', 'attrs' => ['data-u-delete' => true]]) ?>
        <?= Ui::btn(t('ui.cancel'), ['style' => 'secondary', 'size' => 'l', 'attrs' => ['data-close' => true]]) ?>
        <?= Ui::btn(t('ui.save'), ['size' => 'l', 'icon' => 'check', 'type' => 'submit']) ?>
      </div>
    </div>
  </form>
</div>

<div class="scrim" id="pin-sheet" hidden>
  <div class="sheet">
    <?= Ui::sheetHead(t('users.reset_pin')) ?>
    <div class="sheet__body">
      <p class="t-label-l" data-pin-title></p>
      <div class="pinshow" data-pin-digits></div>
      <div class="note"><?= icon('info', 20) ?><span><?= e(t('users.pin_once')) ?></span></div>
      <div class="sheet__actions"><?= Ui::btn(t('ui.close'), ['size' => 'l', 'icon' => 'check', 'attrs' => ['data-close' => true, 'data-reload-on-close' => true]]) ?></div>
    </div>
  </div>
</div>
