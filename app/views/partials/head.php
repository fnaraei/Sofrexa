<?php
/** <head> shared by all layouts. Variables: $title, $theme, $scripts. */
use Sofrexa\Core\I18n;
use Sofrexa\Core\Settings;

$u = user();
$scripts ??= [];
?><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="<?= ($theme ?? '') === 'dark' ? '#0a110d' : '#f6f2e9' ?>">
<meta name="robots" content="noindex, nofollow">
<title><?= e(($title ?? '') !== '' ? $title . ' · ' : '') . Settings::get('profile.name') ?></title>
<link rel="manifest" href="/manifest.webmanifest">
<link rel="icon" href="<?= e(asset('img/sofrexa-mark.png')) ?>">
<link rel="apple-touch-icon" href="<?= e(asset('img/app-icon-192.png')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/fonts.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/tokens.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<script>window.SOFREXA = <?= json_encode([
    'csrf' => \Sofrexa\Core\Csrf::token(),
    'lang' => I18n::lang(),
    'dir' => I18n::dir(),
    'user' => $u ? ['id' => $u['id'], 'name' => $u['name'], 'role' => $u['role_code']] : null,
    'icons' => asset('icons.svg'),
    'idleLock' => (int) Settings::get('security.idle_lock_minutes', 0),
    't' => I18n::jsStrings(['js.']),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;</script>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
<?php foreach ($scripts as $s): ?><script src="<?= e(asset($s)) ?>" defer></script>
<?php endforeach ?>
</head>
