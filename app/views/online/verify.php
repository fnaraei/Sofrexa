<?php
/**
 * E-mail code — Figma O2 (48:181): the 6-digit CodeInput, the resend countdown, "Doğrula ve hesabı aç" and
 * "E-postayı değiştir" (back to O7 with the fields kept). Desktop: the same in a centred panel under the SiteHeader.
 * @var array $a @var int $wait @var string $next
 */
use Sofrexa\View\Ui;

$title = t('on.verify_title');
$scripts = ['js/online.js'];
$bodyClass = 'online-auth';
?>
<?= \Sofrexa\Core\View::partial('online/_site', ['acc' => null]) ?>
<?= \Sofrexa\Core\View::partial('online/_sub', ['back' => '/online/kayit', 'title' => t('on.verify_title'), 'sub' => t('on.verify_sub')]) ?>
<main class="oform oform--code">
  <h1 class="t-display-m only-desktop"><?= e(t('on.verify_title')) ?></h1>
  <p class="t-body-m c-secondary tcenter"><?= e(t('on.verify_text', ['email' => (string) $a['email']])) ?></p>
  <form class="oauth__form" method="post" action="/online/dogrula" data-ajax data-toast="off" data-online-form>
    <?= csrf_field() ?><input type="hidden" name="next" value="<?= e($next) ?>">
    <?= \Sofrexa\Core\View::partial('online/_code') ?>
    <p class="t-label-m c-muted tcenter" data-resend="<?= (int) $wait ?>" data-resend-url="/online/kod" data-text-wait="<?= e(t('on.resend_in', ['t' => '{t}'])) ?>" data-text-go="<?= e(t('on.resend')) ?>"></p>
    <?= Ui::btn(t('on.verify_btn'), ['type' => 'submit', 'style' => 'accent', 'size' => 'l', 'block' => true, 'icon' => 'check']) ?>
  </form>
  <?= Ui::btn(t('on.change_email'), ['style' => 'ghost', 'icon' => 'pencil', 'href' => '/online/kayit']) ?>
</main>
