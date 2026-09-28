<?php
/**
 * Till — Figma C1 (24:2 desktop: KPIs, filters, order cards) and C8 (29:970 phone: two stats, chips, order rows).
 * @var array $cards @var array $counts @var int $openTotal @var array $today @var ?array $shift @var array $rates
 */
use Sofrexa\Core\Money;
use Sofrexa\View\Ui;

$u = user();
if ($shift) {
    $sub = t('cash.sub', ['time' => digits(date('H:i', intdiv((int) $shift['opened_at'], 1000))), 'name' => first_name($shift['user_name'] ?? $u['name'])]);
    $appSub = t('cash.sub_m', ['name' => first_name($shift['user_name'] ?? $u['name'])]);
} else {
    $sub = $appSub = t('cash.sub_closed');
}
$fx = [];
foreach ($rates as $c => $r) {
    $fx[] = Money::symbol($c) . ' ' . digits(number_format($r, 2, ',', '.'));
}
$fxLabel = $fx ? t('cash.fx', ['rates' => implode(' · ', $fx)]) : t('cash.fx_none');
$headActions = (can('cash.rates') ? Ui::btn($fxLabel, ['style' => 'secondary', 'icon' => 'currency', 'href' => '/cashier/rates']) : '')
    . Ui::btn(t('cash.takeaway'), ['style' => 'secondary', 'icon' => 'bag', 'href' => '/orders/new/takeaway'])
    . Ui::btn(t('cash.phone'), ['icon' => 'phone', 'href' => '/delivery/new'])
    . Ui::ibtn('more', t('ui.more'), ['attrs' => ['data-sheet' => 'till-more']]);
$appActions = [
    Ui::ibtn('currency', t('cash.rates'), ['class' => 'appbar__act', 'href' => '/cashier/rates']),
    Ui::ibtn('plus', t('ui.new'), ['class' => 'appbar__act', 'attrs' => ['data-sheet' => 'till-more']]),
];
$bodyClass = 'page-till';
$delta = $today['yesterday'] > 0 ? (int) round(($today['total'] - $today['yesterday']) * 100 / $today['yesterday']) : null;
$chip = static fn(string $key, string $label, bool $m = false) => Ui::chip($label, $key === 'all', digits($counts[$key] ?? 0), ['data-filter' => $key]);
?>
<?php if (!$shift): ?>
  <div class="banner banner--warning" role="status"><?= icon('lock', 20) ?><div class="col grow" style="gap:2px"><div class="banner__title"><?= e(t('cash.no_shift_t')) ?></div><div class="banner__text"><?= e(t('cash.no_shift')) ?></div></div>
    <?php if (can('cash.shift')): ?><?= Ui::btn(t('shift.open_btn'), ['size' => 's', 'icon' => 'lock', 'attrs' => ['data-load-sheet' => '/cashier/shift/open']]) ?><?php endif ?></div>
<?php endif ?>

<div class="stats stats--4 only-desktop">
  <?= Ui::stat(t('cash.k_open'), money($openTotal), ['brand' => true]) ?>
  <?= Ui::stat(t('cash.k_taken'), money($today['total']), ['delta' => $delta !== null ? t('cash.vs_yesterday', ['p' => digits(abs($delta))]) : '', 'down' => $delta !== null && $delta < 0]) ?>
  <?= Ui::stat(t('cash.k_cashcard'), money($today['cash']) . ' / ' . money($today['card'])) ?>
  <?= Ui::stat(t('cash.k_fx'), \Sofrexa\Modules\Orders\Till::fxText($today['fx'])) ?>
</div>
<div class="stats only-mobile">
  <?= Ui::stat(t('cash.k_open_m'), money($openTotal), ['brand' => true]) ?>
  <?= Ui::stat(t('cash.k_taken_m'), money($today['total'])) ?>
</div>

