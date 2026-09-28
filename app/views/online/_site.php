<?php
/**
 * SiteHeader of the public pages on desktop (Figma O5/O6/O9): tenant logo and name, the website's links with
 * "Online sipariş" active, language, and "Giriş yap" or the customer's first name (opens the account sheet).
 * @var ?array $acc
 */
use Sofrexa\Core\{I18n, Settings};
use Sofrexa\View\{Brand, Ui};

$site = rtrim((string) Settings::get('profile.website', ''), '/');
$name = (string) (Settings::get('profile.short_name', '') ?: Settings::get('profile.name'));
$logo = Brand::logoUrl();
$links = $site !== '' ? [[t('on.nav_home'), $site . '/'], [t('on.nav_menu'), $site . '/menu'], [t('on.title'), '/online'], [t('on.nav_contact'), $site . '/contact']] : [[t('on.title'), '/online']];
?>
<header class="osite only-desktop">
  <a class="osite__brand" href="/online"><?php if ($logo): ?><img class="osite__logo" src="<?= e($logo) ?>" alt=""><?php endif ?><span class="t-display-m"><?= e(mb_strtoupper($name, 'UTF-8')) ?></span></a>
  <nav class="osite__nav">
    <?php foreach ($links as [$label, $href]): ?><a class="t-label-m<?= $href === '/online' ? ' is-active' : '' ?>" href="<?= e($href) ?>"><?= e($label) ?></a><?php endforeach ?>
  </nav>
  <span class="grow"></span>
  <?= Ui::btn(strtoupper(I18n::lang()), ['style' => 'ghost', 'size' => 's', 'icon' => 'globe', 'attrs' => ['data-sheet' => 'guest-lang']]) ?>
  <?php if ($acc ?? null): ?>
    <?= Ui::btn(first_name((string) $acc['name']), ['style' => 'secondary', 'size' => 's', 'icon' => 'user', 'attrs' => ['data-load-sheet' => '/online/hesap']]) ?>
  <?php else: ?>
    <?= Ui::btn(t('on.sign_in'), ['style' => 'secondary', 'size' => 's', 'icon' => 'user', 'href' => '/online/giris']) ?>
  <?php endif ?>
</header>
