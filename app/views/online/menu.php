<?php
/**
 * Online menu and cart — desktop Figma O5 (50:223): CategoryNav, dish grid, CartPanel with Teslimat / Gel-al and the
 * minimum-order warning. Phones reuse the QR menu Q1 (46:2) with the channel as subtitle, an account button, the
 * delivery info and a cart view like Q2. The cart lives on the phone (localStorage) until "Devam et".
 * @var array $menu @var ?array $acc @var string $avail
 */
use Sofrexa\Core\Settings;
use Sofrexa\Modules\Online\OnlineOrders;
use Sofrexa\View\{Brand, Ui};

$title = t('on.title');
$scripts = ['js/online.js'];
$bodyClass = 'online-menu';
$open = $avail === 'open';
$items = [];
foreach ($menu as $c) {
    foreach ($c['items'] as $i) {
        $items[$i['id']] = ['n' => $i['name'], 'p' => $i['price'], 'img' => $i['photo'], 'g' => $i['groups'], 'ok' => $i['orderable']];
    }
}
$fee = OnlineOrders::fee();
$types = OnlineOrders::types();
$info = match ($avail) {
    'open' => in_array('delivery', $types, true)
        ? t('on.info', ['fee' => $fee ? t('on.benefit_fee', ['amount' => money($fee)]) : t('on.free_delivery'), 'min' => money(OnlineOrders::minOrder()), 'eta' => digits(OnlineOrders::eta())])
        : t('on.info_pickup'),
    'closed' => t('on.closed', ['hours' => digits((string) Settings::get('online.hours'))]),
    'offline' => t('on.offline'),
    default => t('on.off'),
};
$name = (string) (Settings::get('profile.short_name', '') ?: Settings::get('profile.name'));
$logo = Brand::logoUrl();
$seg = static function (string $prefix) use ($types, $fee): string {
    $out = '<div class="segs segs--block" role="radiogroup" data-cart-type>';
    foreach ($types as $k => $ty) {
        $label = $ty === 'delivery' ? ($prefix === 'd' ? ($fee ? t('on.type_delivery_fee', ['amount' => money($fee)]) : t('on.type_delivery')) : t('on.type_delivery')) : t('on.type_pickup');
        $out .= '<label class="seg"><input type="radio" name="' . $prefix . '_type" value="' . $ty . '"' . ($k === 0 ? ' checked' : '') . '><span>' . e($label) . '</span></label>';
    }
    return $out . '</div>';
};
$dish = static function (array $i, string $variant) use ($open): string {
    $photo = $i['photo'] ? '<img class="' . ($variant === 'd' ? 'odish__img' : 'dish__img') . '" src="' . e($i['photo']) . '" alt="" loading="lazy">' : '<span class="' . ($variant === 'd' ? 'odish__img' : 'dish__img') . '">' . icon('utensils', 28) . '</span>';
    $ctl = !$i['orderable'] ? Ui::badge(t('qr.soldout'), 'neutral') : ($open ? '<span class="dish__ctl" data-ctl="' . $variant . '"></span>' : '');
    $search = e(mb_strtolower(strtr($i['name'] . ' ' . $i['desc'], ['İ' => 'i', 'I' => 'i', 'ı' => 'i']), 'UTF-8'));
    if ($variant === 'd') {
        return '<article class="odish' . ($i['orderable'] ? '' : ' is-soldout') . '" data-dish="' . e($i['id']) . '" data-search="' . $search . '">' . $photo
            . '<div class="odish__body"><h3 class="t-heading-s">' . e($i['name']) . '</h3>' . ($i['desc'] !== '' ? '<p class="odish__desc t-body-s">' . e($i['desc']) . '</p>' : '')
            . '<div class="dish__row"><span class="t-label-l c-accent num">' . e(money($i['price'])) . '</span>' . $ctl . '</div></div></article>';
    }
    return '<article class="dish' . ($i['orderable'] ? '' : ' is-soldout') . '" data-dish="' . e($i['id']) . '" data-search="' . $search . '">' . $photo
        . '<div class="dish__col"><h3 class="t-heading-s">' . e($i['name']) . '</h3>' . ($i['desc'] !== '' ? '<p class="dish__desc t-body-s">' . e($i['desc']) . '</p>' : '')
        . '<div class="dish__row"><span class="t-label-l c-accent num">' . e(money($i['price'])) . '</span>' . $ctl . '</div></div></article>';
};
?>
<div class="oapp" data-online data-min="<?= OnlineOrders::minOrder() ?>" data-fee="<?= $fee ?>"<?= $open ? '' : ' data-readonly' ?>>

  <!-- phones: Q1 with the online subtitle -->
  <section class="gview only-mobile" data-view="menu">
    <header class="ghead">
      <?php if ($logo): ?><img class="ghead__logo" src="<?= e($logo) ?>" alt=""><?php endif ?>
      <div class="ghead__txt"><span class="ghead__name"><?= e(mb_strtoupper($name, 'UTF-8')) ?></span><span class="ghead__sub"><?= e(upper(t('on.channel_line'))) ?></span></div>
      <?= $acc ? Ui::ibtn('user', first_name((string) $acc['name']), ['attrs' => ['data-load-sheet' => '/online/hesap']]) : Ui::ibtn('user', t('on.sign_in'), ['href' => '/online/giris']) ?>
      <?= Ui::ibtn('globe', t('qr.lang'), ['attrs' => ['data-sheet' => 'guest-lang']]) ?>
    </header>
    <main class="gbody<?= $open ? ' gbody--menu' : '' ?>">
      <div class="gnote gnote--<?= $open ? 'accent' : 'info' ?>"><?= icon($open ? 'truck' : 'info', 22) ?><span><?= e($info) ?></span></div>
      <?= Ui::field('q', ['id' => 'm-q', 'type' => 'search', 'icon' => 'search', 'placeholder' => t('qr.search'), 'attrs' => ['data-online-search' => true, 'enterkeyhint' => 'search']]) ?>
      <nav class="grail" aria-label="<?= e(t('on.categories')) ?>">
        <?php foreach ($menu as $k => $c): ?><?= Ui::chip($c['name'], $k === 0, null, ['href' => '#m-c-' . $c['id'], 'data-rail' => $c['id']]) ?><?php endforeach ?>
      </nav>
      <?php foreach ($menu as $c): ?>
        <section class="gcat" id="m-c-<?= e($c['id']) ?>" data-cat="<?= e($c['id']) ?>">
          <h2 class="t-display-l"><?= e($c['name']) ?></h2>
          <?php foreach ($c['items'] as $i): ?><?= $dish($i, 'm') ?><?php endforeach ?>
        </section>
      <?php endforeach ?>
      <div class="empty" data-none hidden><?= icon('search', 24) ?><span><?= e(t('qr.none')) ?></span></div>
    </main>
    <?php if ($open): ?>
      <button type="button" class="cartbar" data-open-cart hidden>
        <span class="cartbar__n t-label-m num" data-cart-n>0</span>
        <span class="cartbar__label t-label-l"><?= e(t('on.review')) ?></span>
        <span class="t-heading-m num" data-cart-total></span>
      </button>
    <?php endif ?>
  </section>

  <?php if ($open): ?>
  <section class="gview only-mobile" data-view="cart" hidden>
    <header class="ghead ghead--back">
      <?= Ui::ibtn('arrow-left', t('on.back'), ['attrs' => ['data-close-cart' => true]]) ?>
      <div class="ghead__txt"><span class="ghead__name"><?= e(t('on.cart')) ?></span><span class="ghead__sub"><?= e(t('on.title')) ?></span></div>
    </header>
    <main class="gbody">
      <?= count($types) > 1 ? $seg('m') : '' ?>
      <div class="col" data-cart-lines="m"></div>
      <div class="empty" data-cart-empty hidden><?= icon('bag', 24) ?><span><?= e(t('on.cart_empty')) ?></span></div>
      <div class="gtotals">
        <div class="gtotals__row t-body-m"><span class="c-secondary"><?= e(t('on.subtotal')) ?></span><span class="num" data-cart-sub></span></div>
        <div class="gtotals__row t-body-m" data-fee-row><span class="c-secondary"><?= e(t('on.delivery')) ?></span><span class="num<?= $fee ? '' : ' c-success' ?>"><?= e($fee ? money($fee) : t('on.free')) ?></span></div>
        <div class="gtotals__row t-heading-m"><span><?= e(t('on.total')) ?></span><span class="num" data-cart-sum></span></div>
      </div>
      <div data-min-banner hidden><?= Ui::banner(t('on.min_title', ['amount' => money(OnlineOrders::minOrder())]), '', 'warning', 'info') ?></div>
    </main>
    <div class="gbar"><button type="button" class="btn btn--accent btn--l btn--block" data-continue data-label="<?= e(t('on.continue', ['amount' => '{amount}'])) ?>"><?= icon('arrow-right', 20) ?><span></span></button></div>
  </section>
  <?php endif ?>

  <!-- desktop: O5 -->
  <div class="only-desktop">
    <?= \Sofrexa\Core\View::partial('online/_site', ['acc' => $acc]) ?>
    <div class="omenu">
      <nav class="ocatnav" aria-label="<?= e(t('on.categories')) ?>">
        <div class="overline"><?= e(t('on.categories')) ?></div>
        <?php foreach ($menu as $k => $c): ?><a class="t-label-m<?= $k === 0 ? ' is-active' : '' ?>" href="#d-c-<?= e($c['id']) ?>" data-catnav="<?= e($c['id']) ?>"><?= e($c['name']) ?></a><?php endforeach ?>
      </nav>
      <main class="omain">
        <div class="omain__head">
          <h1 class="t-display-l grow" data-active-title><?= e($menu[0]['name'] ?? t('on.nav_menu')) ?></h1>
          <?= Ui::field('dq', ['id' => 'd-q', 'type' => 'search', 'icon' => 'search', 'placeholder' => t('on.search'), 'class' => 'omain__search', 'attrs' => ['data-online-search' => true]]) ?>
        </div>
        <?php if (!$open): ?><div class="gnote gnote--info"><?= icon('info', 22) ?><span><?= e($info) ?></span></div><?php endif ?>
        <?php foreach ($menu as $k => $c): ?>
          <section class="ocat" id="d-c-<?= e($c['id']) ?>" data-cat="<?= e($c['id']) ?>" data-cat-name="<?= e($c['name']) ?>">
            <?php if ($k > 0): ?><h2 class="t-display-m"><?= e($c['name']) ?></h2><?php endif ?>
            <div class="ogrid"><?php foreach ($c['items'] as $i): ?><?= $dish($i, 'd') ?><?php endforeach ?></div>
          </section>
        <?php endforeach ?>
        <div class="empty" data-none hidden><?= icon('search', 24) ?><span><?= e(t('qr.none')) ?></span></div>
      </main>
      <?php if ($open): ?>
      <aside class="ocart">
        <h2 class="t-display-m"><?= e(t('on.cart')) ?></h2>
        <?= count($types) > 1 ? $seg('d') : '' ?>
        <div class="col gap-10" data-cart-lines="d"></div>
        <div class="empty" data-cart-empty hidden><?= e(t('on.cart_empty')) ?></div>
        <div class="ocart__rows">
          <div class="gtotals__row t-body-m"><span class="c-secondary"><?= e(t('on.subtotal')) ?></span><span class="num" data-cart-sub></span></div>
          <div class="gtotals__row t-body-m" data-fee-row><span class="c-secondary"><?= e(t('on.delivery')) ?></span><span class="num<?= $fee ? '' : ' c-success' ?>"><?= e($fee ? money($fee) : t('on.free')) ?></span></div>
        </div>
        <div data-min-banner hidden><?= Ui::banner(t('on.min_title', ['amount' => money(OnlineOrders::minOrder())]), '', 'warning', 'info') ?></div>
        <button type="button" class="btn btn--accent btn--l btn--block" data-continue data-label="<?= e(t('on.continue', ['amount' => '{amount}'])) ?>"><?= icon('arrow-right', 20) ?><span></span></button>
      </aside>
      <?php endif ?>
    </div>
  </div>
