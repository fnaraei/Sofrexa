<?php
/**
 * Cancelled dishes of the kitchen — Figma IP1 (128:1409, desktop: four StatCards, a table with the till's decisions, a note)
 * and IP2 (133:1742, phone: chips and a list). Only sent → "Hazırlanmadı · stoka dön" / "Hazırlandı · zayi"; waste →
 * "Masaya ver" (IP3) / "Personele yaz" (IP4); the settled ones say what became of them.
 * @var array $rows @var array $stats @var string $filter
 */
use Sofrexa\Core\{I18n, Clock};
use Sofrexa\View\Ui;

$tr = I18n::lang() === 'tr';
$to = static fn(string $s): string => $tr ? I18n::trDative($s) : $s;
$sub = t('voids.sub');
$appSub = $stats['pending'] ? t('voids.sub_m', ['n' => digits($stats['pending'])]) : t('voids.sub');
$headActions = Ui::btn(t('voids.report'), ['style' => 'secondary', 'icon' => 'file-text', 'href' => '/reports/voids']);
$bodyClass = 'page-voids';
$chips = static fn(string $key, string $label, int $n): string => Ui::chip($label, $filter === $key, digits($n), ['href' => $key === 'today' ? '/cashier/voids' : '/cashier/voids?f=' . $key]);
$chipRow = $chips('today', t('voids.f_today'), $stats['all']) . $chips('pending', t('voids.f_pending'), $stats['pending']) . $chips('waste', t('voids.f_waste'), $stats['waste_n']);
$badge = static fn(string $st): string => match ($st) {
    'pending' => Ui::badge(t('voids.s_pending'), 'attention', true),
    'waste' => Ui::badge(t('voids.s_waste'), 'danger', true),
    'table' => Ui::badge(t('voids.b_table'), 'success', true),
    'staff' => Ui::badge(t('voids.b_staff'), 'info', true),
    default => Ui::badge(t('voids.s_returned'), 'neutral', true),
};
$stage = static fn(array $r): string => '<span class="voidstage voidstage--' . $r['stage'] . '">' . icon($r['stage'] === 'ready' ? 'bell' : 'check', 14)
    . '<span>' . e(t('voids.stage_' . $r['stage'])) . '</span></span>';
