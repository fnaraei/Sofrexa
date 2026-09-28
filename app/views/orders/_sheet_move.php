<?php
/** Move a table's order to a free table (W7 › Masayı taşı). @var array $o @var array $areas (each with 'free') */
use Sofrexa\View\Ui;
?>
<div class="scrim" data-dyn hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead(t('move.title')) ?>
    <form class="sheet__body" method="post" action="/orders/<?= e($o['id']) ?>/move" data-ajax data-toast="off">
      <?= csrf_field() ?>
      <p class="t-body-m c-muted"><?= e(t('move.help')) ?></p>
      <?php foreach ($areas as $a): if (!$a['free']) continue; ?>
        <div class="overline"><?= e(tn(json_arr($a['names']) ?: $a['name'])) ?></div>
        <div class="chips chips--wrap">
          <?php foreach ($a['free'] as $t): ?><?= Ui::chipRadio('table_id', $t['id'], t('order.table', ['n' => digits($t['number'])])) ?><?php endforeach ?>
        </div>
      <?php endforeach ?>
      <div class="sheet__actions"><?= Ui::btn(t('ui.cancel'), ['style' => 'secondary', 'size' => 'l', 'attrs' => ['data-close' => true]]) ?><?= Ui::btn(t('move.title'), ['type' => 'submit', 'size' => 'l', 'icon' => 'transfer']) ?></div>
    </form>
  </div>
</div>
