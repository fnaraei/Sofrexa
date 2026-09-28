<?php
/**
 * QR order approval — Figma W5 (19:443): the guests' first order of a table session, with their note; "Reddet" / "Onayla".
 * @var array $o @var array $orders @var array $lines @var array $table @var bool $first @var int $guests @var int $total
 */
use Sofrexa\View\OrderUi;
use Sofrexa\View\Ui;

$area = tn(json_arr($table['area_names']) ?: $table['area_name']);
$meta = $area . ' · ' . digits(date('H:i', intdiv((int) $o['opened_at'], 1000))) . ($guests > 0 ? ' · ' . t('qr.w5_guests', ['n' => digits($guests)]) : '');
$notes = array_values(array_filter(array_map(static fn(array $x): string => trim((string) $x['note']), $orders)));
$base = '/qr/orders/' . $o['id'];
?>
<div class="scrim" data-dyn hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead(t('qr.w5_title', ['n' => digits($table['number'])])) ?>
    <div class="sheet__body qrsheet">
      <div class="row wrap gap-8"><?= Ui::badge($first ? t('qr.w5_first') : t('qr.w5_more'), 'attention', true) ?><span class="t-body-s c-muted"><?= e($meta) ?></span></div>
      <div class="qrsheet__lines">
        <?php foreach ($lines as $l): ?><?= OrderUi::line($l) ?><?php endforeach ?>
      </div>
      <?php foreach ($notes as $note): ?>
        <div class="qrsheet__note"><?= icon('note', 20) ?><span class="t-body-m c-secondary"><?= e(t('qr.w5_note', ['note' => $note])) ?></span></div>
      <?php endforeach ?>
      <p class="t-body-s c-muted"><?= e(t('qr.w5_help', ['n' => $table['number']])) ?></p>
    </div>
    <div class="qrsheet__acts">
      <?= Ui::btn(t('qr.w5_reject'), ['style' => 'danger', 'size' => 'l', 'icon' => 'close', 'attrs' => ['data-post' => $base . '/reject']]) ?>
      <?= Ui::btn(t('qr.w5_approve', ['amount' => money($total)]), ['size' => 'l', 'icon' => 'check', 'attrs' => ['data-post' => $base . '/approve']]) ?>
    </div>
  </div>
</div>