$hm = static fn(?int $ms): string => $ms ? digits(Clock::fmt($ms, 'H:i')) : '';
$place = static fn(array $r): string => $r['where'] . ($r['area'] ? ' · ' . tn($r['area']) : '');
$done = static function (array $r) use ($to, $hm): string {
    $what = match ($r['state']) {
        'table' => t('voids.s_table', ['to' => $to((string) $r['to']), 'where' => (string) $r['to']]),
        'staff' => t('voids.s_staff', ['to' => $to(first_name((string) $r['to'])), 'name' => first_name((string) $r['to']), 'amount' => money($r['amount'])]),
        default => t('voids.s_returned'),
    };
    return implode(' · ', array_filter([$what, $hm($r['done_at']), first_name($r['done_by'])]));
};
// the decision buttons of a row (IP1 actions cell, IP2 actions row)
$actions = static function (array $r, bool $phone): string {
    $base = '/cashier/voids/' . $r['id'];
    if ($r['state'] === 'pending') {
        return Ui::btn(t('notif.void_back'), ['style' => 'secondary', 'size' => 's', 'class' => $phone ? 'grow' : '', 'attrs' => ['data-post' => $base . '/settle', 'data-body' => '{"how":"returned"}']])
            . Ui::btn(t('notif.void_waste'), ['size' => 's', 'attrs' => ['data-post' => $base . '/settle', 'data-body' => '{"how":"waste"}']]);
    }
    if ($r['state'] === 'waste') {
        return Ui::btn(t('voids.give_table'), ['style' => 'secondary', 'size' => 's', 'class' => $phone ? 'grow' : '', 'attrs' => ['data-load-sheet' => $base . '/sheet/table']])
            . Ui::btn(t('voids.give_staff'), ['style' => 'secondary', 'size' => 's', 'class' => $phone ? 'grow' : '', 'attrs' => ['data-load-sheet' => $base . '/sheet/staff']]);
    }
    return '';
};
?>
<div class="only-desktop col gap-18">
  <div class="stats stats--4">
    <?= Ui::stat(t('voids.st_pending'), digits($stats['pending']), ['brand' => true, 'icon' => 'chef-hat', 'tone' => 'accent',
        'delta' => $stats['pending'] ? t('voids.d_pending', ['amount' => money($stats['pending_amount'])]) : '']) ?>
    <?= Ui::stat(t('voids.st_waste'), money($stats['waste']), ['icon' => 'trash', 'tone' => 'down',
        'delta' => $stats['waste_n'] ? t('voids.st_waste_cost', ['amount' => money($stats['waste_cost'])]) : '']) ?>
    <?= Ui::stat(t('voids.st_table'), digits($stats['table']), ['icon' => 'transfer', 'tone' => 'up',
        'delta' => $stats['table'] ? t('voids.d_table', ['amount' => money($stats['table_amount']), 'to' => $to((string) $stats['table_last']), 'where' => (string) $stats['table_last']]) : '']) ?>
    <?= Ui::stat(t('voids.st_staff'), digits($stats['staff']), ['icon' => 'user',
        'delta' => $stats['staff'] ? t('voids.d_staff', ['amount' => money($stats['staff_amount'])]) : '']) ?>
  </div>
  <section class="card card--pad0 voidtable">
    <div class="voidtable__title"><h2 class="grow t-heading-s"><?= e(t('voids.list')) ?></h2><div class="chips"><?= $chipRow ?></div></div>
    <div class="voidrow voidrow--head"><span><?= e(t('voids.c_dish')) ?></span><span><?= e(t('voids.c_from')) ?></span><span><?= e(t('voids.c_reason')) ?></span>
      <span class="tend"><?= e(t('voids.c_value')) ?></span><span class="tend"><?= e(t('voids.c_action')) ?></span></div>
    <?php foreach ($rows as $r): ?>
      <div class="voidrow">
        <span class="col gap-2"><span class="t-label-l"><?= e(digits(\Sofrexa\Modules\Orders\Orders::qtyText($r['qty'])) . '× ' . $r['name']) ?></span>
          <?php if ($r['mods']): ?><span class="t-body-s c-muted"><?= e(implode(' · ', $r['mods'])) ?></span><?php endif ?></span>
        <span class="col gap-2"><span class="t-body-m c-secondary"><?= e($place($r)) ?></span><span class="t-body-s c-muted"><?= e(implode(' · ', array_filter([first_name($r['by']), $hm($r['at'])]))) ?></span></span>
        <span class="col gap-4"><span class="t-body-m c-secondary"><?= e($r['reason']) ?></span><?= $stage($r) ?></span>
        <span class="col gap-4 end-a"><span class="t-label-l num"><?= e(money($r['amount'])) ?></span><?= $badge($r['state']) ?></span>
        <span class="voidrow__acts"><?php $a = $actions($r, false); ?><?= $a !== '' ? $a : '<span class="t-body-s c-muted">' . e($done($r)) . '</span>' ?></span>
      </div>
    <?php endforeach ?>
    <?php if (!$rows): ?><div class="empty"><?= icon('check-circle', 24) ?><div class="t-label-l"><?= e(t('voids.empty')) ?></div></div><?php endif ?>
  </section>
  <p class="row gap-10 t-body-s c-muted voidnote"><?= icon('info', 18) ?><span><?= e(t('voids.note')) ?></span></p>
</div>

<div class="only-mobile col gap-12">
  <div class="chips chips--scroll"><?= $chipRow ?></div>
  <div class="card voidlist">
    <?php foreach ($rows as $r): ?>
      <div class="voiditem">
        <div class="row gap-12 start voiditem__top">
          <span class="grow col gap-2"><span class="t-label-l"><?= e(digits(\Sofrexa\Modules\Orders\Orders::qtyText($r['qty'])) . '× ' . $r['name']) ?></span>
            <?php if ($r['mods']): ?><span class="t-body-s c-muted"><?= e(implode(' · ', $r['mods'])) ?></span><?php endif ?>
            <span class="t-body-s c-muted"><?= e(implode(' · ', array_filter([$place($r), first_name($r['by']), $hm($r['at'])]))) ?></span></span>
          <span class="col gap-4 end-a"><span class="t-label-l num"><?= e(money($r['amount'])) ?></span><?= $badge($r['state']) ?></span>
        </div>
        <div class="row gap-6"><?= $stage($r) ?><span class="t-body-s c-muted ellipsis">· <?= e($r['reason']) ?></span></div>
        <?php $a = $actions($r, true); if ($a !== ''): ?><div class="row gap-8"><?= $a ?></div><?php endif ?>
      </div>
    <?php endforeach ?>
    <?php if (!$rows): ?><div class="empty"><?= icon('check-circle', 24) ?><div class="t-label-l"><?= e(t('voids.empty')) ?></div></div><?php endif ?>
  </div>
</div>
