<?php
/**
 * Business profile — Figma SE5 (77:406, desktop: logo, business details, receipt texts) and SE6 (78:710, phone).
 * Fields that SE6 does not show stay in the form (only-desktop) so saving on a phone keeps their values.
 * @var array $data
 */
use Sofrexa\Core\Settings;
use Sofrexa\View\Ui;

$logo = $data['logo'];
$v = static fn(string $k): string => (string) Settings::get($k, '');
$f = static fn(string $k, string $label, string $icon, array $o = []): string => Ui::field('s[' . $k . ']', $o + ['label' => $label, 'icon' => $icon, 'value' => $v($k)]);
?>
<section class="section only-desktop">
  <div class="section__head"><h2 class="section__title"><?= e(t('set.logo.title')) ?></h2><p class="section__sub"><?= e(t('set.logo.sub')) ?></p></div>
  <div class="logorow">
    <div class="logobox"><?php if ($logo): ?><img src="<?= e($logo['url']) ?>" alt=""><?php else: ?><?= icon('image', 32) ?><?php endif ?></div>
    <div class="col gap-8 grow">
      <div class="t-label-l"><?= $logo ? e($logo['name'] . ($logo['w'] ? ' · ' . $logo['w'] . ' × ' . $logo['h'] : '') . ' · ' . $logo['size']) : e(t('set.logo.none')) ?></div>
      <div class="t-body-s c-muted"><?= e(t('set.logo.help')) ?></div>
      <div class="row gap-8">
        <?= Ui::btn(t('set.logo.upload'), ['style' => 'secondary', 'size' => 's', 'icon' => 'upload', 'attrs' => ['data-logo-pick' => true]]) ?>
        <?php if ($logo): ?><?= Ui::btn(t('ui.remove'), ['style' => 'ghost', 'size' => 's', 'icon' => 'trash', 'attrs' => ['data-post' => '/settings/profile/logo/remove', 'data-confirm' => t('js.confirm')]]) ?><?php endif ?>
      </div>
      <div class="row gap-6 wrap"><span class="t-body-s c-muted"><?= e(t('set.logo.used_in')) ?></span>
        <?php foreach (['u_receipt', 'u_qr', 'u_online', 'u_login'] as $u): ?><?= Ui::badge(t('set.logo.' . $u)) ?><?php endforeach ?>
      </div>
    </div>
  </div>
</section>

<div class="card only-mobile" style="flex-direction:row;align-items:center;gap:12px">
  <div class="logobox logobox--s"><?php if ($logo): ?><img src="<?= e($logo['url']) ?>" alt=""><?php else: ?><?= icon('image', 24) ?><?php endif ?></div>
  <div class="col grow" style="gap:2px"><span class="t-label-l"><?= e(t('set.logo.label')) ?></span><span class="t-body-s c-muted"><?= $logo ? e($logo['ext'] . ($logo['w'] ? ' · ' . $logo['w'] . ' px' : '') . ' · ' . $logo['size']) : e(t('set.logo.none')) ?></span></div>
  <?= Ui::btn(t('set.logo.change'), ['style' => 'secondary', 'size' => 's', 'icon' => 'upload', 'attrs' => ['data-logo-pick' => true]]) ?>
</div>

<section class="section section--m-plain">
  <div class="section__head"><h2 class="section__title"><?= e(t('set.biz.title')) ?></h2><p class="section__sub"><?= e(t('set.biz.sub')) ?></p></div>
  <div class="frow">
    <?= $f('profile.name', t('set.biz.name'), 'store', ['attrs' => ['required' => true, 'maxlength' => 120]]) ?>
    <div class="only-desktop"><?= $f('profile.legal_name', t('set.biz.legal'), 'file-text') ?></div>
    <div class="only-desktop"><?= $f('profile.tax_no', t('set.biz.tax'), 'hash') ?></div>
  </div>
  <div class="frow">
    <?= $f('profile.phone', t('set.biz.phone'), 'phone', ['type' => 'tel']) ?>
    <?= $f('profile.email', t('set.biz.email'), 'mail', ['type' => 'email']) ?>
    <div class="only-desktop"><?= $f('profile.website', t('set.biz.website'), 'globe') ?></div>
  </div>
  <div class="frow">
    <?= $f('profile.address', t('set.biz.address'), 'map-pin') ?>
    <div class="only-desktop"><?= $f('profile.hours', t('set.biz.hours'), 'clock') ?></div>
  </div>
</section>

<section class="section section--m-plain">
  <div class="section__head"><h2 class="section__title"><?= e(t('set.rcpt.title')) ?></h2><p class="section__sub"><?= e(t('set.rcpt.sub')) ?></p></div>
  <div class="overline only-mobile"><?= e(t('set.rcpt.section')) ?></div>
  <div class="frow">
    <div class="only-desktop"><?= $f('receipt.header', t('set.rcpt.head'), 'note') ?></div>
    <?= $f('receipt.footer', t('set.rcpt.foot'), 'note') ?>
  </div>
  <div class="only-desktop"><?= Ui::toggleRow('s[receipt.logo]', t('set.rcpt.logo'), t('set.rcpt.logo_sub'), (bool) Settings::get('receipt.logo'), ['data-mirror' => 'rlogo']) ?></div>
  <div class="only-mobile"><?= Ui::toggleRow('m_receipt_logo', t('set.rcpt.logo'), null, (bool) Settings::get('receipt.logo'), ['data-mirror' => 'rlogo']) ?></div>
  <div class="only-desktop"><?= Ui::toggleRow('s[receipt.powered_by]', t('set.rcpt.powered'), t('set.rcpt.powered_sub'), (bool) Settings::get('receipt.powered_by')) ?></div>
</section>
