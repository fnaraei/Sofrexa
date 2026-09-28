<?php
/**
 * Emergency mode card on the web copy (stage 13; no Figma frame — built from Section, Banner and Button).
 * Off: what it does, and the switch (only while the till PC is not in touch). On: since when, by whom, and "Kapat".
 */
use Sofrexa\Sync\{Emergency, Status};
use Sofrexa\View\Ui;

$s = Emergency::state();
$pcAway = Status::get()['state'] === 'offline';
?>
<section class="section">
  <div class="section__head"><h2 class="section__title"><?= e(t('emg.title')) ?></h2><p class="section__sub"><?= e(t('emg.sub')) ?></p></div>
  <?php if ($s): ?>
    <?= Ui::banner(t('emg.on_t'), t('emg.on', ['time' => digits(when_label((int) $s['at'])), 'name' => first_name((string) $s['name'])]), 'warning', 'alert') ?>
    <p class="t-body-s c-muted"><?= e(t('emg.on_help')) ?></p>
    <div><?= Ui::btn(t('emg.stop'), ['style' => 'secondary', 'icon' => 'check', 'attrs' => ['data-post' => '/settings/emergency', 'data-body' => json_encode(['on' => 0]), 'data-confirm' => t('emg.stop_confirm')]]) ?></div>
  <?php else: ?>
    <ul class="emglist t-body-s c-secondary">
      <li><?= e(t('emg.l1')) ?></li><li><?= e(t('emg.l2')) ?></li><li><?= e(t('emg.l3')) ?></li><li><?= e(t('emg.l4')) ?></li>
    </ul>
    <?php if (!$pcAway): ?><p class="t-body-s c-muted"><?= e(t('emg.err_online')) ?></p><?php endif ?>
    <div><?= Ui::btn(t('emg.start'), ['style' => 'danger', 'icon' => 'alert', 'attrs' => ['data-post' => '/settings/emergency', 'data-body' => json_encode(['on' => 1]), 'data-confirm' => t('emg.start_confirm'), 'disabled' => !$pcAway]]) ?></div>
  <?php endif ?>
</section>
