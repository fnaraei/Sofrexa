<?php
/**
 * Set the remote sign-in password from an e-mailed link. Same frame as the sign-in screen (L1/L3).
 * @var ?array $reset @var string $token
 */
use Sofrexa\View\Brand;
use Sofrexa\View\Ui;
?>
<div class="login">
  <aside class="login__brand" data-theme="dark">
    <div class="login__brandline" style="align-items:flex-start"><?= Brand::tenantLogo('l') ?><?= Brand::poweredBy(true) ?></div>
  </aside>
  <main class="login__main">
    <div class="login__brandline"><?= Brand::tenantLogo('l') ?><?= Brand::poweredBy() ?></div>
    <section class="login__card">
      <h1 class="login__title"><?= e(t('pw.title')) ?></h1>
      <?php if (!$reset): ?>
        <?= Ui::banner(t('pw.title'), t('pw.invalid'), 'danger', 'alert') ?>
        <?= Ui::btn(t('login.submit'), ['href' => '/login?mode=password', 'style' => 'secondary', 'size' => 'l']) ?>
      <?php else: ?>
        <p class="login__sub"><?= e(t('pw.sub', ['name' => $reset['name']])) ?></p>
        <form class="login__form" method="post" action="/password/set" data-ajax>
          <?= csrf_field() ?>
          <input type="hidden" name="token" value="<?= e($token) ?>">
          <input type="text" name="username" value="<?= e($reset['email']) ?>" autocomplete="username" hidden>
          <?= Ui::field('password', ['label' => t('pw.new'), 'icon' => 'lock', 'type' => 'password', 'autocomplete' => 'new-password', 'help' => t('pw.rules'), 'attrs' => ['required' => true, 'minlength' => 10, 'autofocus' => true]]) ?>
          <?= Ui::field('password2', ['label' => t('pw.repeat'), 'icon' => 'lock', 'type' => 'password', 'autocomplete' => 'new-password', 'attrs' => ['required' => true, 'minlength' => 10]]) ?>
          <?= Ui::btn(t('pw.save'), ['type' => 'submit', 'size' => 'l', 'block' => true, 'icon' => 'check']) ?>
        </form>
      <?php endif ?>
    </section>
  </main>
</div>
