<?php
/**
 * Roles and permissions — Figma ST5 (93:354). One form holds the whole matrix; the header "Kaydet" submits it.
 * @var array $roles @var array $groups
 */
use Sofrexa\View\Ui;

$headActions = Ui::btn(t('roles.new'), ['style' => 'secondary', 'icon' => 'plus', 'attrs' => ['data-sheet' => 'role-sheet']])
    . Ui::btn(t('ui.save'), ['icon' => 'check', 'type' => 'submit', 'attrs' => ['form' => 'roles-form']]);
$appActions = [Ui::ibtn('plus', t('roles.new'), ['class' => 'appbar__act', 'attrs' => ['data-sheet' => 'role-sheet']])];
$bottom = Ui::btn(t('ui.save'), ['size' => 'l', 'icon' => 'check', 'type' => 'submit', 'attrs' => ['form' => 'roles-form']]);
$cols = count($roles);
?>
<form id="roles-form" method="post" action="/staff/roles/save" data-ajax data-toast="off" data-reload>
  <?= csrf_field() ?>
  <div class="matrix-scroll">
    <div class="matrix" style="--cols: <?= $cols ?>">
      <div class="matrix__head">
        <div class="overline"><?= e(t('roles.col_perm')) ?></div>
        <?php foreach ($roles as $r): ?>
          <div class="matrix__role" title="<?= $r['all'] ? e(t('roles.manager_all')) : '' ?>"><span class="overline c-primary"><?= e(mb_strtoupper($r['label'])) ?></span><span class="t-body-s c-muted"><?= e(t('roles.people', ['n' => digits($r['people'])])) ?></span></div>
        <?php endforeach ?>
      </div>
      <?php foreach ($groups as $g => $perms): ?>
        <div class="matrix__group overline"><?= e(t('pgroup.' . $g)) ?></div>
        <?php foreach ($perms as $p): ?>
          <div class="matrix__row">
            <div class="t-body-m"><?= e(t('perm.' . $p)) ?></div>
            <?php foreach ($roles as $r): ?>
              <div class="matrix__cell"><?= Ui::checkbox('perms[' . $r['id'] . '][]', $r['all'] || in_array($p, $r['perms'], true), ['value' => $p, 'disabled' => $r['all'], 'aria-label' => $r['label'] . ' · ' . t('perm.' . $p)]) ?></div>
            <?php endforeach ?>
          </div>
        <?php endforeach ?>
      <?php endforeach ?>
    </div>
  </div>
</form>

<div class="scrim" id="role-sheet" hidden>
  <form class="sheet" method="post" action="/staff/roles/save" data-ajax>
    <?= Ui::sheetHead(t('roles.new')) ?>
    <div class="sheet__body">
      <?= csrf_field() ?>
      <input type="hidden" name="create" value="1">
      <?= Ui::field('new_role', ['label' => t('roles.name'), 'icon' => 'user', 'attrs' => ['required' => true, 'maxlength' => 40, 'autofocus' => true]]) ?>
      <div class="sheet__actions">
        <?= Ui::btn(t('ui.cancel'), ['style' => 'secondary', 'size' => 'l', 'attrs' => ['data-close' => true]]) ?>
        <?= Ui::btn(t('ui.add'), ['size' => 'l', 'icon' => 'plus', 'type' => 'submit']) ?>
      </div>
    </div>
  </form>
</div>
