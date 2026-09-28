<?php
/** Manager's point correction (+/−, with a reason; written to the activity log). @var array $c @var int $points */
use Sofrexa\Core\I18n;
use Sofrexa\View\Ui;

?>
<div class="scrim" data-dyn hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead(t('loy.adjust')) ?>
    <form class="sheet__body" method="post" action="/customers/<?= e($c['id']) ?>/points" data-ajax data-toast="off">
      <?= csrf_field() ?>
      <?= Ui::who($c['name'], t('loy.points_n', ['n' => digits(I18n::num($points))])) ?>
      <?= Ui::segs(['plus' => t('loy.add'), 'minus' => t('loy.remove')], 'plus', 'dir') ?>
      <?= Ui::field('points', ['label' => t('loy.points'), 'icon' => 'star', 'attrs' => ['inputmode' => 'numeric', 'required' => true, 'autofocus' => true]]) ?>
      <?= Ui::field('note', ['label' => t('loy.reason'), 'icon' => 'note', 'attrs' => ['required' => true]]) ?>
      <p class="t-body-s c-muted"><?= e(t('cust.tier_logged')) ?></p>
      <div class="sheet__actions">
        <?= Ui::btn(t('ui.cancel'), ['style' => 'secondary', 'size' => 'l', 'attrs' => ['data-close' => true]]) ?>
        <?= Ui::btn(t('ui.save'), ['size' => 'l', 'icon' => 'check', 'type' => 'submit']) ?>
      </div>
    </form>
  </div>
</div>
