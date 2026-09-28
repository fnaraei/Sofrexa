<?php
/**
 * My shift — Figma ST3 (42:508): the running time in a ring, today's sales / tables / estimated bonus,
 * the last shifts, end (or start) the shift. @var array $u @var ?array $open @var array $today @var array $history
 */
use Sofrexa\Core\I18n;
use Sofrexa\Modules\Staff\{Staff, Users};
use Sofrexa\View\Ui;

$roleLabel = Users::roleLabel($u['role_code'], $u['role_name']);
$appSub = $u['name'] . ' · ' . $roleLabel;
$sub = $appSub;
$back = '/more';
$bodyClass = 'page-myshift';
$since = $open ? (int) $open['at'] : null;
$elapsed = $since ? intdiv(max(0, \Sofrexa\Core\Clock::ms() - $since), 60_000) : 0;
$bottom = $open
    ? Ui::btn(t('staff.end'), ['style' => 'danger', 'size' => 'l', 'icon' => 'logout', 'block' => true, 'attrs' => ['data-post' => '/my/shift/out', 'data-confirm' => t('staff.end_q')]])
    : Ui::btn(t('staff.start'), ['size' => 'l', 'icon' => 'clock', 'block' => true, 'attrs' => ['data-post' => '/my/shift/in']]);
$courier = $u['role_code'] === 'courier';
$day = static function (int $ms): string {
    return t('date.d' . date('w', intdiv($ms, 1000))) . ' ' . digits(date('d.m', intdiv($ms, 1000)));
};
?>
<div class="myshift">
  <div class="ring<?= $open ? '' : ' ring--off' ?>" <?= $since ? 'data-since="' . $since . '"' : '' ?>>
    <span class="overline"><?= e($open ? t('staff.on_shift') : t('staff.off_shift')) ?></span>
    <span class="t-number-xl num" data-ring-time><?= e(digits(intdiv($elapsed, 60) . ':' . sprintf('%02d', $elapsed % 60))) ?></span>
    <span class="t-body-s c-muted"><?= e($open ? t('staff.since_t', ['t' => digits(date('H:i', intdiv($since, 1000)))]) : ($history ? t('staff.last_out', ['t' => when_label($history[0]['out'])]) : t('staff.never'))) ?></span>
  </div>
  <div class="ministats">
    <div class="card ministat"><span class="t-body-s c-muted"><?= e(t('staff.today_sales')) ?></span><span class="t-heading-m num"><?= e(money($today['sales'])) ?></span></div>
    <div class="card ministat"><span class="t-body-s c-muted"><?= e($courier ? t('staff.deliveries') : t('staff.tables')) ?></span><span class="t-heading-m num"><?= e(digits(I18n::num($courier ? $today['deliveries'] : $today['tables']))) ?></span></div>
    <div class="card ministat"><span class="t-body-s c-muted"><?= e(t('staff.est_bonus')) ?></span><span class="t-heading-m num"><?= e(money($today['bonus'])) ?></span></div>
  </div>
  <div class="card shiftlist">
    <?php foreach ($history as $h): ?>
      <div class="shiftrow">
        <span class="grow col" style="gap:0"><span class="t-label-m"><?= e($day($h['in'])) ?></span><span class="t-body-s c-muted num"><?= e(digits(date('H:i', intdiv($h['in'], 1000)) . '–' . date('H:i', intdiv($h['out'], 1000)))) ?></span></span>
        <span class="t-label-m c-secondary"><?= e(Staff::duration($h['ms'])) ?></span>
      </div>
    <?php endforeach ?>
    <?php if (!$history): ?><div class="empty"><?= e(t('staff.no_history')) ?></div><?php endif ?>
  </div>
</div>
