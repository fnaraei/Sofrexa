<?php
/**
 * GuestHeader (Figma Q1/Q3/Q4): tenant logo, restaurant name, "MASA 7 · BAHÇE", language button.
 * Back variant (Q2): $back set — a back button, $title over the table line, no language button.
 * @var ?array $table @var ?string $back @var ?string $title
 */
use Sofrexa\Core\Settings;
use Sofrexa\Modules\QrOrder\QrOrders;
use Sofrexa\View\Brand;
use Sofrexa\View\Ui;

// the restaurant's name is a brand: plain capitals (BASILIC), the table line follows the language (BAHÇE, SİGARA)
$name = (string) (Settings::get('profile.short_name', '') ?: Settings::get('profile.name'));
$line = $table ? QrOrders::label($table) : t('qr.menu');
$logo = Brand::logoUrl();
?>
<?php if (!empty($back)): ?>
<header class="ghead ghead--back">
  <?= $back === '#' ? Ui::ibtn('arrow-left', t('qr.back'), ['attrs' => ['data-close-cart' => true]]) : Ui::ibtn('arrow-left', t('qr.back'), ['href' => $back]) ?>
  <div class="ghead__txt"><span class="ghead__name"><?= e($title ?? '') ?></span><span class="ghead__sub"><?= e($line) ?></span></div>
</header>
<?php else: ?>
<header class="ghead">
  <?php if ($logo): ?><img class="ghead__logo" src="<?= e($logo) ?>" alt=""><?php endif ?>
  <div class="ghead__txt"><span class="ghead__name"><?= e(mb_strtoupper($name, 'UTF-8')) ?></span><span class="ghead__sub"><?= e(upper($line)) ?></span></div>
  <?= Ui::ibtn('globe', t('qr.lang'), ['attrs' => ['data-sheet' => 'guest-lang']]) ?>
</header>
<?php endif ?>
