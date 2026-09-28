<?php
/**
 * Activity log — Figma ST6 (93:813, desktop) and ST7 (93:1214, mobile).
 * @var array $rows @var int $total @var array $counts @var int $week @var string $group @var string $q
 * @var string $day @var int $dayMs @var bool $isToday @var int $page @var int $perPage @var array $chips
 */
use Sofrexa\Core\Audit;
use Sofrexa\Core\I18n;
use Sofrexa\Modules\Staff\Users;
use Sofrexa\View\Ui;

$keep = array_filter(['day' => $isToday ? null : $day, 'q' => $q !== '' ? $q : null]);
$dayLabel = $isToday ? t('ui.today') : I18n::date($dayMs, 'full');
$sub = t('audit.sub');
$appSub = t('audit.sub_mobile', ['day' => $dayLabel, 'n' => digits($total)]);
$headActions = Ui::btn(t('audit.export'), ['style' => 'secondary', 'icon' => 'file-sheet', 'href' => url('/staff/audit/export', array_filter(['day' => $day, 'g' => $group ?: null, 'q' => $q ?: null]))]);
$appActions = [Ui::ibtn('filter', t('ui.search'), ['class' => 'appbar__act', 'attrs' => ['data-sheet' => 'audit-filter']])];
$from = $total ? ($page - 1) * $perPage + 1 : 0;
$to = min($total, $page * $perPage);
$first = static fn(?string $name): string => explode(' ', trim((string) $name))[0];
?>
<form class="toolbar only-desktop" method="get" action="/staff/audit" data-autosubmit>
  <?php if ($group !== ''): ?><input type="hidden" name="g" value="<?= e($group) ?>"><?php endif ?>
  <?= Ui::field('q', ['type' => 'search', 'icon' => 'search', 'value' => $q, 'placeholder' => t('audit.search'), 'class' => 'toolbar__search']) ?>
  <div class="chips">
    <?= Ui::chip(t('ui.all'), $group === '', digits($counts['']), ['href' => url('/staff/audit', $keep)]) ?>
    <?php foreach ($chips as $g): ?>
      <?= Ui::chip(t('audit.f.' . $g), $group === $g, digits($counts[$g]), ['href' => url('/staff/audit', $keep + ['g' => $g])]) ?>
    <?php endforeach ?>
  </div>
  <label class="datepill"><?= icon('calendar', 18) ?><span><?= e(I18n::date($dayMs, 'full')) ?></span><input type="date" name="day" value="<?= e($day) ?>" max="<?= e(date('Y-m-d')) ?>" aria-label="<?= e(t('audit.col_time')) ?>"></label>
</form>

<div class="chips only-mobile">
  <?= Ui::chip(t('ui.all'), $group === '', null, ['href' => url('/staff/audit', $keep)]) ?>
  <?php foreach (['void', 'discount', 'cash', 'price', 'login'] as $g): ?>
    <?= Ui::chip(t('audit.f.' . $g), $group === $g, null, ['href' => url('/staff/audit', $keep + ['g' => $g])]) ?>
  <?php endforeach ?>
</div>
<div class="row gap-8 only-mobile c-muted t-body-s"><?= icon('lock', 16) ?><span><?= e(t('audit.locked_note')) ?></span></div>

<?php if (!$rows): ?>
  <div class="empty"><?= e(t('audit.empty')) ?></div>
<?php else: ?>
<div class="dtable dtable--dense only-desktop">
  <table>
    <thead><tr>
      <th style="width:56px"><?= e(t('audit.col_time')) ?></th>
      <th style="width:180px"><?= e(t('audit.col_user')) ?></th>
      <th style="width:150px"><?= e(t('audit.col_action')) ?></th>
      <th><?= e(t('audit.col_detail')) ?></th>
      <th style="width:150px"><?= e(t('audit.col_device')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): [$label, $tone] = Audit::meta($r['action']); ?>
      <tr>
        <td class="t-label-m c-primary num"><?= e(digits(date('H:i', intdiv($r['at'], 1000)))) ?></td>
        <td><?= $r['user_name'] ? Ui::who($r['user_name'], $r['role_code'] ? Users::roleLabel($r['role_code'], (string) $r['role_name']) : null) : '<span class="c-muted">' . e(t('audit.system')) . '</span>' ?></td>
        <td><?= Ui::badge(t($label), $tone, true) ?></td>
        <td><?= e($r['summary']) ?></td>
        <td class="nowrap"><?= e($r['device']) ?></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <div class="dtable__foot">
    <span class="grow"><?= e(t('audit.foot', ['from' => digits($from), 'to' => digits($to), 'n' => digits($total), 'day' => $isToday ? t('audit.day_today') : I18n::date($dayMs, 'short'), 'week' => I18n::num($week)])) ?></span>
    <?= Ui::ibtn('chevron-left', t('ui.prev'), ['style' => 'secondary', 'size' => 's', 'href' => $page > 1 ? url('/staff/audit', $keep + array_filter(['g' => $group ?: null, 'page' => $page - 1])) : null, 'attrs' => $page > 1 ? [] : ['disabled' => true]]) ?>
    <?= Ui::ibtn('chevron-right', t('ui.next'), ['style' => 'secondary', 'size' => 's', 'href' => $to < $total ? url('/staff/audit', $keep + array_filter(['g' => $group ?: null, 'page' => $page + 1])) : null, 'attrs' => $to < $total ? [] : ['disabled' => true]]) ?>
  </div>
</div>

<div class="list only-mobile">
  <?php foreach ($rows as $r): [$label, $tone, $ic] = Audit::meta($r['action']); ?>
    <div class="evt">
      <span class="evt__ic evt__ic--<?= e($tone) ?>"><?= icon($ic, 18) ?></span>
      <span class="evt__mid"><span class="t-label-m"><?= e(($r['user_name'] ? $first($r['user_name']) : t('audit.system')) . ' · ' . t($label)) ?></span><span class="t-body-s c-muted"><?= e($r['summary']) ?></span></span>
      <span class="t-body-s c-muted num"><?= e(digits(date('H:i', intdiv($r['at'], 1000)))) ?></span>
    </div>
  <?php endforeach ?>
  <?php if ($to < $total): ?>
    <a class="evt__more" href="<?= e(url('/staff/audit', $keep + array_filter(['g' => $group ?: null, 'page' => $page + 1]))) ?>"><?= e(t('ui.more')) ?></a>
  <?php endif ?>
</div>
<?php endif ?>

<div class="scrim" id="audit-filter" hidden>
  <form class="sheet" method="get" action="/staff/audit">
    <?= Ui::sheetHead(t('ui.search')) ?>
    <div class="sheet__body">
      <?php if ($group !== ''): ?><input type="hidden" name="g" value="<?= e($group) ?>"><?php endif ?>
      <?= Ui::field('q', ['type' => 'search', 'icon' => 'search', 'value' => $q, 'placeholder' => t('audit.search'), 'id' => 'f-q-m']) ?>
      <?= Ui::field('day', ['type' => 'date', 'icon' => 'calendar', 'value' => $day, 'label' => t('audit.col_time'), 'id' => 'f-day-m']) ?>
      <div class="sheet__actions">
        <?= Ui::btn(t('audit.export'), ['style' => 'secondary', 'size' => 'l', 'icon' => 'file-sheet', 'href' => url('/staff/audit/export', array_filter(['day' => $day, 'g' => $group ?: null, 'q' => $q ?: null]))]) ?>
        <?= Ui::btn(t('ui.search'), ['size' => 'l', 'icon' => 'search', 'type' => 'submit']) ?>
      </div>
    </div>
  </form>
</div>