<div class="tillbar">
  <div class="chips chips--scroll" data-till-filter>
    <?= $chip('all', t('ui.all')) ?>
    <?= Ui::chip(t('cash.f_tables'), false, digits($counts['table'] ?? 0), ['data-filter' => 'table', 'data-label-m' => t('cash.f_tables_m')]) ?>
    <?= $chip('takeaway', t('cash.f_takeaway')) ?>
    <?= $chip('delivery', t('cash.f_delivery')) ?>
    <?= $chip('online', t('cash.f_online')) ?>
  </div>
  <?= Ui::field('q', ['type' => 'search', 'icon' => 'search', 'placeholder' => t('cash.search'), 'class' => 'tillbar__search only-desktop', 'attrs' => ['data-till-search' => true]]) ?>
</div>

<?php if (!$cards): ?>
  <div class="empty"><?= icon('receipt', 24) ?><div><?= e(t('cash.empty')) ?></div></div>
<?php endif ?>
<div class="tcards">
  <?php foreach ($cards as $c):
      $b = $c['btn'];
      $btnAttrs = isset($b['post']) ? ['data-post' => $b['post'], 'data-reload' => true] : (isset($b['sheet']) ? ['data-load-sheet' => $b['sheet']] : []); ?>
    <article class="tcard<?= $c['border'] ? ' tcard--' . $c['border'] : '' ?>" data-channel="<?= e($c['channel']) ?>" data-q="<?= e($c['search']) ?>" data-href="<?= isset($b['href']) ? e($b['href']) : '' ?>">
      <div class="tcard__row">
        <span class="lrow__lead"><?= icon($c['icon'], 20) ?></span>
        <div class="tcard__mid">
          <div class="t-label-l ellipsis"><?= e($c['title']) ?></div>
          <div class="t-body-s c-muted ellipsis only-desktop"><?= e($c['sub']) ?></div>
          <div class="t-body-s c-muted ellipsis only-mobile"><?= e($c['sub_m']) ?></div>
        </div>
        <span class="only-desktop"><?= Ui::badge($c['badge'][0], $c['badge'][1], true) ?></span>
        <div class="tcard__end only-mobile"><span class="t-label-l num"><?= e(money($c['amount'])) ?></span><?= Ui::badge($c['badge_m'][0], $c['badge_m'][1]) ?></div>
      </div>
      <div class="tcard__row only-desktop">
        <span class="t-heading-l num grow"><?= e(money($c['amount'])) ?></span>
        <?= Ui::btn($b['label'], ['style' => $b['style'], 'size' => 's', 'href' => $b['href'] ?? null, 'attrs' => $btnAttrs]) ?>
      </div>
    </article>
  <?php endforeach ?>
</div>

<div class="scrim" id="till-more" hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead(t('cash.title')) ?>
    <div class="sheet__body">
      <div class="list list--flush">
        <?= Ui::lrow(t('cash.takeaway'), ['icon' => 'bag', 'href' => '/orders/new/takeaway']) ?>
        <?= Ui::lrow(t('cash.phone'), ['icon' => 'phone', 'href' => '/delivery/new']) ?>
        <?php if (can('cash.moves') && $shift): ?><?= Ui::lrow(t('cash.moves'), ['icon' => 'wallet', 'href' => '/cashier/moves']) ?><?php endif ?>
        <?php if (can('cash.rates')): ?><?= Ui::lrow(t('cash.rates'), ['icon' => 'currency', 'href' => '/cashier/rates']) ?><?php endif ?>
        <?php if (can('cash.shift')): ?><?= $shift ? Ui::lrow(t('cash.close_shift'), ['icon' => 'lock', 'href' => '/cashier/shift']) : Ui::lrow(t('shift.open_title'), ['icon' => 'lock', 'attrs' => ['data-load-sheet' => '/cashier/shift/open', 'data-action' => 'open']]) ?><?php endif ?>
        <?php if (can('cash.nosale') && $shift): ?><?= Ui::lrow(t('moves.drawer'), ['icon' => 'lock', 'attrs' => ['data-post' => '/cashier/nosale', 'data-action' => 'nosale']]) ?><?php endif ?>
      </div>
    </div>
  </div>
</div>
