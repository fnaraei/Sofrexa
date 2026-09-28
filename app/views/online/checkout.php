<?php
/**
 * Checkout — Figma O3 (48:222, phone) and O6 (50:392, desktop): Teslimat / Gel-al, address, time, phone, payment at
 * the door (cash with the amount for change, or card), totals and "Siparişi ver". Additions the brief asks for but
 * Figma does not draw: an order note, the cash amount on phones, "Yeni adres" and the Gel-al variant.
 * @var array $a @var array $p @var string $type @var array $addresses @var array $slots @var string $avail
 */
use Sofrexa\Modules\Online\OnlineOrders;
use Sofrexa\View\Ui;

$title = t('on.checkout');
$scripts = ['js/online.js'];
$bodyClass = 'online-checkout';
$types = OnlineOrders::types();
$fee = OnlineOrders::fee();
$eta = digits(OnlineOrders::eta());
$sub = t('on.checkout_sub', ['n' => digits($p['count']), 'amount' => money($p['subtotal'])]);
$split = static function (array $ad): array {
    $parts = array_map('trim', explode(',', (string) $ad['address'], 2));
    return count($parts) === 2 ? $parts : ['', $parts[0]];
};
$first = $addresses[0] ?? null;
$typeSeg = static function (string $f, bool $desk) use ($types, $type, $fee): string {
    if (count($types) < 2) {
        return '<input type="hidden" name="type" value="' . e($types[0] ?? 'delivery') . '">';
    }
    $out = '<div class="segs segs--full" data-type-seg>';
    foreach ($types as $ty) {
        $label = $ty === 'delivery' ? ($desk ? ($fee ? t('on.type_delivery_fee', ['amount' => money($fee)]) : t('on.type_delivery_d')) : t('on.type_delivery')) : ($desk ? t('on.type_pickup_d') : t('on.type_pickup'));
        $out .= '<label class="seg"><input type="radio" name="type" value="' . $ty . '" form="' . $f . '"' . ($ty === $type ? ' checked' : '') . '><span>' . e($label) . '</span></label>';
    }
    return $out . '</div>';
};
$payTiles = static function (bool $desk) use ($type): string {
    $card = ['delivery' => $desk ? t('on.card_sub') : t('on.card_sub_m'), 'pickup' => t('on.card_sub_pickup')];
    return '<div class="orow2 orow2--tight">'
        . Ui::opt(t('on.cash'), t('on.cash_sub'), 'cash', true, 'pay', 'cash', ['data-pay' => 'cash', 'data-sub-empty' => t('on.cash_sub'), 'data-sub-with' => t('on.cash_with', ['amount' => '{amount}'])])
        . Ui::opt(t('on.card'), $card[$type] ?? $card['delivery'], 'credit-card', false, 'pay', 'card', ['data-pay' => 'card', 'data-delivery' => $card['delivery'], 'data-pickup' => $card['pickup']])
        . '</div>';
};
$totals = static function () use ($p, $fee, $type): string {
    $total = $p['subtotal'] + ($type === 'delivery' ? $fee : 0);
    return '<div class="gtotals__row t-body-m"><span class="c-secondary">' . e(t('on.subtotal')) . '</span><span class="num">' . e(money($p['subtotal'])) . '</span></div>'
        . '<div class="gtotals__row t-body-m" data-when-type="delivery"' . ($type === 'delivery' ? '' : ' hidden') . '><span class="c-secondary">' . e(t('on.delivery')) . '</span><span class="num' . ($fee ? '' : ' c-success') . '">' . e($fee ? money($fee) : t('on.free')) . '</span></div>'
        . '<div class="gtotals__row ototal"><span>' . e(t('on.total')) . '</span><span class="num" data-total data-sub="' . $p['subtotal'] . '" data-fee="' . $fee . '">' . e(money($total)) . '</span></div>';
};
?>
<?= \Sofrexa\Core\View::partial('online/_sub', ['back' => '/online', 'title' => t('on.checkout'), 'sub' => $sub]) ?>

