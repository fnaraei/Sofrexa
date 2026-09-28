<?php
/**
 * Send to the accountant — Figma R4 (44:258 desktop: period, contents, format, preview, e-mail / download) and
 * R5 (44:508 phone: period, checklist, formats, e-mail / download and share). @var array $p @var array $preview
 */
use Sofrexa\Core\{I18n, Money, Settings};
use Sofrexa\Modules\Reports\{Accountant, Reports};
use Sofrexa\View\Ui;

$noHead = true;
$sub = t('rep.exp_sub');
$periodLabel = in_array($p['key'], ['month', 'last_month'], true) ? \Sofrexa\Modules\Customers\Customers::monthLabel(substr($p['first'], 0, 7)) : Reports::rangeLabel($p);
$appSub = $periodLabel;
$bodyClass = 'page-export';
$bottom = Ui::ibtn('mail', t('rep.exp_mail'), ['style' => 'secondary', 'class' => 'ibtn--l', 'attrs' => ['data-sheet' => 'mail-sheet']])
    . Ui::btn(t('rep.exp_share'), ['size' => 'l', 'icon' => 'share', 'class' => 'grow', 'type' => 'submit', 'attrs' => ['form' => 'export-form']]);
$email = (string) Settings::get('report.accountant_email', '');
$seg = static function (array $keys) use ($p): string {
    $h = '<div class="segs segs--full">';
    foreach ($keys as $k) {
        $h .= '<a class="seg' . ($k === $p['key'] ? ' is-active' : '') . '" href="/reports/export?p=' . $k . '">' . e(t('rep.seg.' . $k)) . '</a>';
    }
    return $h . '</div>';
};
$fmtTile = static fn(string $v, string $icon, bool $on): string => '<label class="opt"><input type="checkbox" name="formats[' . $v . ']" value="1"' . ($on ? ' checked' : '') . '>' . icon($icon, 24)
    . '<span class="opt__label">' . e(t('rep.fmt.' . $v)) . '</span><span class="opt__sub ellipsis only-desktop">' . e(t('rep.fmt.' . $v . '_s')) . '</span></label>';
