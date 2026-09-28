<?php
/**
 * New customer / edit — Figma CU6 (81:888): name, phone (the loyalty key), e-mail, address, loyalty and account
 * switches. Editing adds the company, tax number, note, credit limit, own discount and the black list
 * (not a separate frame; same Input / ToggleRow components). Opened from a bill ($order) it saves and adds
 * the customer to that bill. @var ?array $c @var string $order @var string $name @var string $phone @var ?string $address
 */
use Sofrexa\Core\I18n;
use Sofrexa\Modules\Customers\Loyalty;
use Sofrexa\View\Ui;

$v = static fn(string $k, $d = '') => $c[$k] ?? $d;
$manage = can('customers.manage');
$earn = digits(I18n::numAuto((float) (Loyalty::tiers()[0]['earn_pct'] ?? 0)));
?>
<div class="scrim" data-dyn hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead($c ? t('cust.edit_t') : t('cust.new')) ?>
    <form class="sheet__body" method="post" action="/customers/save" data-ajax data-toast="off">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= e($v('id')) ?>">
      <?php if ($order !== ''): ?><input type="hidden" name="order" value="<?= e($order) ?>"><?php endif ?>
      <?= Ui::field('name', ['label' => t('cust.f_name'), 'icon' => 'user', 'value' => (string) ($v('name') ?: $name), 'attrs' => ['required' => true, 'autofocus' => !$c]]) ?>
      <?= Ui::field('phone', ['label' => t('cust.f_phone'), 'icon' => 'phone', 'type' => 'tel', 'value' => (string) ($v('phone') ?: $phone), 'attrs' => ['required' => true, 'inputmode' => 'tel']]) ?>
      <?= Ui::field('email', ['label' => t('cust.f_email'), 'icon' => 'mail', 'type' => 'email', 'value' => (string) $v('email')]) ?>
      <?= Ui::field('address', ['label' => t('cust.f_address'), 'icon' => 'map-pin', 'value' => (string) ($address ?? '')]) ?>
      <?php if ($c): ?>
        <div class="grid2">
          <?= Ui::field('company', ['label' => t('cust.f_company'), 'icon' => 'store', 'value' => (string) $v('company')]) ?>
          <?= Ui::field('tax_no', ['label' => t('cust.f_tax'), 'icon' => 'file-text', 'value' => (string) $v('tax_no')]) ?>
        </div>
        <?= Ui::field('note', ['label' => t('cust.f_note'), 'icon' => 'note', 'value' => (string) $v('note')]) ?>
      <?php endif ?>
      <div class="trow--2"><?= Ui::toggleRow('loyalty', t('cust.f_loyalty'), t('cust.f_loyalty_s', ['p' => $earn]), !$c || (int) $v('loyalty', 1) === 1) ?></div>
      <div class="trow--2"><?= Ui::toggleRow('credit', t('cust.f_credit'), $manage ? t('cust.f_credit_s') : t('cust.f_credit_need'), (bool) $v('credit_enabled', 0), $manage ? [] : ['disabled' => true]) ?></div>
      <?php if ($manage && $c): ?>
        <div class="grid2">
          <?= Ui::field('credit_limit', ['label' => t('cust.f_limit'), 'icon' => 'wallet', 'value' => (int) $v('credit_limit', 0) > 0 ? money((int) $v('credit_limit')) : '', 'placeholder' => t('cust.f_limit_none'), 'attrs' => ['inputmode' => 'decimal']]) ?>
          <?= Ui::field('discount_pct', ['label' => t('cust.f_discount'), 'icon' => 'percent', 'value' => (float) $v('discount_pct', 0) > 0 ? I18n::numAuto((float) $v('discount_pct')) : '', 'placeholder' => '0', 'attrs' => ['inputmode' => 'decimal']]) ?>
        </div>
        <div class="trow--2"><?= Ui::toggleRow('blacklist', t('cust.f_blacklist'), t('cust.f_blacklist_s'), (bool) $v('blacklist', 0)) ?></div>
      <?php endif ?>
      <div class="sheet__actions">
        <?= Ui::btn(t('ui.cancel'), ['style' => 'secondary', 'size' => 'l', 'attrs' => ['data-close' => true]]) ?>
        <?= Ui::btn($order !== '' ? t('cust.save_add') : t('ui.save'), ['size' => 'l', 'icon' => $c ? 'check' : 'user-plus', 'type' => 'submit']) ?>
      </div>
      <?php if ($c && $manage): ?>
        <button type="button" class="btn btn--ghost btn--s c-danger sheet__del" data-post="/customers/<?= e($c['id']) ?>/delete" data-confirm="<?= e(t('cust.delete_q', ['name' => $c['name']])) ?>"><?= icon('trash', 16) ?><span><?= e(t('cust.delete')) ?></span></button>
      <?php endif ?>
    </form>
  </div>
</div>