<!-- phones: O3 -->
<form class="gbody ocheck only-mobile" id="m-checkout" method="post" action="/online/siparis" data-ajax data-toast="off" data-online-form data-checkout>
  <?= csrf_field() ?>
  <?= $typeSeg('m-checkout', false) ?>
  <div class="col" data-when-type="delivery"<?= $type === 'delivery' ? '' : ' hidden' ?>>
    <?php if ($first): [$district, $street] = $split($first); ?>
      <div class="oaddr">
        <?= icon('map-pin', 22) ?>
        <div class="grow col gap-2"><span class="t-label-l" data-addr-title><?= e(trim(($first['label'] ?? '') . ($district !== '' ? ' · ' . $district : ''), ' ·')) ?></span><span class="t-body-s c-secondary" data-addr-line><?= e($street) ?></span></div>
        <?= Ui::btn(t('on.change'), ['style' => 'ghost', 'size' => 's', 'attrs' => ['data-sheet' => 'online-addr']]) ?>
        <input type="hidden" name="address_id" value="<?= e($first['id']) ?>" data-addr-input>
      </div>
    <?php else: ?>
      <?= Ui::btn(t('on.addr_none'), ['style' => 'secondary', 'size' => 'l', 'block' => true, 'icon' => 'map-pin', 'attrs' => ['data-sheet' => 'online-addr-new']]) ?>
      <input type="hidden" name="address_id" value="" data-addr-input>
    <?php endif ?>
  </div>
  <div class="chips chips--wrap" data-when-chips>
    <label class="chip"><input type="radio" name="when" value="asap" checked><span data-asap-label data-delivery="<?= e(t('on.asap', ['eta' => $eta])) ?>" data-pickup="<?= e(t('on.asap_pickup')) ?>"><?= e($type === 'delivery' ? t('on.asap', ['eta' => $eta]) : t('on.asap_pickup')) ?></span></label>
    <?php if ($slots): ?><label class="chip"><input type="radio" name="when" value="slot"><span><?= e(t('on.pick_time')) ?></span></label><?php endif ?>
  </div>
  <?php if ($slots): ?>
    <div data-slot-box hidden><?= Ui::select('slot', array_combine($slots, array_map('digits', $slots)), $slots[0], ['icon' => 'clock', 'id' => 'm-slot', 'label' => t('on.time')]) ?></div>
  <?php endif ?>
  <?= Ui::field('phone', ['id' => 'm-phone', 'label' => t('on.phone'), 'icon' => 'phone', 'type' => 'tel', 'autocomplete' => 'tel', 'value' => (string) $a['phone']]) ?>
  <div class="overline" data-pay-head data-delivery="<?= e(t('on.pay_door')) ?>" data-pickup="<?= e(t('on.pay_shop')) ?>"><?= e($type === 'delivery' ? t('on.pay_door') : t('on.pay_shop')) ?></div>
  <?= $payTiles(false) ?>
  <div data-cash-box><?= Ui::field('cash_given', ['id' => 'm-cash', 'label' => t('on.cash_given'), 'icon' => 'cash', 'attrs' => ['inputmode' => 'decimal', 'placeholder' => '₺']]) ?></div>
  <?= Ui::field('note', ['id' => 'm-note', 'label' => t('on.note'), 'icon' => 'note', 'placeholder' => t('on.note_ph'), 'attrs' => ['maxlength' => 200]]) ?>
  <div class="gtotals"><?= $totals() ?></div>
</form>
<div class="gbar only-mobile"><button type="submit" form="m-checkout" class="btn btn--accent btn--l btn--block" data-place-btn data-label="<?= e(t('on.place_amount', ['amount' => '{amount}'])) ?>"><?= icon('check', 20) ?><span><?= e(t('on.place_amount', ['amount' => money($p['subtotal'] + ($type === 'delivery' ? $fee : 0))])) ?></span></button></div>

<!-- desktop: O6 -->
<div class="only-desktop">
  <?= \Sofrexa\Core\View::partial('online/_site', ['acc' => $a]) ?>
  <form class="ocheckd" id="d-checkout" method="post" action="/online/siparis" data-ajax data-toast="off" data-online-form data-checkout>
    <?= csrf_field() ?>
    <div class="ocheckd__main">
      <h1 class="t-display-xl"><?= e(t('on.checkout')) ?></h1>
      <section class="ostep">
        <h2 class="t-heading-m" data-step-title data-delivery="<?= e(t('on.step_delivery')) ?>" data-pickup="<?= e(t('on.step_pickup')) ?>"><?= e($type === 'delivery' ? t('on.step_delivery') : t('on.step_pickup')) ?></h2>
        <?= $typeSeg('d-checkout', true) ?>
        <div class="oaddrs" data-when-type="delivery"<?= $type === 'delivery' ? '' : ' hidden' ?>>
          <?php foreach ($addresses as $k => $ad): [$district, $street] = $split($ad); ?>
            <label class="oaddrtile"><input type="radio" name="address_id" value="<?= e($ad['id']) ?>"<?= $k === 0 ? ' checked' : '' ?>><?= icon('map-pin', 20) ?><span class="grow col gap-2"><span class="t-label-m"><?= e((string) ($ad['label'] ?: t('on.addr_home'))) ?></span><span class="t-body-s c-secondary"><?= e(trim($street . ($district !== '' ? ' · ' . $district : ''), ' ·')) ?></span></span></label>
          <?php endforeach ?>
          <button type="button" class="oaddrtile oaddrtile--new" data-sheet="online-addr-new"><?= icon('plus', 20) ?><span class="t-label-m"><?= e(t('on.addr_new')) ?></span></button>
        </div>
        <div class="orow2">
          <?= Ui::field('phone', ['id' => 'd-phone', 'label' => t('on.phone'), 'icon' => 'phone', 'type' => 'tel', 'autocomplete' => 'tel', 'value' => (string) $a['phone']]) ?>
          <?php
          $whenOpts = ['asap' => $type === 'delivery' ? t('on.asap', ['eta' => $eta]) : t('on.asap_pickup')];
          foreach ($slots as $s) {
              $whenOpts[$s] = digits($s);
          } ?>
          <?= Ui::select('when', $whenOpts, 'asap', ['icon' => 'clock', 'id' => 'd-when', 'label' => t('on.time')]) ?>
        </div>
        <?= Ui::field('note', ['id' => 'd-note', 'label' => t('on.note'), 'icon' => 'note', 'placeholder' => t('on.note_ph'), 'attrs' => ['maxlength' => 200]]) ?>
      </section>
      <section class="ostep">
        <h2 class="t-heading-m" data-pay-head data-delivery="<?= e(t('on.step_pay')) ?>" data-pickup="<?= e(t('on.step_pay_pickup')) ?>"><?= e($type === 'delivery' ? t('on.step_pay') : t('on.step_pay_pickup')) ?></h2>
        <?= $payTiles(true) ?>
        <div data-cash-box><?= Ui::field('cash_given', ['id' => 'd-cash', 'label' => t('on.cash_given'), 'icon' => 'cash', 'attrs' => ['inputmode' => 'decimal', 'placeholder' => '₺']]) ?></div>
      </section>
    </div>
    <aside class="osum2">
      <h2 class="t-display-m"><?= e(t('on.your_order')) ?></h2>
      <?php foreach ($p['lines'] as $l): ?>
        <div class="osum2__line">
          <?php if ($l['photo']): ?><img class="osum2__img" src="<?= e($l['photo']) ?>" alt=""><?php else: ?><span class="osum2__img"><?= icon('utensils', 20) ?></span><?php endif ?>
          <span class="grow col gap-2"><span class="t-label-m"><?= e(digits($l['qty']) . '× ' . $l['name']) ?></span><?php if ($l['note_text'] !== ''): ?><span class="t-body-s c-muted"><?= e($l['note_text']) ?></span><?php endif ?></span>
          <span class="t-label-m c-secondary num"><?= e(money($l['total'])) ?></span>
        </div>
      <?php endforeach ?>
      <div class="col gap-12"><?= $totals() ?></div>
      <?= Ui::btn(t('on.place'), ['type' => 'submit', 'style' => 'accent', 'size' => 'l', 'block' => true, 'icon' => 'check']) ?>
      <p class="t-body-s c-muted"><?= e(t('on.place_hint')) ?></p>
    </aside>
  </form>
