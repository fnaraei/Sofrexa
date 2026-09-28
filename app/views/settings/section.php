<?php
/**
 * One settings section. Desktop: SE1/SE5/SE7 frame (230px sub-navigation + section cards, header "Kaydet").
 * Phones: SE3/SE4/SE6/SE8 — app bar with the section title, cards, bottom "Kaydet" (not on SE8, which saves toggles at once).
 * Sheets and forms that must not sit inside the section form come from settings/s_<key>_after.
 * @var string $section @var string $mTitle @var string $mSub @var array $data
 */
use Sofrexa\Modules\Settings\SettingsController;
use Sofrexa\View\Ui;

$appTitle = $mTitle;
$appSub = $mSub;
$headActions = Ui::btn(t('ui.save'), ['icon' => 'check', 'type' => 'submit', 'attrs' => ['form' => 'set-form']]);
$bottom = $section === 'backup' ? '' : Ui::btn(t('ui.save'), ['size' => 'l', 'icon' => 'check', 'type' => 'submit', 'attrs' => ['form' => 'set-form']]);
$noTabbar = true;
$after = is_file(APP_DIR . '/views/settings/s_' . $section . '_after.php') ? \Sofrexa\Core\View::partial('settings/s_' . $section . '_after', ['data' => $data]) : '';
?>
<div class="setwrap">
  <nav class="setnav only-desktop" aria-label="<?= e(t('set.title')) ?>">
    <?php foreach (SettingsController::SECTIONS as $key => $icon): ?>
      <a class="setnav__item<?= $key === $section ? ' is-active' : '' ?>" href="/settings/<?= $key ?>"<?= $key === $section ? ' aria-current="page"' : '' ?>><?= icon($icon, 18) ?><span><?= e(t('set.nav.' . $key)) ?></span></a>
    <?php endforeach ?>
  </nav>
  <form id="set-form" class="setbody" method="post" action="/settings/<?= e($section) ?>" data-ajax data-reload<?= $section === 'backup' ? ' data-autosave' : '' ?>>
    <?= csrf_field() ?>
    <?= \Sofrexa\Core\View::partial('settings/s_' . $section, ['data' => $data]) ?>
  </form>
</div>
<?= $after ?>
