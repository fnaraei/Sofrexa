<?php
/**
 * Kitchen TV — Figma K1 (30:2, 1920×1080, dark): header with station tabs, counters, sync and clock;
 * tickets in five columns (oldest first); footer hints. Works signed in (/kitchen/tv) or with the
 * display token (/kds/{token}). @var array $tickets @var array $ov @var string $station @var string $base @var string $self
 */
use Sofrexa\View\{Brand, Ui};

$theme = 'dark';
$bodyClass = 'kds-tv';
$title = t('kds.title');
$scripts = ['js/kitchen.js'];
$count = static fn(string $s): int => $s === 'all' ? $ov['open'] : ($ov['stations'][$s] ?? 0);
$tab = static fn(string $s, string $key): string => '<a class="seg' . ($station === $s ? ' is-active' : '') . '" href="' . e($self . '?s=' . $s) . '">' . e(t('kds.tab', ['name' => t($key), 'n' => digits($count($s))])) . '</a>';
?>
<div class="kds" data-kds data-base="<?= e($base) ?>" data-station="<?= e($station) ?>" data-view="tv">
  <header class="kds__head">
    <?= Brand::tenantLogo('s') ?>
    <h1 class="kds__title"><?= e(t('kds.title')) ?></h1>
    <nav class="segs kds__tabs"><?= $tab('kitchen', 'kds.st_kitchen') . $tab('bar', 'kds.st_bar') . $tab('all', 'kds.st_all') ?></nav>
    <div class="grow"></div>
    <div class="kds__badges" data-kds-badges><?= \Sofrexa\Core\View::partial('kitchen/_badges', ['ov' => $ov, 'station' => $station]) ?></div>
    <?= Ui::sync() ?>
    <span class="kds__clock num" data-clock><?= e(digits(date('H:i'))) ?></span>
  </header>
  <main class="kds__tickets" data-kds-tickets>
    <?= \Sofrexa\Core\View::partial('kitchen/_tickets', ['tickets' => $tickets]) ?>
  </main>
  <footer class="kds__foot">
    <span><?= icon('check', 20) ?><?= e(t('kds.hint_tap')) ?></span>
    <span><?= icon('bell', 20) ?><?= e(t('kds.hint_ready')) ?></span>
    <span><?= icon('undo', 20) ?><?= e(t('kds.hint_undo')) ?></span>
    <button type="button" class="kds__sound" data-kds-sound><?= icon('volume', 20) ?><span><?= e(t('kds.hint_sound')) ?></span></button>
  </footer>
</div>