</div>

<!-- address list (phones, "Değiştir") and a new address (not in Figma) -->
<div class="scrim" id="online-addr" hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead(t('on.delivery')) ?>
    <div class="sheet__body">
      <?php foreach ($addresses as $k => $ad): [$district, $street] = $split($ad); ?>
        <button type="button" class="oaddrtile" data-pick-addr="<?= e($ad['id']) ?>" data-title="<?= e(trim(($ad['label'] ?? '') . ($district !== '' ? ' · ' . $district : ''), ' ·')) ?>" data-line="<?= e($street) ?>"><?= icon('map-pin', 20) ?><span class="grow col gap-2"><span class="t-label-m"><?= e(trim(($ad['label'] ?? '') . ($district !== '' ? ' · ' . $district : ''), ' ·')) ?></span><span class="t-body-s c-secondary"><?= e($street) ?></span></span></button>
      <?php endforeach ?>
      <?= Ui::btn(t('on.addr_new'), ['style' => 'secondary', 'size' => 'l', 'block' => true, 'icon' => 'plus', 'attrs' => ['data-sheet' => 'online-addr-new']]) ?>
    </div>
  </div>
</div>
<div class="scrim" id="online-addr-new" hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead(t('on.addr_new')) ?>
    <form class="sheet__body" method="post" action="/online/adres" data-ajax data-toast="off" data-online-form>
      <?= csrf_field() ?>
      <div class="chips chips--wrap" data-fill="label"><?= Ui::chip(t('on.addr_home'), true, null, ['data-value' => t('on.addr_home')]) ?><?= Ui::chip(t('on.addr_work'), false, null, ['data-value' => t('on.addr_work')]) ?></div>
      <?= Ui::field('label', ['id' => 'a-label', 'label' => t('on.addr_label'), 'icon' => 'tag', 'value' => t('on.addr_home'), 'attrs' => ['maxlength' => 40]]) ?>
      <?= Ui::field('district', ['id' => 'a-district', 'label' => t('on.addr_district'), 'icon' => 'map-pin', 'attrs' => ['maxlength' => 80]]) ?>
      <?= Ui::field('street', ['id' => 'a-street', 'label' => t('on.addr_street'), 'icon' => 'home', 'attrs' => ['maxlength' => 200]]) ?>
      <?= Ui::field('note', ['id' => 'a-note', 'label' => t('on.addr_note'), 'icon' => 'note', 'attrs' => ['maxlength' => 200]]) ?>
      <?php if ((string) \Sofrexa\Core\Settings::get('online.area', '') !== ''): ?><p class="t-body-s c-muted"><?= e(t('on.addr_area', ['area' => (string) \Sofrexa\Core\Settings::get('online.area')])) ?></p><?php endif ?>
      <?= Ui::btn(t('on.addr_save'), ['type' => 'submit', 'style' => 'accent', 'size' => 'l', 'block' => true, 'icon' => 'check']) ?>
    </form>
  </div>
</div>
