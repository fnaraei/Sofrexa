<?php
/**
 * Currencies and rates. Figma has no settings frame for this section; it is built from the SE section card,
 * Field and Toggle components (the till's own rate screen is C7).
 * @var array $data
 */
use Sofrexa\Core\Money;
use Sofrexa\View\Ui;
?>
<section class="section">
  <div class="section__head"><h2 class="section__title"><?= e(t('set.cur.title')) ?></h2><p class="section__sub"><?= e(t('set.cur.sub')) ?></p></div>
  <?php foreach ($data['rates'] as $c => $r): ?>
    <div class="curow">
      <span class="curow__sym"><?= e(Money::symbol($c)) ?></span>
      <div class="col grow" style="gap:0"><span class="t-label-m"><?= e(t('cur.' . $c)) ?> · <?= $c ?></span><span class="t-body-s c-muted"><?= $r['at'] ? e(t('set.cur.updated', ['when' => when_label($r['at']), 'who' => $r['by'] ?? '—'])) : e(t('set.cur.never')) ?></span></div>
      <label class="editable"><input type="text" inputmode="decimal" name="s[rate.<?= $c ?>]" value="<?= $r['rate'] ? e(num($r['rate'], 2)) : '' ?>" placeholder="0,00" aria-label="<?= e(t('set.cur.rate') . ' ' . $c) ?>"></label>
      <?= Ui::toggle('s[currency.accepted][]', $r['accepted'], ['value' => $c, 'aria-label' => t('set.cur.accept') . ' ' . $c]) ?>
    </div>
  <?php endforeach ?>
  <div class="note"><?= icon('info', 20) ?><span><?= e(t('set.cur.note')) ?></span></div>
</section>
