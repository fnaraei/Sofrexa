<?php
/**
 * Staff shell. Desktop (≥1024px): SideNav + page head. Mobile: AppBar, content, action bar or tab bar.
 * Page variables: $title, $sub, $back, $nav, $tab, $appActions[], $headActions, $bottom, $theme,
 * $scripts[], $noTabbar, $noHead, $bodyClass, $appSub (phone subtitle when it differs from the desktop one),
 * $aside / $asideStart: full-height desktop panels after / before the main column (W10 order panel, C4 customer panel).
 * $topBanner: a banner above the page head on desktop and under the AppBar on phones (C1b / C8b).
 */
use Sofrexa\Core\I18n;
use Sofrexa\Core\Settings;
use Sofrexa\View\Shell;
use Sofrexa\View\Ui;

$u = user();
$nav ??= '';
$tab ??= $nav;
$sub ??= '';
$appSub ??= $sub;
$appTitle ??= $title ?? '';
$back ??= null;
$appActions ??= [];
$headActions ??= '';
$bottom ??= '';
$scripts ??= [];
$aside ??= '';
$asideStart ??= '';
$tabs = empty($noTabbar) && $bottom === '' ? Shell::tabSet() : null;
$lang = I18n::lang();
?><!doctype html>
<html lang="<?= e($lang) ?>" dir="<?= I18n::dir() ?>"<?= !empty($theme) && $theme === 'dark' ? ' data-theme="dark"' : '' ?>>
<?= \Sofrexa\Core\View::partial('partials/head', ['title' => $title ?? '', 'theme' => $theme ?? null, 'scripts' => $scripts]) ?>
<body class="<?= e($bodyClass ?? '') ?>">
<div class="shell<?= $aside !== '' ? ' has-aside' : '' ?><?= $asideStart !== '' ? ' has-aside-start' : '' ?>">
<?php if ($u): ?>
  <aside class="side" aria-label="<?= e(t('nav.aria')) ?>">
    <div class="side__head">
      <?= \Sofrexa\View\Brand::tenantLogo('s') ?>
      <?= Ui::sync() ?>
    </div>
    <nav class="side__items">
      <?php foreach (Shell::navItems() as $key => [$label, $icon, $href]): ?>
        <a class="nav<?= $key === $nav ? ' is-active' : '' ?>" href="<?= e($href) ?>"<?= $key === $nav ? ' aria-current="page"' : '' ?>><?= icon($icon, 20) ?><span class="nav__label"><?= e($label) ?></span></a>
      <?php endforeach ?>
    </nav>
    <div class="side__spacer"></div>
    <div class="side__user">
      <?= Ui::avatar($u['name']) ?>
      <div class="grow"><div class="t-label-m ellipsis"><?= e($u['name']) ?></div><div class="t-body-s c-muted"><?= e(t('role.' . $u['role_code'])) ?></div></div>
      <form method="post" action="/logout"><?= csrf_field() ?><button class="ibtn ibtn--s" type="submit" aria-label="<?= e(t('ui.logout')) ?>" title="<?= e(t('ui.logout')) ?>"><?= icon('logout', 20) ?></button></form>
    </div>
  </aside>
<?php endif ?>
<?php if ($asideStart !== ''): ?>  <aside class="xpanel xpanel--start"><?= $asideStart ?></aside>
<?php endif ?>
  <main class="main">
    <header class="appbar">
      <?php if ($back): ?><a class="appbar__back" href="<?= e($back) ?>" aria-label="<?= e(t('ui.back')) ?>"><?= icon('arrow-left', 24) ?></a><?php endif ?>
      <div class="appbar__titles"><div class="appbar__title"><?= e($appTitle) ?></div><?php if ($appSub !== ''): ?><div class="appbar__sub"><?= e($appSub) ?></div><?php endif ?></div>
      <?php foreach ($appActions as $a) echo $a; ?>
    </header>
    <?php if (!empty($topBanner)): ?><div class="topbanner"><?= $topBanner ?></div><?php endif ?>
    <?php if (empty($noHead)): ?><?= Ui::pageHead($title ?? '', $sub, $headActions) ?><?php endif ?>
    <?php if (\Sofrexa\Sync\Emergency::on()): ?><div class="emgbar"><?= Ui::banner(t('emg.banner_t'), t('emg.banner'), 'warning', 'alert') ?></div><?php endif ?>
    <?php foreach (\Sofrexa\Core\Flash::take() as [$type, $msg]): ?><?= Ui::banner($msg, '', $type === 'error' ? 'danger' : 'success', $type === 'error' ? 'alert' : 'check-circle') ?><?php endforeach ?>
    <div class="content">
      <?= $content ?>
    </div>
    <?php if ($bottom !== ''): ?><div class="actionbar only-mobile"><?= $bottom ?></div><?php endif ?>
    <?php if ($tabs): ?>
    <nav class="tabbar" aria-label="<?= e(t('nav.aria')) ?>">
      <?php foreach ($tabs as $key => [$label, $icon, $href]): ?>
        <a class="tab<?= $key === $tab ? ' is-active' : '' ?>" href="<?= e($href) ?>"><span class="tab__pill"><?= icon($icon, 22) ?><?php if ($key === 'notifications'): ?><i class="tab__dot" data-notif-dot hidden></i><?php elseif ($key === 'stock' && Shell::stockAlert()): ?><i class="tab__dot"></i><?php endif ?></span><span class="tab__label"><?= e(t($label)) ?></span></a>
      <?php endforeach ?>
    </nav>
    <?php endif ?>
  </main>
<?php if ($aside !== ''): ?>  <aside class="xpanel"><?= $aside ?></aside>
<?php endif ?>
</div>
<div class="toasts" aria-live="polite"></div>
</body>
</html>
