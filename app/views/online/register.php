<?php
/**
 * Online sign-up — Figma O7 (87:360, phone); desktop shows O9. The rule line turns green once the password fits it.
 * The terms box starts unticked (the customer gives consent); the marketing opt-in is optional.
 * @var string $next @var string $turnstile @var array $old
 */
use Sofrexa\Core\I18n;
use Sofrexa\View\Ui;

$title = t('on.sign_up');
$scripts = ['js/online.js'];
$bodyClass = 'online-auth';
?>
<?= \Sofrexa\Core\View::partial('online/_sub', ['back' => '/online/giris' . ($next !== '' ? '?next=' . rawurlencode($next) : ''), 'title' => t('on.sign_up'), 'sub' => t('on.reg_sub')]) ?>
<main class="oform only-mobile">
  <form class="oauth__form" method="post" action="/online/kayit" data-ajax data-toast="off" data-online-form>
    <?= csrf_field() ?><input type="hidden" name="next" value="<?= e($next) ?>">
    <?= Ui::field('name', ['id' => 'r-name', 'label' => t('on.name'), 'icon' => 'user', 'autocomplete' => 'name', 'value' => (string) ($old['name'] ?? '')]) ?>
    <?= Ui::field('email', ['id' => 'r-email', 'label' => t('on.email_user'), 'icon' => 'mail', 'type' => 'email', 'autocomplete' => 'email', 'value' => (string) ($old['email'] ?? '')]) ?>
    <?= Ui::field('phone', ['id' => 'r-phone', 'label' => t('on.phone'), 'icon' => 'phone', 'type' => 'tel', 'autocomplete' => 'tel', 'value' => (string) ($old['phone'] ?? '')]) ?>
    <div class="col gap-8">
      <?= Ui::field('password', ['id' => 'r-pw', 'label' => t('on.password'), 'icon' => 'lock', 'type' => 'password', 'autocomplete' => 'new-password', 'attrs' => ['data-pw-rule' => '#r-rule']]) ?>
      <p class="t-body-s c-muted opwrule" id="r-rule"><?= e(t('on.pw_rule')) ?></p>
    </div>
    <label class="checkrow"><?= Ui::checkbox('terms', false) ?><span class="grow"><?= e(t('on.terms')) ?></span></label>
    <label class="checkrow"><?= Ui::checkbox('marketing', !empty($old['marketing'])) ?><span class="grow"><?= e(t('on.marketing')) ?></span></label>
    <?php if ($turnstile !== ''): ?><div class="cf-turnstile" data-sitekey="<?= e($turnstile) ?>" data-language="<?= e(I18n::lang()) ?>"></div><?php endif ?>
    <?= Ui::btn(t('on.create'), ['type' => 'submit', 'style' => 'accent', 'size' => 'l', 'block' => true]) ?>
  </form>
  <p class="t-body-s c-muted tcenter"><?= e(t('on.reg_hint')) ?></p>
  <p class="row center-x gap-6"><span class="t-body-s c-secondary"><?= e(t('on.have_account')) ?></span><a class="olink" href="/online/giris<?= $next !== '' ? '?next=' . e(rawurlencode($next)) : '' ?>"><?= e(t('on.sign_in')) ?></a></p>
</main>
<?= \Sofrexa\Core\View::partial('online/_auth_desk', ['next' => $next, 'turnstile' => $turnstile]) ?>
