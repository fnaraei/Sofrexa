<?php
/**
 * Table actions sheet — Figma W7 (20:804): nine option tiles for a busy table.
 * @var array $t table with 'area' and the full 'order'
 */
use Sofrexa\View\Ui;

$o = $t['order'];
$area = tn(json_arr($t['area']['names']) ?: $t['area']['name']);
$base = '/orders/' . $o['id'];
$tile = static function (string $icon, string $label, string $sub, array $attrs): string {
    $tag = isset($attrs['href']) ? 'a' : 'button';
    if ($tag === 'button') {
        $attrs += ['type' => 'button'];
    }
    return '<' . $tag . Ui::attrs($attrs + ['class' => 'opt']) . '>' . icon($icon, 24) . '<span class="opt__label ellipsis">' . e($label) . '</span><span class="opt__sub ellipsis">' . e($sub) . '</span></' . $tag . '>';
};
?>
<div class="scrim" data-dyn hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead(t('order.table_area', ['n' => digits($t['number']), 'area' => $area])) ?>
    <div class="tact__meta">
      <?= Ui::badge(t('tables.busy_t', ['t' => dur((int) $o['opened_at'])]), 'accent', true) ?>
      <span class="t-body-s c-muted ellipsis"><?= e(t('tables.sheet_sub', ['g' => digits(max(1, (int) $o['guests'])), 'amount' => money((int) $o['total']), 'waiter' => first_name($o['waiter_name'] ?? '—')])) ?></span>
    </div>
    <div class="tact">
      <?= $tile('plus', t('act.add'), t('act.add_s'), ['href' => $base]) ?>
      <?= $tile('printer', t('act.prebill'), t('act.prebill_s'), ['data-post' => $base . '/prebill']) ?>
      <?= $tile('transfer', t('act.move'), t('act.move_s'), ['data-load-sheet' => $base . '/sheet/move']) ?>
      <?= $tile('merge', t('act.merge'), t('act.merge_s'), ['data-load-sheet' => $base . '/sheet/merge']) ?>
      <?= $tile('split', t('act.split'), t('act.split_s'), ['data-load-sheet' => $base . '/sheet/split']) ?>
      <?= $tile('users', t('act.guests'), t('act.guests_s', ['n' => digits(max(1, (int) $o['guests']))]), ['data-load-sheet' => $base . '/sheet/guests']) ?>
      <?= $tile('user', t('act.waiter'), t('act.waiter_s', ['name' => first_name($o['waiter_name'] ?? '—')]), ['data-load-sheet' => $base . '/sheet/waiter']) ?>
      <?= $tile('receipt', t('act.bill'), t('act.bill_s'), ['data-post' => $base . '/bill']) ?>
      <?= $tile('x-circle', t('act.close'), t('act.close_s'), ['data-load-sheet' => $base . '/sheet/close']) ?>
    </div>
  </div>
</div>
