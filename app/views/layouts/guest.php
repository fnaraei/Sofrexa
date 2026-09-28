<?php
/** Guest pages of the QR table card (Figma Q1–Q4): the dark customer theme, no navigation. Variables: $title, $scripts, $bodyClass. */
use Sofrexa\Core\I18n;
?><!doctype html>
<html lang="<?= e(I18n::lang()) ?>" dir="<?= I18n::dir() ?>" data-theme="dark">
<?= \Sofrexa\Core\View::partial('partials/head', ['title' => $title ?? '', 'theme' => 'dark', 'scripts' => $scripts ?? []]) ?>
<body class="guest <?= e($bodyClass ?? '') ?>">
<?= $content ?>
<?= \Sofrexa\Core\View::partial('qr/_lang') ?>
<div class="toasts" aria-live="polite"></div>
</body>
</html>