$fxText = implode(' · ', array_map(static fn(string $c, array $v): string => Money::symbol($c) . digits(I18n::num($v['fx'])), array_keys($preview['fx']), $preview['fx']));
?>
<form class="export" id="export-form" method="post" action="/reports/export" data-export>
  <?= csrf_field() ?>
  <input type="hidden" name="p" value="<?= e($p['key']) ?>">
  <div class="page-head only-desktop"><div class="page-head__titles"><h1 class="t-heading-xl"><?= e($title) ?></h1><p class="t-body-m c-muted"><?= e($sub) ?></p></div></div>
  <div class="export__body">
    <div class="export__left">
      <section class="card xcard">
        <h2 class="t-heading-m only-desktop"><?= e(t('rep.exp_period')) ?></h2>
        <div class="only-desktop"><?= $seg(['month', 'last_month', 'quarter', 'custom']) ?></div>
        <div class="only-mobile"><?= $seg(['month', 'last_month', 'custom']) ?></div>
        <div class="grid2 grid2--keep<?= $p['key'] === 'custom' ? '' : ' only-desktop' ?>">
          <label class="field"><span class="field__label"><?= e(t('rep.from')) ?></span><span class="field__box"><?= icon('calendar', 20) ?><input type="date" name="from" value="<?= e($p['first']) ?>" data-range<?= $p['key'] === 'custom' ? '' : ' readonly' ?>></span></label>
          <label class="field"><span class="field__label"><?= e(t('rep.to')) ?></span><span class="field__box"><?= icon('calendar', 20) ?><input type="date" name="to" value="<?= e($p['last']) ?>" data-range<?= $p['key'] === 'custom' ? '' : ' readonly' ?>></span></label>
        </div>
      </section>
      <section class="card xcard xcard--list">
        <h2 class="t-heading-m only-desktop"><?= e(t('rep.exp_content')) ?></h2>
        <?php foreach (Accountant::PARTS as $part): ?>
          <label class="checkrow2"><?= Ui::checkbox('parts[' . $part . ']', in_array($part, Accountant::DEFAULT, true)) ?>
            <span class="col" style="gap:0;min-width:0"><span class="t-label-m"><?= e(t('rep.part.' . $part)) ?></span><span class="t-body-s c-muted ellipsis only-desktop"><?= e(t('rep.part.' . $part . '_s')) ?></span></span></label>
        <?php endforeach ?>
      </section>
    </div>
    <div class="export__right">
      <section class="card xcard">
        <h2 class="t-heading-m only-desktop"><?= e(t('rep.exp_format')) ?></h2>
        <div class="opts opts--3"><?= $fmtTile('xlsx', 'file-sheet', true) ?><?= $fmtTile('pdf', 'file-text', true) ?><?= $fmtTile('csv', 'download', false) ?></div>
      </section>
      <section class="card xcard only-desktop">
        <h2 class="t-heading-m"><?= e(t('rep.exp_preview', ['period' => $periodLabel])) ?></h2>
        <div class="kv3 t-label-m"><span><?= e(t('rep.exp_total')) ?></span><span class="num"><?= e(money($preview['sales'])) ?></span></div>
        <?php foreach ($preview['vat'] as $rate => [$gross, $v]): if ((float) $rate <= 0) continue; ?>
          <div class="kv3"><span><?= e(t('rep.vat_line', ['r' => digits(I18n::numAuto((float) $rate))])) ?></span><span class="num"><?= e(money($v)) ?></span></div>
        <?php endforeach ?>
        <div class="kv3"><span><?= e(t('rep.exp_split')) ?></span><span class="num"><?= e(money($preview['cash']) . ' / ' . money($preview['card']) . ' / ' . money($preview['account'])) ?></span></div>
        <div class="kv3"><span><?= e(t('rep.exp_fx')) ?></span><span class="num"><?= e($fxText !== '' ? $fxText : '—') ?></span></div>
        <div class="kv3"><span><?= e(t('rep.part.purchases')) ?></span><span class="num"><?= e(money($preview['purchases']) . ' · ' . t('rep.pcs', ['n' => digits($preview['purchases_n'])])) ?></span></div>
        <div class="kv3"><span><?= e(t('rep.exp_stock', ['date' => digits(date('d.m', (int) strtotime($p['last'])))])) ?></span><span class="num"><?= e(money($preview['stock'])) ?></span></div>
      </section>
      <div class="row gap-10 only-desktop">
        <?= Ui::btn(t('rep.exp_mail'), ['style' => 'secondary', 'size' => 'l', 'icon' => 'mail', 'class' => 'grow', 'attrs' => ['data-sheet' => 'mail-sheet']]) ?>
        <?= Ui::btn(t('rep.exp_download'), ['size' => 'l', 'icon' => 'download', 'class' => 'grow', 'type' => 'submit']) ?>
      </div>
    </div>
  </div>
</form>

<div class="scrim" id="mail-sheet" hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead(t('rep.exp_mail')) ?>
    <div class="sheet__body">
      <?= Ui::field('email', ['label' => t('rep.exp_to'), 'icon' => 'mail', 'type' => 'email', 'value' => $email, 'attrs' => ['data-mail-to' => true, 'form' => 'export-form']]) ?>
      <p class="t-body-s c-muted"><?= e(t('rep.exp_mail_note')) ?></p>
      <div class="sheet__actions">
        <?= Ui::btn(t('ui.cancel'), ['style' => 'secondary', 'size' => 'l', 'attrs' => ['data-close' => true]]) ?>
        <?= Ui::btn(t('rep.exp_send'), ['size' => 'l', 'icon' => 'send', 'attrs' => ['data-mail-send' => true]]) ?>
      </div>
    </div>
  </div>
</div>
