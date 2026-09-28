<?php
/**
 * Settings list on phones — Figma SE2 (45:333). On the till PC the list is the sub-navigation of each section.
 * @var array $summaries
 */
use Sofrexa\Modules\Settings\SettingsController;
use Sofrexa\Sync\Status;
use Sofrexa\View\Ui;

$appActions = ['<form method="post" action="/logout" class="appbar__act">' . csrf_field() . Ui::ibtn('logout', t('ui.logout'), ['type' => 'submit']) . '</form>'];
$st = Status::get();
$titles = ['profile' => 'set.list.profile'];
?>
<script>if (matchMedia('(min-width: 1024px)').matches) location.replace('/settings/profile');</script>
<div class="strip strip--<?= $st['state'] === 'offline' ? 'warning' : 'success' ?>"><?= icon($st['state'] === 'offline' ? 'wifi-off' : 'cloud-check', 22) ?><span><?= e(t($st['state'] === 'offline' ? 'set.offline_strip' : 'set.synced_strip')) ?></span></div>
<div class="list list--flush">
  <?php foreach (SettingsController::SECTIONS as $key => $icon): if ($key === 'security') continue; ?>
    <?= Ui::lrow(t($titles[$key] ?? 'set.nav.' . $key), ['icon' => $icon, 'sub' => $summaries[$key] ?? '', 'href' => '/settings/' . $key]) ?>
  <?php endforeach ?>
</div>
