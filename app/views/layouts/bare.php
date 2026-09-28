<?php
/** Layout without navigation: login, error pages, kitchen TV. Variables: $title, $theme, $scripts, $bodyClass. */
use Sofrexa\Core\I18n;
?><!doctype html>
<html lang="<?= e(I18n::lang()) ?>" dir="<?= I18n::dir() ?>"<?= ($theme ?? '') === 'dark' ? ' data-theme="dark"' : '' ?>>
<?= \Sofrexa\Core\View::partial('partials/head', ['title' => $title ?? '', 'theme' => $theme ?? null, 'scripts' => $scripts ?? []]) ?>
<body class="<?= e($bodyClass ?? '') ?>">
<?= $content ?>
<div class="toasts" aria-live="polite"></div>
</body>
</html>
