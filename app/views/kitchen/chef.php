<?php
/**
 * Chef — Figma K2 (31:240, phone: station tabs and ticket cards) and K3 (31:340, desktop: KPIs and one row per
 * order across stations). Dark, no side navigation. @var array $tickets @var array $ov @var string $station @var ?string $tvUrl
 */
use Sofrexa\View\{Brand, Ui};

$theme = 'dark';
$bodyClass = 'kds-chef';
$title = t('kds.title');
$scripts = ['js/kitchen.js'];
$mTab = static fn(string $s, string $key, int $n): string => '<a class="seg' . ($station === $s ? ' is-active' : '') . '" href="/kitchen?s=' . $s . '">' . e(t('kds.tab_m', ['name' => t($key), 'n' => digits($n)])) . '</a>';
$mmss = static fn(int $s): string => sprintf('%02d:%02d', intdiv($s, 60), $s % 60);
$firstLate = $ov['late'][0] ?? null;
$firstReady = $ov['ready'][0] ?? null;
$place = static fn(array $t): string => in_array($t['channel'], ['table', 'qr'], true) ? t('order.table', ['n' => $t['lines'][0]['table_no'] ?? '']) : $t['title'];
$stateTone = ['late' => 'danger', 'new' => 'accent', 'cooking' => 'info', 'ready' => 'success'];
?>
<div class="kds kds--chef" data-kds data-base="/kitchen" data-station="<?= e($station) ?>" data-view="m">
  <!-- K2 phone -->
  <header class="appbar kds__appbar only-mobile">
    <div class="appbar__titles"><div class="appbar__title"><?= e(t('kds.title')) ?></div><div class="appbar__sub"><?= e(t('kds.sub_m', ['n' => digits($ov['open']), 'm' => digits($ov['avg'])])) ?></div></div>
    <button type="button" class="appbar__act" data-kds-sound aria-label="<?= e(t('kds.hint_sound')) ?>"><?= icon('volume', 22) ?></button>
    <button type="button" class="appbar__act" data-sheet="kds-more" aria-label="<?= e(t('ui.more')) ?>"><?= icon('more', 22) ?></button>
  </header>
  <div class="kds__m only-mobile">
    <nav class="segs"><?= $mTab('kitchen', 'kds.st_kitchen', $ov['stations']['kitchen']) . $mTab('bar', 'kds.st_bar', $ov['stations']['bar']) . $mTab('ready', 'kds.st_ready', count($ov['ready'])) ?></nav>
    <div class="kds__list" data-kds-tickets><?= \Sofrexa\Core\View::partial('kitchen/_tickets', ['tickets' => $tickets]) ?></div>
  </div>

  <!-- K3 desktop -->
  <header class="kds__head kds__head--chef only-desktop">
    <?= Brand::tenantLogo('s') ?>
    <h1 class="t-heading-l grow"><?= e(t('kds.chef')) ?></h1>
    <?= Ui::btn(t('kds.tv'), ['style' => 'secondary', 'size' => 's', 'icon' => 'monitor', 'attrs' => ['data-sheet' => 'kds-more']]) ?>
    <?= Ui::sync() ?>
    <span class="t-number-m num" data-clock><?= e(digits(date('H:i'))) ?></span>
  </header>
  <main class="kds__chef only-desktop" data-kds-chef>
    <div class="stats stats--4">
      <?= Ui::stat(t('kds.k_open'), digits($ov['open']), ['brand' => true, 'delta' => t('kds.k_open_d', ['k' => digits($ov['stations']['kitchen']), 'b' => digits($ov['stations']['bar'])])]) ?>
      <?= Ui::stat(t('kds.k_avg'), t('dur.min', ['m' => digits($ov['avg'])]), ['delta' => t('kds.k_avg_d', ['n' => digits(\Sofrexa\Modules\Kitchen\Kitchen::target())])]) ?>
      <?= Ui::stat(t('kds.k_late'), digits(count($ov['late'])), ['delta' => $firstLate ? $place($firstLate) . ' · ' . t('dur.min', ['m' => digits(intdiv($firstLate['seconds'], 60))]) : '']) ?>
      <?= Ui::stat(t('kds.k_ready'), digits(count($ov['ready'])), ['delta' => $firstReady ? $place($firstReady) . ' · ' . dur((int) $firstReady['ready_at']) : '']) ?>
    </div>
    <section class="card card--pad0 ktable">
      <div class="krow krow--head"><span><?= e(t('kds.c_order')) ?></span><span><?= e(t('kds.c_channel')) ?></span><span><?= e(t('kds.c_kitchen')) ?></span><span><?= e(t('kds.c_bar')) ?></span><span><?= e(t('kds.c_time')) ?></span><span><?= e(t('kds.c_waiter')) ?></span><span><?= e(t('kds.c_state')) ?></span></div>
      <?php if (!$ov['orders']): ?><div class="empty"><?= e(t('kds.empty')) ?></div><?php endif ?>
      <?php foreach ($ov['orders'] as $o):
          $prog = static function (?array $p): string {
              if (!$p) {
                  return '<span class="t-body-m c-muted">—</span>';
              }
              return '<span class="t-body-m ' . ($p[0] > 0 ? 'c-success' : 'c-secondary') . '">' . e($p[0] > 0 ? t('kds.progress', ['a' => digits($p[0]), 'b' => digits($p[1])]) : digits($p[0] . '/' . $p[1])) . '</span>';
          }; ?>
        <div class="krow">
          <span class="t-label-l ellipsis"><?= e($o['title']) ?></span>
          <span class="t-body-m c-secondary"><?= e($o['channel']) ?></span>
          <?= $prog($o['kitchen']) ?>
          <?= $prog($o['bar']) ?>
          <span class="t-number-m num <?= $o['state'] === 'late' ? 'c-danger' : 'c-primary' ?>"><?= e(digits($mmss($o['seconds']))) ?></span>
          <span class="t-body-m c-secondary"><?= e($o['waiter'] ?: '—') ?></span>
          <span><?= Ui::badge(t('kds.s.' . $o['state']), $stateTone[$o['state']], true) ?></span>
        </div>
      <?php endforeach ?>
    </section>
  </main>
