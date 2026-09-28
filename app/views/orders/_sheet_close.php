<?php
/** Close (cancel) an unpaid table (W7 › Masayı kapat). @var array $o */
use Sofrexa\View\Ui;

$sent = array_filter($o['lines'], static fn(array $l): bool => !in_array($l['status'], ['new', 'void'], true));
?>
<div class="scrim" data-dyn hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead(t('close.title')) ?>
    <form class="sheet__body" method="post" action="/orders/<?= e($o['id']) ?>/close" data-ajax data-toast="off">
      <?= csrf_field() ?>
      <p class="t-body-m c-muted"><?= e(t('close.help')) ?></p>
      <?php if ($sent): ?>
        <div class="chips chips--wrap" data-fill="reason">
          <?php foreach (['line.r_changed', 'line.r_wrong', 'line.r_late', 'line.r_bad'] as $k): ?><?= Ui::chip(t($k), false, null, ['data-value' => t($k)]) ?><?php endforeach ?>
        </div>
        <?= Ui::field('reason', ['label' => t('line.reason'), 'icon' => 'note']) ?>
      <?php endif ?>
      <div class="sheet__actions"><?= Ui::btn(t('ui.cancel'), ['style' => 'secondary', 'size' => 'l', 'attrs' => ['data-close' => true]]) ?><?= Ui::btn(t('close.do'), ['type' => 'submit', 'style' => 'danger', 'size' => 'l', 'icon' => 'x-circle']) ?></div>
    </form>
  </div>
</div>
