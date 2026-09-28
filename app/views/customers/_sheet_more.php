<?php
/** More actions of the phone account screen (CU4 "more"): edit, statement, history, tier, points. @var array $c */
use Sofrexa\View\Ui;

$id = $c['id'];
?>
<div class="scrim" data-dyn hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead($c['name']) ?>
    <div class="sheet__body">
      <div class="list list--flush">
        <?php if (can('customers.manage')): ?><?= Ui::lrow(t('cust.edit'), ['icon' => 'pencil', 'attrs' => ['data-load-sheet' => '/customers/' . $id . '/sheet/edit', 'data-action' => 'edit']]) ?><?php endif ?>
        <?= Ui::lrow(t('cust.statement_print'), ['icon' => 'printer', 'href' => '/customers/' . $id . '/statement', 'attrs' => ['target' => '_blank']]) ?>
        <?= Ui::lrow(t('cust.v_orders'), ['icon' => 'receipt', 'href' => '/customers/' . $id . '?v=orders']) ?>
        <?= Ui::lrow(t('cust.v_points'), ['icon' => 'history', 'href' => '/customers/' . $id . '?v=points']) ?>
        <?php if (can('customers.manage')): ?>
          <?= Ui::lrow(t('cust.tier_t'), ['icon' => 'star', 'attrs' => ['data-load-sheet' => '/customers/' . $id . '/sheet/tier', 'data-action' => 'tier']]) ?>
          <?= Ui::lrow(t('loy.adjust'), ['icon' => 'sparkles', 'attrs' => ['data-load-sheet' => '/customers/' . $id . '/sheet/points', 'data-action' => 'points']]) ?>
        <?php endif ?>
      </div>
    </div>
  </div>
</div>
