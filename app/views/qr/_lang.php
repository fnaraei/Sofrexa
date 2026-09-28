<?php
/** Language picker of the guest pages (opened by the globe button of the GuestHeader). */
use Sofrexa\Core\I18n;
use Sofrexa\Modules\QrOrder\GuestController;
use Sofrexa\View\Ui;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$query = $_GET;
?>
<div class="scrim" id="guest-lang" hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead(t('qr.lang')) ?>
    <div class="sheet__body">
      <div class="list list--flush">
        <?php foreach (GuestController::languages() as $code => $name): ?>
          <a class="lrow" href="<?= e($path . '?' . http_build_query(['lang' => $code] + $query)) ?>" lang="<?= e($code) ?>"<?= $code === 'fa' ? ' dir="rtl"' : '' ?>>
            <span class="lrow__mid"><span class="lrow__title"><?= e($name) ?></span></span>
            <?php if ($code === I18n::lang()): ?><span class="lrow__chev c-accent"><?= icon('check', 20) ?></span><?php endif ?>
          </a>
        <?php endforeach ?>
      </div>
    </div>
  </div>
</div>
