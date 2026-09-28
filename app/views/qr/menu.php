<?php
/**
 * QR menu — Figma Q1 (46:2) and, in the same page, the cart Q2 (46:106).
 * The cart lives on the phone (localStorage per table); guest.js draws the dish controls, the CartBar and the cart lines.
 * Read-only (no table, ordering switched off or paused): no ＋, no CartBar.
 * @var ?array $table @var string $code @var array $menu @var bool $readonly @var string $avail @var bool $approval @var array $mine
 */
use Sofrexa\Modules\QrOrder\QrOrders;
use Sofrexa\View\Ui;

$title = $table ? QrOrders::label($table) : t('qr.menu');
$scripts = ['js/guest.js'];
$bodyClass = 'guest-menu';
$items = [];
foreach ($menu as $c) {
    foreach ($c['items'] as $i) {
        $items[$i['id']] = ['n' => $i['name'], 'p' => $i['price'], 'img' => $i['photo'], 'g' => $i['groups'], 'ok' => $i['orderable']];
    }
}
$note = match (true) {
    !$table => [t('qr.no_table'), 'info', 'info'],
    $avail === 'offline' => [t('qr.readonly_offline'), 'info', 'wifi-off'],
    $avail === 'off' => [t('qr.readonly_off'), 'info', 'info'],
    default => [t('qr.info_order'), 'accent', 'qr'],
};
$status = '/q/' . rawurlencode($code) . '/status';
?>
<div class="gapp" data-guest data-code="<?= e($code) ?>"<?= $readonly ? ' data-readonly' : '' ?>>
  <section class="gview" data-view="menu">
    <?= \Sofrexa\Core\View::partial('qr/_head', ['table' => $table]) ?>
    <main class="gbody<?= $readonly ? '' : ' gbody--menu' ?>">
      <div class="gnote gnote--<?= $note[1] ?>"><?= icon($note[2], 22) ?><span><?= e($note[0]) ?></span></div>
      <?php if ($mine): ?>
        <a class="gnote gnote--link" href="<?= e($status) ?>"><?= icon('chef-hat', 22) ?><span class="grow"><?= e(t('qr.track')) ?></span><?= icon('chevron-right', 20) ?></a>
      <?php endif ?>
      <?= Ui::field('q', ['type' => 'search', 'icon' => 'search', 'placeholder' => t('qr.search'), 'attrs' => ['data-guest-search' => true, 'enterkeyhint' => 'search']]) ?>
      <nav class="grail" aria-label="<?= e(t('qr.categories')) ?>">
        <?php foreach ($menu as $k => $c): ?><?= Ui::chip($c['name'], $k === 0, null, ['href' => '#c-' . $c['id'], 'data-rail' => $c['id']]) ?><?php endforeach ?>
      </nav>
      <?php foreach ($menu as $c): ?>
        <section class="gcat" id="c-<?= e($c['id']) ?>" data-cat="<?= e($c['id']) ?>">
          <h2 class="t-display-l"><?= e($c['name']) ?></h2>
          <?php foreach ($c['items'] as $i): ?>
            <article class="dish<?= $i['orderable'] ? '' : ' is-soldout' ?>" data-dish="<?= e($i['id']) ?>" data-search="<?= e(mb_strtolower(strtr($i['name'] . ' ' . $i['desc'], ['İ' => 'i', 'I' => 'i', 'ı' => 'i']), 'UTF-8')) ?>">
              <?php if ($i['photo']): ?><img class="dish__img" src="<?= e($i['photo']) ?>" alt="" loading="lazy"><?php else: ?><span class="dish__img"><?= icon('utensils', 28) ?></span><?php endif ?>
              <div class="dish__col">
                <div class="dish__head"><h3 class="t-heading-s"><?= e($i['name']) ?></h3><?php if (!empty($i['promo'])): ?><?= \Sofrexa\View\Ui::badge($i['promo'], 'accent') ?><?php endif ?></div>
                <?php if ($i['desc'] !== ''): ?><p class="dish__desc t-body-s"><?= e($i['desc']) ?></p><?php endif ?>
                <div class="dish__row">
                  <span class="dish__price"><span class="t-label-l c-accent num"><?= e(money($i['price'])) ?></span><?php if (!empty($i['was'])): ?> <s class="t-body-s c-muted num"><?= e(money($i['was'])) ?></s><?php endif ?></span>
                  <?php if (!$i['orderable']): ?><?= Ui::badge(t('qr.soldout'), 'neutral') ?>
                  <?php elseif (!$readonly): ?><span class="dish__ctl" data-ctl><button type="button" class="dish__add" data-add="<?= e($i['id']) ?>" aria-label="<?= e(t('qr.add')) ?>"><?= icon('plus', 20) ?></button></span><?php endif ?>
                </div>
              </div>
            </article>
          <?php endforeach ?>
        </section>
      <?php endforeach ?>
      <div class="empty" data-guest-none hidden><?= icon('search', 24) ?><span><?= e(t('qr.none')) ?></span></div>
    </main>
    <?php if (!$readonly): ?>
      <button type="button" class="cartbar" data-open-cart hidden>
        <span class="cartbar__n t-label-m num" data-cart-n>0</span>
        <span class="cartbar__label t-label-l"><?= e(t('qr.review')) ?></span>
        <span class="t-heading-m num" data-cart-total></span>
      </button>
    <?php endif ?>
  </section>

  <?php if (!$readonly): ?>
  <section class="gview" data-view="cart" hidden>
    <?= \Sofrexa\Core\View::partial('qr/_head', ['table' => $table, 'back' => '#', 'title' => t('qr.your_order')]) ?>
    <main class="gbody">
      <div class="col" data-cart-lines></div>
      <div class="empty" data-cart-empty hidden><?= icon('bag', 24) ?><span><?= e(t('qr.cart_empty')) ?></span></div>
      <?= Ui::field('note', ['label' => t('qr.note_label'), 'icon' => 'note', 'placeholder' => t('qr.note_ph'), 'attrs' => ['data-cart-note' => true, 'maxlength' => 200]]) ?>
      <div class="gtotals">
        <div class="gtotals__row t-body-m"><span class="c-secondary"><?= e(t('qr.subtotal')) ?></span><span class="num" data-cart-sub></span></div>
        <div class="gtotals__row t-heading-m"><span><?= e(t('qr.total')) ?></span><span class="num" data-cart-sum></span></div>
      </div>
      <div class="gnote gnote--info"><?= icon('info', 20) ?><span><?= e(t($approval ? 'qr.info_approval' : 'qr.info_direct')) ?></span></div>
    </main>
    <div class="gbar">
      <button type="button" class="btn btn--accent btn--l btn--block" data-send data-label="<?= e(t('qr.send', ['amount' => '{amount}'])) ?>"><?= icon('send', 20) ?><span></span></button>
    </div>
  </section>
  <?php endif ?>
