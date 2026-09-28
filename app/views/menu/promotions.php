<?php
/**
 * Promotions — Figma PR1 (113:928, desktop: tabs and status chips, four StatCards, table, note) and
 * PR3 (119:1222, phone: tabs, the running-now card, a list with toggles). Rows open the editor:
 * the PR2 dialog on desktop, the PR4 page on phones.
 * @var array $rows @var array $all @var string $f @var array $stats
 */
use Sofrexa\Modules\Menu\Promotions;
use Sofrexa\View\Ui;

$count = static fn(string $st): int => count(array_filter($all, static fn(array $p): bool => $st === 'off' ? in_array($p['state'], ['off', 'ended'], true) : $p['state'] === $st));
$sub = t('promo.sub');
$appSub = t('promo.sub_m', ['n' => digits(count($all)), 'a' => digits($count('now'))]);
$headActions = Ui::btn(t('promo.new'), ['icon' => 'plus', 'attrs' => ['data-load-sheet' => '/menu/promotions/new/sheet']]);
$appActions = [Ui::ibtn('plus', t('promo.new'), ['class' => 'appbar__act', 'href' => '/menu/promotions/new'])];
$bodyClass = 'page-promos';
$badge = static fn(string $st): string => match ($st) {
    'now' => Ui::badge(t('promo.st_now'), 'success', true),
    'planned' => Ui::badge(t('promo.st_planned'), 'info', true),
    default => Ui::badge(t('promo.st_off'), 'neutral', true),
};
$chip = static fn(string $key, string $label, int $n) => Ui::chip($label, $f === $key, digits($n), ['href' => $key === '' ? '/menu/promotions' : '/menu/promotions?f=' . $key]);
$now = $stats['now'];
$next = $stats['planned'][0] ?? null;
$toggle = static fn(array $p): string => '<span class="promo__toggle">' . Ui::toggle('on', (bool) $p['active'], ['data-promo-toggle' => $p['id'], 'aria-label' => t('promo.active')]) . '</span>';
?>
<div class="only-desktop col gap-16">
  <div class="row between">
    <?= \Sofrexa\Core\View::partial('menu/_tabs', ['active' => '/menu/promotions']) ?>
    <div class="chips"><?= $chip('', t('promo.f_all'), count($all)) . $chip('now', t('promo.st_now'), $count('now')) . $chip('planned', t('promo.st_planned'), $count('planned')) . $chip('off', t('promo.st_off'), $count('off')) ?></div>
  </div>
  <div class="stats stats--4">
    <?= Ui::stat(t('promo.s_now'), $now ? tn($now[0]['names']) : '—', ['brand' => true, 'icon' => 'clock',
        'delta' => $now ? (count($now) > 1 ? t('promo.s_more', ['n' => digits(count($now) - 1)]) : '%' . \Sofrexa\Core\I18n::numAuto((float) $now[0]['pct']) . ' · ' . Promotions::hint($now[0])) : ($next ? Promotions::hint($next) : '')]) ?>
    <?= Ui::stat(t('promo.s_items'), digits($stats['items']), ['icon' => 'tag', 'delta' => $stats['items_label']]) ?>
    <?= Ui::stat(t('promo.s_planned'), digits(count($stats['planned'])), ['icon' => 'calendar', 'delta' => $next ? t('promo.s_next', ['name' => tn($next['names']), 'when' => mb_strtolower(Promotions::hint($next), 'UTF-8')]) : '']) ?>
    <?= Ui::stat(t('promo.s_given'), money($stats['given']), ['icon' => 'receipt', 'delta' => $stats['orders'] ? t('promo.s_orders', ['n' => digits($stats['orders'])]) : '']) ?>
  </div>
  <section class="card card--pad0 promotable">
    <div class="promorow promorow--head"><span><?= e(t('promo.c_promo')) ?></span><span><?= e(t('promo.c_channels')) ?></span><span><?= e(t('promo.c_status')) ?></span><span class="right"><?= e(t('promo.c_active')) ?></span><span></span></div>
    <?php foreach ($rows as $p): ?>
      <div class="promorow" data-load-sheet="/menu/promotions/<?= e($p['id']) ?>/sheet" role="button" tabindex="0">
        <span class="col gap-2"><span class="t-label-l"><?= e(tn($p['names'])) ?></span><span class="t-body-s c-muted"><?= e(Promotions::summary($p)) ?></span></span>
        <span class="t-body-m c-secondary"><?= e(Promotions::channelsText(json_arr($p['channels']))) ?></span>
        <span class="row gap-8"><?= $badge($p['state']) ?><span class="t-body-s c-muted"><?= e(Promotions::hint($p)) ?></span></span>
        <span class="right"><?= $toggle($p) ?></span>
        <span class="right"><?= Ui::ibtn('pencil', t('promo.edit'), ['size' => 's', 'attrs' => ['data-load-sheet' => '/menu/promotions/' . $p['id'] . '/sheet']]) ?></span>
      </div>
    <?php endforeach ?>
    <?php if (!$rows): ?><div class="empty"><?= icon('percent', 24) ?><div class="t-label-l"><?= e(t('promo.empty')) ?></div><div class="t-body-s"><?= e(t('promo.empty_sub')) ?></div></div><?php endif ?>
  </section>
  <p class="row gap-8 t-body-s c-muted"><?= icon('info', 18) ?><span><?= e(t('promo.note')) ?></span></p>
</div>

<div class="only-mobile col gap-12">
  <?= \Sofrexa\Core\View::partial('menu/_tabs', ['active' => '/menu/promotions']) ?>
  <?php if ($now): ?>
    <div class="promonow">
      <?= icon('percent', 20) ?>
      <div class="grow col gap-2"><span class="t-label-l"><?= e(t('promo.active_card', ['name' => tn($now[0]['names'])])) ?></span>
        <span class="t-body-s c-secondary"><?= e('%' . \Sofrexa\Core\I18n::numAuto((float) $now[0]['pct']) . ' · ' . t('promo.n_items', ['n' => digits(count(Promotions::affected($now[0])))]) . ' · ' . Promotions::hint($now[0])) ?></span></div>
      <?= Ui::btn(t('ui.edit'), ['style' => 'secondary', 'size' => 's', 'icon' => 'pencil', 'href' => '/menu/promotions/' . $now[0]['id']]) ?>
    </div>
  <?php endif ?>
  <div class="card promolist">
    <?php foreach ($all as $p): [$what, $when] = Promotions::parts($p); ?>
      <div class="promoitem" data-href="/menu/promotions/<?= e($p['id']) ?>">
        <span class="grow col gap-4"><span class="row gap-8"><span class="t-label-m ellipsis"><?= e(tn($p['names'])) ?></span><?= $badge($p['state']) ?></span>
          <span class="t-body-s c-muted"><?= e($what) ?></span><span class="t-body-s c-muted"><?= e($when) ?></span></span>
        <?= $toggle($p) ?>
      </div>
    <?php endforeach ?>
    <?php if (!$all): ?><div class="empty"><?= e(t('promo.empty')) ?><div class="t-body-s"><?= e(t('promo.empty_sub')) ?></div></div><?php endif ?>
  </div>
</div>