</div>

<div class="scrim" id="kds-more" hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead(t('kds.tv')) ?>
    <div class="sheet__body">
      <?php if ($tvUrl): ?>
        <p class="t-body-m c-muted"><?= e(t('kds.tv_help')) ?></p>
        <div class="field"><div class="field__box"><?= icon('monitor', 20) ?><input readonly value="<?= e($tvUrl) ?>" data-copy-src aria-label="<?= e(t('kds.tv')) ?>"></div></div>
        <div class="sheet__actions">
          <?= Ui::btn(t('kds.tv_renew'), ['style' => 'secondary', 'size' => 'l', 'icon' => 'refresh', 'attrs' => ['data-post' => '/kitchen/tv/renew', 'data-confirm' => t('kds.tv_renew_confirm'), 'data-reload' => true]]) ?>
          <?= Ui::btn(t('kds.tv_open'), ['size' => 'l', 'icon' => 'monitor', 'href' => $tvUrl, 'attrs' => ['target' => '_blank', 'rel' => 'noopener']]) ?>
        </div>
      <?php endif ?>
      <div class="list list--flush">
        <?= Ui::lrow(t('kds.overview'), ['icon' => 'chart', 'href' => '/kitchen']) ?>
        <?php if (can('orders.take')): ?><?= Ui::lrow(t('tables.title'), ['icon' => 'grid', 'href' => '/tables']) ?><?php endif ?>
        <?= Ui::lrow(t('tab.profile'), ['icon' => 'user', 'href' => '/my']) ?>
      </div>
    </div>
  </div>
</div>
