<?php
/**
 * Online sign-in — Figma O1 (48:142, phone) and O9 (88:391, desktop, with sign-up next to it).
 * @var string $next @var string $turnstile
 */
use Sofrexa\Core\I18n;
use Sofrexa\View\{Brand, Ui};

$title = t('on.title');
$scripts = ['js/online.js'];
$bodyClass = 'online-auth';
$logo = Brand::logoUrl();
?>
<main class="oauth only-mobile">
  <?php if ($logo): ?><img class="oauth__logo" src="<?= e($logo) ?>" alt=""><?php endif ?>
  <h1 class="t-display-l"><?= e(t('on.title')) ?></h1>
  <p class="t-body-m c-secondary oauth__lead"><?= e(t('on.login_sub')) ?></p>
  <form class="oauth__form" method="post" action="/online/giris" data-ajax data-toast="off" data-online-form>
    <?= csrf_field() ?><input type="hidden" name="next" value="<?= e($next) ?>">
    <?= Ui::field('email', ['id' => 'm-email', 'label' => t('on.email'), 'icon' => 'mail', 'type' => 'email', 'autocomplete' => 'username']) ?>
    <?= Ui::field('password', ['id' => 'm-pw', 'label' => t('on.password'), 'icon' => 'lock', 'type' => 'password', 'autocomplete' => 'current-password']) ?>
    <div class="olinks"><label class="checkrow grow"><?= Ui::checkbox('remember', true) ?><span><?= e(t('on.remember')) ?></span></label><a class="olink" href="/online/sifre?step=email"><?= e(t('on.forgot')) ?></a></div>
    <?php if ($turnstile !== ''): ?><div class="cf-turnstile" data-sitekey="<?= e($turnstile) ?>" data-language="<?= e(I18n::lang()) ?>"></div><?php endif ?>
    <?= Ui::btn(t('on.sign_in'), ['type' => 'submit', 'style' => 'accent', 'size' => 'l', 'block' => true]) ?>
  </form>
  <div class="ordiv"><i></i><span class="t-body-s c-muted"><?= e(t('on.no_account')) ?></span><i></i></div>
  <?= Ui::btn(t('on.sign_up'), ['style' => 'secondary', 'size' => 'l', 'block' => true, 'icon' => 'user-plus', 'href' => '/online/kayit' . ($next !== '' ? '?next=' . rawurlencode($next) : '')]) ?>
  <span class="grow"></span>
  <p class="t-body-s c-muted oauth__legal"><?= e(t('on.legal')) ?></p>
  <?= Brand::poweredBy(true) ?>
</main>
<?= \Sofrexa\Core\View::partial('online/_auth_desk', ['next' => $next, 'turnstile' => $turnstile]) ?>
