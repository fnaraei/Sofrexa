<?php
/**
 * Phone order — Figma C4 (27:491): customer by phone with addresses and courier | menu | the order with payment at the door.
 * Desktop: three columns next to the SideNav; phone: the same blocks stacked.
 * @var string $type delivery|pickup @var array $items @var array $cats @var array $popular @var array $groups
 * @var array $couriers @var int $nextNo @var int $fee @var string $eta
 */
use Sofrexa\View\{OrderUi, Ui};

$noHead = true;
$title = t($type === 'pickup' ? 'deliv.new_pickup' : 'deliv.new_delivery');
$back = '/delivery';
$bodyClass = 'page-phone';
[$etaMin, $etaMax] = array_pad(preg_split('/\s*[–-]\s*/u', $eta) ?: [], 2, '');
$cfg = [
    'fee' => $fee,
    'str' => [
        'delivery' => t('order.delivery', ['no' => digits(sprintf('%04d', $nextNo))]),
        'pickup' => t('order.takeaway', ['no' => digits(sprintf('%04d', $nextNo))]),
        'title_delivery' => t('deliv.new_delivery'), 'title_pickup' => t('deliv.new_pickup'),
        'new' => t('order.st_new'), 'empty' => t('deliv.cart_empty'), 'undo' => t('js.undo'),
    ],
];
$courierTiles = '';
foreach ($couriers as $i => $c) {
    $courierTiles .= Ui::opt(first_name($c['name']), $c['out'] ? t('deliv.c_way') : t('deliv.c_free'), 'bike', !$c['out'] && $i === 0, 'courier_id', $c['id']);
}
$courierTiles .= Ui::opt(t('deliv.c_later'), t('deliv.c_later_s'), 'bike', !$couriers, 'courier_id', '');
?>
<form class="phord" data-phone-order data-cfg='<?= e(json_encode($cfg, JSON_UNESCAPED_UNICODE)) ?>' autocomplete="off">
  <section class="phord__cust">
    <div class="row gap-8"><?= Ui::ibtn('arrow-left', t('ui.back'), ['style' => 'secondary', 'size' => 's', 'href' => '/delivery', 'class' => 'ibtn--flip only-desktop']) ?><h1 class="t-heading-l grow" data-title><?= e($title) ?></h1></div>
    <?= Ui::segs(['delivery' => t('deliv.t_delivery'), 'pickup' => t('deliv.t_pickup')], $type, 'type') ?>
    <?= Ui::field('phone', ['label' => t('deliv.phone'), 'icon' => 'phone', 'type' => 'tel', 'attrs' => ['inputmode' => 'tel', 'autofocus' => true, 'data-phone' => true]]) ?>
    <div class="matchcard" data-match hidden><span class="avatar" data-match-av></span><div class="col grow" style="gap:0"><span class="t-label-l" data-match-name></span><span class="t-body-s c-secondary" data-match-line></span></div></div>
    <input type="hidden" name="customer_id" value="">
    <div data-newcust hidden>
      <p class="t-body-s c-accent"><?= e(t('deliv.new_customer')) ?></p>
      <?= Ui::field('name', ['label' => t('deliv.name'), 'icon' => 'user']) ?>
    </div>
    <div class="col gap-14" data-when="delivery"<?= $type === 'pickup' ? ' hidden' : '' ?>>
      <div class="overline"><?= e(t('deliv.addresses')) ?></div>
      <div class="col gap-8" data-addresses></div>
      <?= Ui::btn(t('deliv.add_address'), ['style' => 'ghost', 'size' => 's', 'icon' => 'plus', 'class' => 'start-self', 'attrs' => ['data-add-address' => true]]) ?>
      <div class="col gap-8" data-new-address hidden>
        <?= Ui::field('address_label', ['label' => t('deliv.addr_label'), 'icon' => 'tag']) ?>
        <?= Ui::field('address', ['label' => t('deliv.addr_text'), 'icon' => 'map-pin', 'textarea' => true]) ?>
      </div>
      <div class="overline"><?= e(t('deliv.courier')) ?></div>
      <div class="couropts"><?= $courierTiles ?></div>
      <p class="t-body-s c-muted"><?= e(t('deliv.eta', ['min' => digits($etaMin), 'max' => digits($etaMax ?: $etaMin)])) ?></p>
    </div>
    <div data-when="pickup"<?= $type === 'delivery' ? ' hidden' : '' ?>>
      <?= Ui::field('pickup_at', ['label' => t('deliv.pickup_time'), 'icon' => 'clock', 'type' => 'time']) ?>
    </div>
  </section>

  <section class="phord__menu">
    <?= Ui::field('q', ['type' => 'search', 'icon' => 'search', 'placeholder' => t('menu.search'), 'attrs' => ['data-menu-search' => true]]) ?>
    <div class="chips chips--scroll" data-cats>
      <?= Ui::chip(t('order.popular'), true, null, ['data-cat' => 'pop']) ?>
      <?php foreach ($cats as $c): ?><?= Ui::chip(tn($c['names']), false, null, ['data-cat' => $c['id']]) ?><?php endforeach ?>
    </div>
    <div class="mtiles mtiles--phone" data-menu>
      <?php foreach ($items as $i): ?>
        <?= OrderUi::menuTile($i, 0, [
            'data-cat' => $i['category_id'], 'data-pop' => isset($popular[$i['id']]) ? '1' : null, 'data-q' => mb_strtolower(implode(' ', json_arr($i['names'])), 'UTF-8'),
            'data-price' => (int) $i['price'], 'data-name' => tn($i['names']), 'data-required' => (int) ($groups[$i['id']] ?? 0) > 0 ? '1' : null,
            'data-groups' => isset($groups[$i['id']]) ? '1' : null, 'hidden' => !isset($popular[$i['id']]),
        ]) ?>
      <?php endforeach ?>
    </div>
    <div class="empty" data-menu-empty hidden><?= e(t('menu.empty')) ?></div>
  </section>

  <section class="phord__order">
    <div class="row gap-8"><h2 class="t-heading-l grow" data-order-title><?= e($type === 'pickup' ? $cfg['str']['pickup'] : $cfg['str']['delivery']) ?></h2><?= Ui::badge(t('deliv.new'), 'accent', true) ?></div>
    <div class="phord__lines" data-cart><div class="empty"><?= e(t('deliv.cart_empty')) ?></div></div>
    <?= Ui::field('note', ['label' => t($type === 'pickup' ? 'deliv.order_note' : 'deliv.courier_note'), 'icon' => 'note', 'attrs' => ['data-note' => true]]) ?>
    <div class="grow"></div>
    <div class="phord__totals">
      <div class="kv"><span><?= e(t('order.subtotal')) ?></span><span class="num" data-sub>₺0</span></div>
      <div class="kv" data-when="delivery"<?= $type === 'pickup' ? ' hidden' : '' ?>><span><?= e(t('deliv.fee_line')) ?></span><span class="num<?= $fee ? '' : ' c-success' ?>"><?= e($fee ? money($fee) : t('deliv.free')) ?></span></div>
      <div class="kv kv--big t-heading-l"><span><?= e(t('pay.total')) ?></span><span class="num" data-total>₺0</span></div>
    </div>
    <div class="overline" data-pay-title data-door="<?= e(t('deliv.pay_door')) ?>" data-counter="<?= e(t('deliv.pay_pickup')) ?>"><?= e(t($type === 'pickup' ? 'deliv.pay_pickup' : 'deliv.pay_door')) ?></div>
    <?= Ui::segs(['cash' => t('deliv.pay_cash'), 'card' => t('deliv.pay_card'), 'account' => t('deliv.pay_account')], 'cash', 'pay') ?>
    <div data-cash-given><?= Ui::field('cash_given', ['label' => t('deliv.cash_given'), 'icon' => 'cash', 'attrs' => ['inputmode' => 'decimal']]) ?></div>
    <?= Ui::btn(t('deliv.create'), ['type' => 'submit', 'size' => 'l', 'block' => true, 'icon' => 'check']) ?>
  </section>
</form>
