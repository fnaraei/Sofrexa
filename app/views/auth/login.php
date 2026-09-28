<?php
/**
 * Staff sign-in — Figma L1 (13:2), L2 FA (13:100), L3 desktop (15:115).
 * Phones: logo, avatar row, PIN dots, keypad, language switch. Till PC (≥1024px): dark brand panel with
 * clock + white card with staff chips. $mode = pin | password (remote sign-in on the web copy).
 *
 * @var array  $staff  [['id','label','name','role'], ...]
 * @var string $mode
 * @var string $next
 */
use Sofrexa\Core\App;
use Sofrexa\Core\Clock;
use Sofrexa\Core\I18n;
use Sofrexa\View\Brand;
use Sofrexa\View\Ui;

$now = Clock::ms();
$langs = array_intersect_key(I18n::LANGS, array_flip((array) \Sofrexa\Core\Settings::get('lang.staff', array_keys(I18n::LANGS))));
?>
<div class="login" data-login data-next="<?= e($next) ?>">
  <aside class="login__brand" data-theme="dark">
    <div class="login__brandline" style="align-items:flex-start">
      <?= Brand::tenantLogo('l') ?>
      <?= Brand::poweredBy(true) ?>
    </div>
    <div class="grow"></div>
    <div class="login__clock" data-clock><?= e(date('H:i', intdiv($now, 1000))) ?></div>
    <div class="login__date" data-date><?= e(I18n::date($now)) ?></div>
    <div class="login__lines">
      <?php if (App::isWeb()): ?>
        <?= e(t('login.web_line1')) ?> · <?= e(parse_url((string) App::config('base_url'), PHP_URL_HOST) ?: ($_SERVER['HTTP_HOST'] ?? '')) ?><br><?= e(t('login.web_line2')) ?>
      <?php else: ?>
        <?= e(t('login.pc_line1')) ?><br><?= e(t('login.pc_line2')) ?>
      <?php endif ?>
    </div>
    <?= Ui::sync() ?>
  </aside>

  <main class="login__main">
    <div class="login__brandline">
      <?= Brand::tenantLogo('l') ?>
      <?= Brand::poweredBy() ?>
    </div>

    <?php if ($mode === 'pin'): ?>
    <section class="login__card" data-pin-login>
      <h1 class="login__title"><span class="only-mobile"><?= e(t('login.welcome')) ?></span><span class="only-desktop"><?= e(t('login.title')) ?></span></h1>
      <p class="login__sub"><span class="only-mobile"><?= e(t('login.pick')) ?></span><span class="only-desktop"><?= e(t('login.pick_short')) ?></span></p>
      <?php if (!$staff): ?>
        <?= Ui::banner(t('login.title'), t('login.no_users'), 'warning', 'alert') ?>
      <?php else: ?>
      <div class="staffpick" role="radiogroup" aria-label="<?= e(t('login.title')) ?>">
        <?php foreach ($staff as $i => $s): ?>
          <button type="button" class="sp<?= $i === 0 ? ' is-selected' : '' ?>" role="radio" aria-checked="<?= $i === 0 ? 'true' : 'false' ?>" data-user="<?= e($s['id']) ?>">
            <?= Ui::avatar($s['name']) ?>
            <span class="sp__col"><span class="sp__name"><?= e($s['label']) ?></span><span class="sp__role"><?= e($s['role']) ?></span></span>
          </button>
        <?php endforeach ?>
      </div>
      <div class="pin-dots" data-dots aria-hidden="true"><i></i><i></i><i></i><i></i></div>
      <div class="keypad" data-keypad>
        <?php foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9', '00', '0'] as $k): ?><button type="button" class="key" data-key="<?= $k ?>"><?= I18n::lang() === 'fa' ? I18n::faDigits($k) : $k ?></button><?php endforeach ?>
        <button type="button" class="key key--action" data-key="back" aria-label="<?= e(t('login.backspace')) ?>"><?= icon('backspace', 26) ?></button>
      </div>
      <p class="login__hint"><?= e(t('login.hint')) ?></p>
      <?php endif ?>
    </section>
    <?php else: ?>
    <section class="login__card">
      <h1 class="login__title"><?= e(t('login.title')) ?></h1>
      <p class="login__sub"><?= e(t('login.with_password')) ?></p>
      <form class="login__form" method="post" action="/login/password" data-ajax data-login-password>
        <?= csrf_field() ?>
        <input type="hidden" name="next" value="<?= e($next) ?>">
        <?= Ui::field('email', ['label' => t('login.email'), 'icon' => 'mail', 'type' => 'email', 'autocomplete' => 'username', 'attrs' => ['required' => true, 'autofocus' => true]]) ?>
        <?= Ui::field('password', ['label' => t('login.password'), 'icon' => 'lock', 'type' => 'password', 'autocomplete' => 'current-password', 'attrs' => ['required' => true]]) ?>
        <?php if ($turnstile !== ''): ?>
          <div class="cf-turnstile" data-sitekey="<?= e($turnstile) ?>" data-language="<?= e(I18n::lang()) ?>"></div>
          <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
        <?php endif ?>
        <?= Ui::btn(t('login.submit'), ['type' => 'submit', 'size' => 'l', 'block' => true, 'icon' => 'arrow-right']) ?>
      </form>
      <?php if ($hasPin): ?><a class="login__alt" href="<?= e(url('/login', ['next' => $next])) ?>"><?= e(t('login.with_pin')) ?></a><?php endif ?>
    </section>
    <?php endif ?>

    <div class="login__foot">
      <div class="segs langsw" role="tablist" aria-label="<?= e(t('ui.language')) ?>">
        <?php foreach ($langs as $code => $label): ?>
          <a class="seg<?= $code === I18n::lang() ? ' is-active' : '' ?>" href="<?= e(url('/login', ['lang' => $code, 'mode' => $mode === 'pin' ? null : $mode, 'next' => $next !== '/' ? $next : null])) ?>" hreflang="<?= $code ?>"><?= e($label) ?></a>
        <?php endforeach ?>
      </div>
      <?= Ui::sync() ?>
    </div>
  </main>
</div>
