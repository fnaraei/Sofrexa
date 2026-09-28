<?php
/**
 * Loyalty program — Figma CU5 (41:425): KPIs; rules (point value, tier window, minimum use, expiry, three switches);
 * tiers (annual spending threshold, discount %, earn %; add, rename, recolour, remove); the most loyal customers.
 * @var array $kpi @var array $tiers @var array $top @var array $set
 */
use Sofrexa\Core\I18n;
use Sofrexa\View\Ui;

$n = static fn(int $v): string => digits(I18n::num($v));
$pct = static fn(float $p): string => '%' . digits(I18n::numAuto($p));
$pv = (int) $set['loyalty.point_value'];
$noHead = true;
$back = '/customers';
$appSub = t('cust.title');
$bodyClass = 'page-loyalty';
$bottom = Ui::btn(t('ui.save'), ['size' => 'l', 'icon' => 'check', 'block' => true, 'type' => 'submit', 'attrs' => ['form' => 'loyalty-form']]);
$delta = $kpi['earned_prev'] > 0 ? (int) round(($kpi['earned'] - $kpi['earned_prev']) * 100 / $kpi['earned_prev']) : null;
$tierNames = array_column($tiers, null, 'id');
$monthsOpt = static fn(array $list): array => array_combine($list, array_map(static fn(int $m): string => $m === 0 ? t('loy.never') : t('loy.last_months', ['n' => digits($m)]), $list));
$expOpt = array_combine([6, 12, 24, 36, 0], array_map(static fn(int $m): string => $m === 0 ? t('loy.never') : t('loy.months', ['n' => digits($m)]), [6, 12, 24, 36, 0]));
?>
<form class="loy" id="loyalty-form" method="post" action="/customers/loyalty" data-ajax data-toast="off" data-loyalty>
  <?= csrf_field() ?>
  <div class="page-head only-desktop">
    <div class="page-head__titles"><h1 class="t-heading-xl"><?= e($title) ?></h1><p class="t-body-m c-muted"><?= e(t('loy.sub')) ?></p></div>
    <?= Ui::btn(t('ui.save'), ['icon' => 'check', 'type' => 'submit']) ?>
  </div>

  <div class="stats stats--4">
    <?= Ui::stat(t('loy.k_members'), $n($kpi['members']), ['brand' => true, 'delta' => $kpi['new'] ? t('loy.k_members_d', ['n' => $n($kpi['new'])]) : '']) ?>
    <?= Ui::stat(t('loy.k_earned'), t('loy.points_n', ['n' => $n($kpi['earned'])]), ['delta' => $delta !== null ? t('loy.k_earned_d', ['p' => ($delta >= 0 ? '+' : '−') . '%' . digits(abs($delta))]) : '', 'down' => $delta !== null && $delta < 0]) ?>
    <?= Ui::stat(t('loy.k_used'), t('loy.points_n', ['n' => $n($kpi['used'])]), ['delta' => t('loy.k_used_d', ['amount' => money($kpi['used'] * $pv)])]) ?>
    <?= Ui::stat(t('loy.k_unused'), $n($kpi['unused']), ['delta' => t('loy.k_unused_d', ['amount' => money($kpi['unused'] * $pv)])]) ?>
  </div>

  <div class="loy__body">
    <div class="loy__left">
      <section class="card loycard">
        <h2 class="t-heading-s"><?= e(t('loy.rules')) ?></h2>
        <div class="grid2 grid2--keep">
          <div class="field"><label class="field__label" for="f-point_value"><?= e(t('loy.point_value')) ?></label>
            <div class="field__box"><?= icon('currency', 20) ?><span class="field__prefix"><?= e(t('loy.one_point')) ?></span><input id="f-point_value" name="point_value" value="<?= e(money($pv)) ?>" inputmode="decimal" autocomplete="off" required></div></div>
          <?= Ui::select('tier_window', $monthsOpt([3, 6, 12, 24]), (string) $set['loyalty.tier_window_months'], ['label' => t('loy.window'), 'icon' => 'history']) ?>
          <?= Ui::field('min_redeem', ['label' => t('loy.min'), 'icon' => 'tag', 'value' => (string) (int) $set['loyalty.min_redeem'], 'suffix' => t('loy.points'), 'attrs' => ['inputmode' => 'numeric']]) ?>
          <?= Ui::select('expiry', $expOpt, (string) $set['loyalty.expiry_months'], ['label' => t('loy.expiry'), 'icon' => 'calendar']) ?>
        </div>
        <div class="trows">
          <?= Ui::toggleRow('no_points_on_discounted', t('loy.r_discounted'), null, (bool) $set['loyalty.no_points_on_discounted']) ?>
          <?= Ui::toggleRow('points_on_delivery', t('loy.r_delivery'), null, (bool) $set['loyalty.points_on_delivery']) ?>
          <?= Ui::toggleRow('no_points_on_account', t('loy.r_account'), null, (bool) $set['loyalty.no_points_on_account']) ?>
        </div>
      </section>

      <section class="card loycard loycard--tiers">
        <div class="row gap-8"><h2 class="t-heading-s grow"><?= e(t('loy.tiers')) ?></h2><?= Ui::btn(t('loy.tier_add'), ['style' => 'ghost', 'size' => 's', 'icon' => 'plus', 'attrs' => ['data-tier-add' => true]]) ?></div>
        <div class="tierrow tierrow--head"><span><?= e(t('loy.c_tier')) ?></span><span><?= e(t('loy.c_spend')) ?></span><span><?= e(t('loy.c_discount')) ?></span><span><?= e(t('loy.c_earn')) ?></span></div>
        <div data-tier-rows>
          <?php foreach ($tiers as $t): ?>
            <div class="tierrow">
              <span><button type="button" class="tierbadge" data-load-sheet="/customers/loyalty/tier/<?= e($t['id']) ?>" title="<?= e(t('loy.tier_edit')) ?>"><?= Ui::badge($t['name'], $t['tone']) ?></button></span>
              <input class="tierfield" name="tiers[<?= e($t['id']) ?>][threshold]" value="<?= e(money((int) $t['threshold']) . ' +') ?>" inputmode="decimal" aria-label="<?= e(t('loy.c_spend') . ' · ' . $t['name']) ?>" data-tf="money">
              <input class="tierfield" name="tiers[<?= e($t['id']) ?>][discount_pct]" value="<?= e($pct((float) $t['discount_pct'])) ?>" inputmode="decimal" aria-label="<?= e(t('loy.c_discount') . ' · ' . $t['name']) ?>" data-tf="pct">
              <input class="tierfield" name="tiers[<?= e($t['id']) ?>][earn_pct]" value="<?= e($pct((float) $t['earn_pct'])) ?>" inputmode="decimal" aria-label="<?= e(t('loy.c_earn') . ' · ' . $t['name']) ?>" data-tf="pct">
            </div>
          <?php endforeach ?>
        </div>
        <template data-tier-tpl>
          <div class="tierrow tierrow--new">
            <span><input class="tierfield tierfield--name" name="new[__i][name]" placeholder="<?= e(t('loy.tier_name')) ?>" maxlength="40" aria-label="<?= e(t('loy.tier_name')) ?>"><input type="hidden" name="new[__i][tone]" value="Neutral"></span>
            <input class="tierfield" name="new[__i][threshold]" placeholder="₺0 +" inputmode="decimal" aria-label="<?= e(t('loy.c_spend')) ?>" data-tf="money">
            <input class="tierfield" name="new[__i][discount_pct]" placeholder="%0" inputmode="decimal" aria-label="<?= e(t('loy.c_discount')) ?>" data-tf="pct">
            <input class="tierfield" name="new[__i][earn_pct]" placeholder="%0" inputmode="decimal" aria-label="<?= e(t('loy.c_earn')) ?>" data-tf="pct">
          </div>
        </template>
        <p class="t-body-s c-muted"><?= e(t('loy.tiers_note')) ?></p>
      </section>
    </div>

    <div class="loy__right">
      <section class="card card--pad0 loytop">
        <div class="ledger__title"><h2 class="t-heading-s grow"><?= e(t('loy.top')) ?></h2><?= Ui::chip(t('loy.this_year'), true, null, ['class' => 'chip chip--static is-selected']) ?></div>
        <div class="toprow toprow--head"><span><?= e(t('cust.c_customer')) ?></span><span><?= e(t('loy.c_tier')) ?></span><span><?= e(t('loy.c_spent')) ?></span><span><?= e(t('cust.c_points')) ?></span><span><?= e(t('cust.c_last')) ?></span></div>
        <?php foreach ($top as $r): $tr = $r['tier_id'] !== null ? ($tierNames[$r['tier_id']] ?? null) : null; ?>
          <a class="toprow" href="/customers/<?= e($r['id']) ?>">
            <?= Ui::who($r['name'], (string) $r['phone']) ?>
            <span><?= $tr ? Ui::badge($tr['name'], $tr['tone']) : '' ?></span>
            <span class="t-body-m c-secondary num"><?= e(money((int) $r['spend'])) ?></span>
            <span class="t-body-m c-secondary num"><?= e($n((int) $r['points'])) ?></span>
            <span class="t-body-m c-secondary num"><?= e(digits(date('d.m', intdiv((int) $r['last_at'], 1000)))) ?></span>
          </a>
        <?php endforeach ?>
        <?php if (!$top): ?><div class="empty"><?= e(t('loy.top_none')) ?></div><?php endif ?>
      </section>
      <div class="note"><?= icon('info', 20) ?><span class="grow"><?= e(t('loy.info')) ?></span></div>
    </div>
  </div>
</form>
