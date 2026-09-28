<?php
/** Error page (404, 403, 419, 500). Variables: $status, $message. */
use Sofrexa\View\Brand;
use Sofrexa\View\Ui;

$titles = [403 => 'err.forbidden', 404 => 'err.not_found', 405 => 'err.method', 419 => 'err.csrf'];
$msg = $message !== '' && $message !== (string) $status ? $message : t($titles[$status] ?? 'err.server');
?>
<div class="errorpage">
  <?php if (!user()): ?><div class="login__brandline"><?= Brand::tenantLogo('l') ?><?= Brand::poweredBy() ?></div><?php endif ?>
  <div class="card errorpage__card">
    <div class="errorpage__code num"><?= (int) $status ?></div>
    <h1 class="t-heading-l"><?= e(t('err.title')) ?></h1>
    <p class="t-body-m c-muted"><?= e($msg) ?></p>
    <?= Ui::btn(t('err.go_home'), ['href' => '/', 'icon' => 'home', 'style' => 'secondary']) ?>
  </div>
</div>
