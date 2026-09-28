<?php
/**
 * Desktop sign-in / sign-up — Figma O9 (88:391): SiteHeader, the 960 auth card (sign-in left, sign-up right),
 * benefits and POWERED BY SOFREXA. The terms box starts unticked: consent is given by the customer.
 * @var string $next @var string $turnstile
 */
use Sofrexa\Core\I18n;
use Sofrexa\Modules\Online\OnlineOrders;
use Sofrexa\View\{Brand, Ui};

$fee = OnlineOrders::fee();
$cf = $turnstile !== '' ? '<div class="cf-turnstile" data-sitekey="' . e($turnstile) . '" data-language="' . e(I18n::lang()) . '"></div>' : '';
?>
<div class="only-desktop odesk-wrap">
  <?= \Sofrexa\Core\View::partial('online/_site', ['acc' => null]) ?>
  <main class="odesk">
    <h1 class="t-display-l"><?= e(t('on.title')) ?></h1>
    <p class="t-body-l c-secondary"><?= e(t('on.login_sub_d')) ?></p>
    <div class="oauthcard">
      <form class="oauthcard__col" method="post" action="/online/giris" data-ajax data-toast="off" data-online-form>
        <?= csrf_field() ?><input type="hidden" name="next" value="<?= e($next) ?>">
        <div class="col gap-4"><h2 class="t-heading-l"><?= e(t('on.sign_in')) ?></h2><p class="t-body-m c-muted"><?= e(t('on.login_card')) ?></p></div>
        <?= Ui::field('email', ['id' => 'd-email', 'label' => t('on.email'), 'icon' => 'mail', 'type' => 'email', 'autocomplete' => 'username']) ?>
        <?= Ui::field('password', ['id' => 'd-pw', 'label' => t('on.password'), 'icon' => 'lock', 'type' => 'password', 'autocomplete' => 'current-password']) ?>
        <div class="olinks"><label class="checkrow grow"><?= Ui::checkbox('remember', true) ?><span><?= e(t('on.remember')) ?></span></label><a class="olink" href="/online/sifre?step=email"><?= e(t('on.forgot')) ?></a></div>
        <?= $cf ?>
        <?= Ui::btn(t('on.sign_in'), ['type' => 'submit', 'style' => 'accent', 'size' => 'l', 'block' => true]) ?>
      </form>
      <form class="oauthcard__col oauthcard__col--reg" method="post" action="/online/kayit" data-ajax data-toast="off" data-online-form>
        <?= csrf_field() ?><input type="hidden" name="next" value="<?= e($next) ?>">
        <div class="col gap-4"><h2 class="t-heading-l"><?= e(t('on.register_card')) ?></h2><p class="t-body-m c-muted"><?= e(t('on.register_card_sub')) ?></p></div>
        <div class="orow2">
          <?= Ui::field('name', ['id' => 'd-name', 'label' => t('on.name'), 'icon' => 'user', 'autocomplete' => 'name']) ?>
          <?= Ui::field('phone', ['id' => 'd-phone', 'label' => t('on.phone'), 'icon' => 'phone', 'type' => 'tel', 'autocomplete' => 'tel']) ?>
        </div>
        <div class="orow2">
          <?= Ui::field('email', ['id' => 'd-remail', 'label' => t('on.email'), 'icon' => 'mail', 'type' => 'email', 'autocomplete' => 'email']) ?>
          <?= Ui::field('password', ['id' => 'd-rpw', 'label' => t('on.password'), 'icon' => 'lock', 'type' => 'password', 'autocomplete' => 'new-password', 'placeholder' => t('on.pw_ph')]) ?>
        </div>
        <label class="checkrow"><?= Ui::checkbox('terms', false) ?><span class="grow"><?= e(t('on.terms_short')) ?></span></label>
        <?= $cf ?>
        <?= Ui::btn(t('on.create'), ['type' => 'submit', 'style' => 'secondary', 'size' => 'l', 'block' => true, 'icon' => 'user-plus']) ?>
        <p class="t-body-s c-muted"><?= e(t('on.reg_hint_d')) ?></p>
      </form>
    </div>
    <div class="obenefits">
      <span><?= icon('truck', 20) ?><?= e($fee ? t('on.benefit_fee', ['amount' => money($fee)]) : t('on.benefit_free')) ?></span>
      <span><?= icon('tag', 20) ?><?= e(t('on.benefit_min', ['amount' => money(OnlineOrders::minOrder())])) ?></span>
      <span><?= icon('cash', 20) ?><?= e(t('on.benefit_pay')) ?></span>
    </div>
    <?= Brand::poweredBy(true) ?>
  </main>
  <?php if ($turnstile !== ''): ?><script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script><?php endif ?>
</div>