</div>

<?php if (!$readonly): ?>
<div class="scrim" id="guest-opts" hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <div class="sheet__handle"></div>
    <div class="sheet__head"><h2 class="sheet__title" data-opts-title></h2><button type="button" class="sheet__close" data-close aria-label="<?= e(t('ui.close')) ?>"><?= icon('close', 20) ?></button></div>
    <form class="optsheet" data-opts>
      <div class="optsheet__body" data-opts-body></div>
      <div class="optsheet__body"><?= Ui::field('line_note', ['label' => t('qr.line_note_label'), 'icon' => 'note', 'placeholder' => t('qr.line_note_ph'), 'attrs' => ['maxlength' => 120]]) ?></div>
      <div class="optsheet__actions">
        <div class="qty qty--l" data-stepper><button type="button" data-step="-1" aria-label="−"><?= icon('minus', 20) ?></button><span class="qty__n num" data-stepper-n>1</span><button type="button" data-step="1" aria-label="+"><?= icon('plus', 20) ?></button><input type="hidden" name="qty" value="1" data-stepper-v data-min="1" data-max="<?= QrOrders::MAX_QTY ?>"></div>
        <button type="submit" class="btn btn--accent btn--l grow" data-label="<?= e(t('qr.add_amount', ['amount' => '{amount}'])) ?>"><?= icon('plus', 20) ?><span></span></button>
      </div>
    </form>
  </div>
</div>
<div class="scrim" id="guest-note" hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead(t('qr.line_note_label')) ?>
    <form class="sheet__body" data-note-form>
      <?= Ui::field('line_note', ['icon' => 'note', 'placeholder' => t('qr.line_note_ph'), 'id' => 'f-guest-note', 'attrs' => ['maxlength' => 120]]) ?>
      <div class="sheet__actions"><button type="submit" class="btn btn--accent btn--l btn--block"><?= icon('check', 20) ?><span><?= e(t('ui.save')) ?></span></button></div>
    </form>
  </div>
</div>
<script type="application/json" id="guest-data"><?= json_encode(['items' => (object) $items, 'status' => $status, 'max' => QrOrders::MAX_QTY], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif ?>