</div>

<?php if ($open): ?>
<div class="scrim" id="guest-opts" hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <div class="sheet__handle"></div>
    <div class="sheet__head"><h2 class="sheet__title" data-opts-title></h2><button type="button" class="sheet__close" data-close aria-label="<?= e(t('ui.close')) ?>"><?= icon('close', 20) ?></button></div>
    <form class="optsheet" data-opts>
      <div class="optsheet__body" data-opts-body></div>
      <div class="optsheet__body"><?= Ui::field('line_note', ['id' => 'o-line-note', 'label' => t('qr.line_note_label'), 'icon' => 'note', 'placeholder' => t('qr.line_note_ph'), 'attrs' => ['maxlength' => 120]]) ?></div>
      <div class="optsheet__actions">
        <div class="qty qty--l" data-stepper><button type="button" data-step="-1" aria-label="−"><?= icon('minus', 20) ?></button><span class="qty__n num" data-stepper-n>1</span><button type="button" data-step="1" aria-label="+"><?= icon('plus', 20) ?></button><input type="hidden" name="qty" value="1" data-stepper-v data-min="1" data-max="<?= OnlineOrders::MAX_QTY ?>"></div>
        <button type="submit" class="btn btn--accent btn--l grow" data-label="<?= e(t('qr.add_amount', ['amount' => '{amount}'])) ?>"><?= icon('plus', 20) ?><span></span></button>
      </div>
    </form>
  </div>
</div>
<script type="application/json" id="online-data"><?= json_encode(['items' => (object) $items, 'max' => OnlineOrders::MAX_QTY, 'types' => $types], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif ?>
