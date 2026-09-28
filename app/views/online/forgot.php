<?php
/**
 * New password — Figma O8 (87:446): "Kod gönderildi" banner, the code, the new password twice, resend countdown.
 * The first step (the e-mail) is not in Figma: a short form in the same style.
 * @var string $email @var bool $sent @var int $wait
 */
use Sofrexa\View\Ui;

$title = t('on.forgot');
$scripts = ['js/online.js'];
$bodyClass = 'online-auth';
?>
<?= \Sofrexa\Core\View::partial('online/_site', ['acc' => null]) ?>
<?= \Sofrexa\Core\View::partial('online/_sub', ['back' => '/online/giris', 'title' => t('on.forgot'), 'sub' => t('on.forgot_sub')]) ?>
<main class="oform">
  <h1 class="t-display-m only-desktop"><?= e(t('on.forgot')) ?></h1>
  <?php if (!$sent): ?>
    <p class="t-body-m c-secondary tcenter"><?= e(t('on.forgot_text')) ?></p>
    <form class="oauth__form" method="post" action="/online/sifre" data-ajax data-toast="off" data-online-form>
      <?= csrf_field() ?>
      <?= Ui::field('email', ['id' => 'f-email', 'label' => t('on.email'), 'icon' => 'mail', 'type' => 'email', 'autocomplete' => 'username', 'value' => $email]) ?>
      <?= Ui::btn(t('on.send_code'), ['type' => 'submit', 'style' => 'accent', 'size' => 'l', 'block' => true, 'icon' => 'mail']) ?>
    </form>
  <?php else: ?>
    <div class="banner banner--success" role="status"><?= icon('mail', 20) ?><div class="col gap-2"><div class="banner__title"><?= e(t('on.code_sent')) ?></div><div class="banner__text"><?= e(t('on.code_sent_text', ['email' => $email])) ?></div></div></div>
    <form class="oauth__form" method="post" action="/online/sifre/yeni" data-ajax data-toast="off" data-online-form>
      <?= csrf_field() ?>
      <div class="overline tcenter"><?= e(t('on.code')) ?></div>
      <?= \Sofrexa\Core\View::partial('online/_code') ?>
      <?= Ui::field('password', ['id' => 'f-pw', 'label' => t('on.new_pw'), 'icon' => 'lock', 'type' => 'password', 'autocomplete' => 'new-password', 'attrs' => ['data-pw-rule' => '#f-rule']]) ?>
      <p class="t-body-s c-muted opwrule" id="f-rule"><?= e(t('on.pw_rule')) ?></p>
      <?= Ui::field('password2', ['id' => 'f-pw2', 'label' => t('on.new_pw2'), 'icon' => 'lock', 'type' => 'password', 'autocomplete' => 'new-password']) ?>
      <?= Ui::btn(t('on.reset_btn'), ['type' => 'submit', 'style' => 'accent', 'size' => 'l', 'block' => true]) ?>
    </form>
    <button type="button" class="btn btn--ghost" data-resend="<?= (int) $wait ?>" data-resend-url="/online/kod" data-resend-purpose="reset" data-text-wait="<?= e(t('on.resend_code') . ' · {t}') ?>" data-text-go="<?= e(t('on.resend_code')) ?>"><?= icon('refresh', 18) ?><span></span></button>
    <a class="olink" href="/online/sifre?step=email"><?= e(t('on.change_email')) ?></a>
  <?php endif ?>
</main>
