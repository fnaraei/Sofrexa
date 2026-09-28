<?php
/** Menü sub-navigation (Figma PR1/PR3 `menu-tabs`): Ürünler · Fiyat ve stok · Promosyonlar. @var string $active */
use Sofrexa\View\Ui;
?>
<div class="menutabs"><?= Ui::segs(['/menu' => t('menu.tab_items'), '/menu/quick' => t('menu.tab_quick'), '/menu/promotions' => t('menu.tab_promo')], $active) ?></div>
